<?php

namespace App\Actions\Booking;

use App\Actions\ActivityLog\LogActivityAction;
use App\Actions\Booking\Budget\SendBudgetRequestAction;
use App\Enums\AddOnStatus;
use App\Enums\BookingLocationType;
use App\Enums\BookingPaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\PackageScheduleMode;
use App\Enums\PackageStatus;
use App\Models\Booking;
use App\Models\BookingSchedule;
use App\Models\User;
use App\Services\Photographer\AvailabilityService;
use App\Services\Photographer\BookabilityService;
use App\Services\Photographer\Booking\BookingDeadlineService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class CreateBookingAction
{
    public function __construct(
        protected BookabilityService $bookabilityService,
        protected AvailabilityService $availabilityService,
        protected LogActivityAction $activityLogger,
        protected BookingDeadlineService $deadlines,
    ) {
    }

    public function execute(User $client, array $data): Booking
    {
        if (! $client->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before booking.'],
            ]);
        }

        $photographer = User::findOrFail($data['photographer_id']);

        if (! $this->bookabilityService->isBookable($photographer)) {
            throw ValidationException::withMessages([
                'photographer_id' => ['This photographer is not currently bookable.'],
            ]);
        }

        $isCustom = (bool) ($data['is_custom_package'] ?? false);
        $customHours = null;

        if ($isCustom) {
            [$packageDurationMinutes, $bufferMinutes, $subtotal, $packageId, $packageSnapshot, $customSnapshot, $customHours] = $this->resolveCustomPackage($photographer, $data);
        } else {
            [$packageDurationMinutes, $bufferMinutes, $subtotal, $packageId, $packageSnapshot, $customSnapshot] = $this->resolveFixedPackage($photographer, $data);
        }

        [$addOnsSnapshot, $addOnsTotal] = $this->resolveAddOns($photographer, $data['add_on_ids'] ?? []);
        $subtotal += $addOnsTotal;

        $platformFee = (float) config('platform.fee', 30.00);
        $totalPrice = $subtotal + $platformFee;

        // Optional budget offer sent with the request (budget_amount / budget_note in
        // CreateBookingRequest). Checked here, before anything is saved, so a bad
        // offer can never leave a half-created booking behind.
        $budgetAmount = isset($data['budget_amount']) && $data['budget_amount'] !== '' ? (float) $data['budget_amount'] : null;
        if ($budgetAmount !== null && ($budgetAmount <= 0 || $budgetAmount >= $subtotal)) {
            throw ValidationException::withMessages([
                'budget_amount' => ['Your budget must be more than 0 and lower than the listed price. Leave it empty to book at the listed price.'],
            ]);
        }

        // One booking = one package/service. Extra photography sessions are
        // only accepted when the photographer's package explicitly allows
        // them; anything else (a different service, or a custom package)
        // has to be booked separately.
        $extraSessions = count($data['additional_schedules'] ?? []);
        if ($extraSessions > 0) {
            $allowsMultiple = $isCustom
                ? (bool) ($customSnapshot['allows_multiple_sessions'] ?? false)
                : (bool) ($packageSnapshot['allows_multiple_sessions'] ?? false);
            if (! $allowsMultiple) {
                throw ValidationException::withMessages([
                    'additional_schedules' => ['The selected package covers a single photography session. Book separate services as separate bookings.'],
                ]);
            }
            $maxSessions = $isCustom
                ? ($customSnapshot['max_sessions'] ?? null)
                : ($packageSnapshot['max_sessions'] ?? null);
            if ($maxSessions !== null && $extraSessions + 1 > $maxSessions) {
                throw ValidationException::withMessages([
                    'additional_schedules' => ["This package allows up to {$maxSessions} sessions per booking."],
                ]);
            }
        }

        // Schedule 1's own duration: an explicit per-schedule override if
        // the client gave one, else the package's own duration_minutes
        // (itself possibly null — a fixed package with no set length).
        // Custom packages always ignore this override and use their own
        // resolved duration; letting a client override a custom booking's
        // duration would desync it from what they were actually priced
        // and validated for.
        // key present (even as null) = the client explicitly chose a value or
        // "TBD"; key absent = fall back to the package's own duration.
        $primaryDuration = $isCustom
            ? $packageDurationMinutes
            : (array_key_exists('duration_minutes', $data) ? $data['duration_minutes'] : $packageDurationMinutes);
        $primaryNeeded = $primaryDuration !== null ? $primaryDuration + $bufferMinutes : null;
        // The buffer exists only to keep the photographer's calendar clear
        // between bookings — it must never appear as part of the coverage
        // the client actually booked and sees (BUG-03).
        $endTime = $primaryDuration !== null
            ? Carbon::parse($data['start_time'])->addMinutes($primaryDuration)->format('H:i')
            : null;

        // Timed session: the whole window (duration + buffer) must be free.
        // Open-ended session (no end time): the START must still be inside the
        // photographer's hours and not blocked or already paid for.
        $this->assertSlotIsAvailable(
            $photographer, $data['event_date'], $data['start_time'],
            $primaryNeeded ?? $this->startPointMinutes($photographer)
        );

        // Schedule 2+ (see the additional_schedules request field): each
        // entry may carry its own duration_minutes, entirely independent of
        // Schedule 1 or the package — a Prenup and a Wedding under the same
        // booking aren't required to be the same length, and either can be
        // left null. A null duration here means "TBD, confirm with
        // photographer" — we never guess a length for it.
        $additionalSchedules = array_map(function (array $schedule) use ($bufferMinutes, $packageDurationMinutes, $isCustom, $customSnapshot) {
            // Custom packages always use their own resolved duration. Fixed
            // packages honor an explicit value/null from the client, else
            // inherit the package duration (which may itself be null).
            $duration = $isCustom
                ? ((($customSnapshot['pricing_model'] ?? 'hourly') === 'hourly') ? ($schedule['duration_minutes'] ?? $packageDurationMinutes) : $packageDurationMinutes)
                : (array_key_exists('duration_minutes', $schedule) ? $schedule['duration_minutes'] : $packageDurationMinutes);
            $needed = $duration !== null ? $duration + $bufferMinutes : null;

            return $schedule + [
                'duration_minutes' => $duration,
                // Same fix as Schedule 1: end_time is coverage only, no buffer.
                'end_time' => $duration !== null
                    ? Carbon::parse($schedule['start_time'])->addMinutes($duration)->format('H:i')
                    : null,
            ];
        }, $data['additional_schedules'] ?? []);

        foreach ($additionalSchedules as $i => $schedule) {
            $needed = $schedule['end_time'] !== null
                ? $schedule['duration_minutes'] + $bufferMinutes
                : $this->startPointMinutes($photographer);

            $this->assertSlotIsAvailable(
                $photographer, $schedule['event_date'], $schedule['start_time'], $needed,
                "additional_schedules.$i.start_time", 'Session '.($i + 2)
            );
        }

        // Guard against this SAME submission double-booking itself — e.g.
        // Schedule 1 and an additional schedule (or two additional
        // schedules) landing on the same date with overlapping times —
        // before checking any of them against existing bookings below. A
        // schedule with no confirmed duration can't be range-checked, so we
        // only catch the unambiguous case of two schedules starting at the
        // exact same moment; a real overlap involving a TBD schedule has to
        // be caught by the photographer on manual review instead.
        $allScheduleWindows = array_merge(
            [['label' => 'Schedule 1', 'event_date' => $data['event_date'], 'start_time' => $data['start_time'], 'end_time' => $endTime]],
            array_map(fn ($s) => ['label' => $s['label'], 'event_date' => $s['event_date'], 'start_time' => $s['start_time'], 'end_time' => $s['end_time']], $additionalSchedules)
        );
        foreach ($allScheduleWindows as $i => $a) {
            foreach ($allScheduleWindows as $j => $b) {
                if ($j <= $i || $a['event_date'] !== $b['event_date']) {
                    continue;
                }
                if ($a['end_time'] === null || $b['end_time'] === null) {
                    if ($a['start_time'] === $b['start_time']) {
                        throw ValidationException::withMessages([
                            'additional_schedules' => ["\"{$a['label']}\" and \"{$b['label']}\" are both set to start at the same time on {$a['event_date']}."],
                        ]);
                    }
                    continue;
                }
                if ($a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                    throw ValidationException::withMessages([
                        'additional_schedules' => ["\"{$a['label']}\" and \"{$b['label']}\" overlap on {$a['event_date']}."],
                    ]);
                }
            }
        }

        // Business rule (revised): a slot is only ever truly "taken" once a
        // booking for it has a CONFIRMED payment (partially or fully paid).
        // Multiple clients are allowed to request — and even be accepted
        // for — the same overlapping slot at the same time; this lets a
        // photographer keep a backup request alive in case the first client
        // never pays or cancels. Whichever booking gets its payment
        // confirmed first wins the slot; see ReleaseConflictingBookingsAction,
        // which auto-declines the other unpaid requests for that slot at
        // that point. We only need to guard against *paid* conflicts here.
        //
        // The check-then-insert below is wrapped in a transaction with a
        // row lock on the photographer so two clients paying/requesting at
        // the exact same instant can't both slip past assertNoConflict()
        // before either row commits (the race condition this replaced).
        $booking = DB::transaction(function () use (
            $client, $photographer, $packageId, $isCustom, $packageSnapshot,
            $customSnapshot, $customHours, $addOnsSnapshot, $data, $subtotal,
            $platformFee, $totalPrice, $endTime, $primaryDuration, $additionalSchedules, $budgetAmount
        ) {
            $photographer = User::where('id', $photographer->id)->lockForUpdate()->firstOrFail();

            if ($endTime !== null) {
                $this->assertNoConflict($photographer, $data['event_date'], $data['start_time'], $endTime);
            }

            foreach ($additionalSchedules as $schedule) {
                if ($schedule['end_time'] !== null) {
                    $this->assertNoConflict($photographer, $schedule['event_date'], $schedule['start_time'], $schedule['end_time']);
                }
            }

            $booking = Booking::create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'package_id' => $packageId,
                'is_custom_package' => $isCustom,
                'package_snapshot' => $packageSnapshot,
                'custom_package_snapshot' => $customSnapshot,
                'custom_hours' => $customHours,
                'add_ons_snapshot' => $addOnsSnapshot,
                'event_type' => $data['event_type'],
                'custom_event_type' => $data['custom_event_type'] ?? null,
                'event_date' => $data['event_date'],
                'start_time' => $data['start_time'],
                'end_time' => $endTime,
                'duration_minutes' => $primaryDuration,
                'location_type' => $data['location_type'],
                'province_id' => $data['province_id'] ?? null,
                'city_municipality_id' => $data['city_municipality_id'] ?? null,
                'barangay_id' => $data['barangay_id'] ?? null,
                'event_address' => $data['event_address'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'subtotal' => $subtotal,
                'platform_fee' => $platformFee,
                'total_price' => $totalPrice,
                'status' => BookingStatus::Pending,
                // Photographer has 48h (never past the event start) to
                // approve/reject, else ExpireStaleBookingHoldsAction expires it.
                'hold_expires_at' => $this->deadlines->decisionDeadlineForRequest(
                    $data['event_date'], $data['start_time'], $additionalSchedules
                ),
            ]);

            foreach ($additionalSchedules as $index => $schedule) {
                $booking->schedules()->create([
                    'label' => $schedule['label'],
                    'event_date' => $schedule['event_date'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'duration_minutes' => $schedule['duration_minutes'],
                    'location_type' => $schedule['location_type'] ?? null,
                    'province_id' => $schedule['province_id'] ?? null,
                    'city_municipality_id' => $schedule['city_municipality_id'] ?? null,
                    'barangay_id' => $schedule['barangay_id'] ?? null,
                    'event_address' => $schedule['event_address'] ?? null,
                    'sort_order' => $index + 1,
                ]);
            }

            // The client's offer (and message) is saved with the booking, in the same transaction.
            if ($budgetAmount !== null) {
                app(SendBudgetRequestAction::class)->createForNewBooking(
                    $booking, $budgetAmount, $data['budget_note'] ?? null
                );
            }

            return $booking;
        });

        $photographer->notify(new \App\Notifications\Booking\NewBookingRequestNotification($booking));
        $client->notify(new \App\Notifications\Booking\BookingRequestSubmittedNotification($booking));

        $this->activityLogger->execute(
            causer: $client,
            subject: $booking,
            action: 'booking.created',
            description: "Created booking #{$booking->id} with {$photographer->name}",
        );

        return $booking;
    }

    protected function resolveFixedPackage(User $photographer, array $data): array
    {
        $package = $photographer->packages()->where('status', PackageStatus::Published)->find($data['package_id'] ?? null);

        if (! $package) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not available for this photographer.'],
            ]);
        }

        $isOpen = $package->schedule_mode === PackageScheduleMode::Open;

        $snapshot = [
            'name' => $package->name,
            'description' => $package->description,
            'price' => (string) $this->fixedPackagePrice($package, $data),
            'rate_type' => ($this->isOnLocation($data) && $package->outdoor_price !== null) ? 'outdoor' : 'studio',
            // Package information / pricing length. For an open-ended package
            // this is NOT used to reserve time.
            'duration_minutes' => $package->duration_minutes,
            'schedule_mode' => $package->schedule_mode?->value ?? 'timed',
            'buffer_minutes' => $package->buffer_minutes,
            'allows_multiple_sessions' => (bool) $package->allows_multiple_sessions,
            'max_sessions' => $package->max_sessions,
        ];

        // Scheduling: an open-ended package reserves no window (no end time).
        return [
            $isOpen ? null : $package->duration_minutes,
            $isOpen ? 0 : (int) $package->buffer_minutes,
            $this->fixedPackagePrice($package, $data), $package->id, $snapshot, null,
        ];
    }

    /** Anything other than "Studio" is priced at the photographer's outdoor / on-location rate. */
    protected function isOnLocation(array $data): bool
    {
        return ($data['location_type'] ?? BookingLocationType::Studio->value) !== BookingLocationType::Studio->value;
    }

    /** Fixed-package price for this booking's location (outdoor price if set, else the normal price). */
    protected function fixedPackagePrice(\App\Models\Package $package, array $data): float
    {
        return ($this->isOnLocation($data) && $package->outdoor_price !== null)
            ? (float) $package->outdoor_price
            : (float) $package->price;
    }

    protected function resolveCustomPackage(User $photographer, array $data): array
    {
        $config = $photographer->customPackageConfig;

        if (! $config || ! $config->enabled) {
            throw ValidationException::withMessages([
                'is_custom_package' => ['Custom packages are not enabled for this photographer.'],
            ]);
        }

        $componentIds = $data['custom_component_ids'] ?? [];
        $components = $photographer->customPackageComponents()
            ->where('status', AddOnStatus::Active)
            ->whereIn('id', $componentIds)
            ->get();

        if (count($componentIds) !== $components->count()) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['One or more selected custom options are invalid.'],
            ]);
        }

        $bufferMinutes = (int) ($config->buffer_minutes ?? 0);

        $pricingModel = $config->pricing_model ?: ($config->hourly_rate !== null ? 'hourly' : 'fixed');

        // Per-day / per-person pricing. PRICE and SCHEDULE are independent:
        // price comes from the rate x quantity below; the scheduled duration
        // is only the photographer's agreed coverage hours per session (null =
        // "to be confirmed" — never invented from price).
        if (in_array($pricingModel, ['per_day', 'per_person'], true)) {
            $unitRate = (float) ($config->unit_rate ?? 0);
            if ($unitRate <= 0) {
                throw ValidationException::withMessages([
                    'is_custom_package' => ["This photographer has not finished setting up their custom pricing."],
                ]);
            }

            $extraSchedules = array_values($data['additional_schedules'] ?? []);
            if (count($extraSchedules) > 0) {
                if (! $config->allows_multiple_sessions) {
                    throw ValidationException::withMessages([
                        'additional_schedules' => ["This photographer's custom package covers a single photography session. Book separate services as separate bookings."],
                    ]);
                }
                if ($config->max_sessions !== null && count($extraSchedules) + 1 > $config->max_sessions) {
                    throw ValidationException::withMessages([
                        'additional_schedules' => ["This custom package allows up to {$config->max_sessions} sessions per booking."],
                    ]);
                }
            }

            if ($pricingModel === 'per_day') {
                // Billable days = distinct dates, so two sessions on one date are one day.
                $quantity = collect([$data['event_date'] ?? null])
                    ->concat(collect($extraSchedules)->pluck('event_date'))
                    ->filter()->unique()->count();
            } else {
                $quantity = (int) ($data['custom_people'] ?? 0);
                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        'custom_people' => ['Enter how many people will be covered.'],
                    ]);
                }
                if ($config->max_people !== null && $quantity > $config->max_people) {
                    throw ValidationException::withMessages([
                        'custom_people' => ["This photographer covers up to {$config->max_people} people per booking."],
                    ]);
                }
            }

            $flatComponents = $components->filter(fn ($c) => $c->duration_minutes === null);
            $subtotal = $unitRate * $quantity + (float) $flatComponents->sum('price_addition');
            $coverageMinutes = $config->coverage_hours ? ((int) $config->coverage_hours) * 60 : null;

            $snapshot = [
                'pricing_model' => $pricingModel,
                'unit_rate' => (string) $config->unit_rate,
                'quantity' => $quantity,
                'coverage_hours' => $config->coverage_hours,
                'duration_minutes' => $coverageMinutes,
                'buffer_minutes' => $bufferMinutes,
                'allows_multiple_sessions' => (bool) $config->allows_multiple_sessions,
                'max_sessions' => $config->max_sessions,
                'components' => $flatComponents->map(fn ($c) => [
                    'label' => $c->label,
                    'type' => $c->type->value,
                    'price_addition' => (string) $c->price_addition,
                ])->values()->toArray(),
            ];

            return [$coverageMinutes, $bufferMinutes, $subtotal, null, null, $snapshot, null];
        }

        // Sliding-hours pricing mode: the photographer set an hourly_rate
        // (and optionally min/max hours) on their custom package config,
        // and the client dragged the frontend slider to pick a coverage
        // length instead of choosing a discrete duration option. Coverage
        // duration and price both scale directly off the client's chosen
        // hours here, so there's no separate duration-component requirement
        // to check — every other (non-duration) component still applies as
        // a flat add-on on top.
        if ($config->hourly_rate !== null && array_key_exists('custom_hours', $data) && $data['custom_hours'] !== null) {
            $baseHours = $config->base_hours;
            // The slider can't go below the hours the base fee already covers.
            $minHours = max($config->min_hours ?? 1, $baseHours ?? 1);
            $maxHours = $config->max_hours ?? 12;
            $hours = (int) $data['custom_hours'];

            if ($hours < $minHours || $hours > $maxHours) {
                throw ValidationException::withMessages([
                    'custom_hours' => ["Coverage hours must be between {$minHours} and {$maxHours} for this photographer."],
                ]);
            }

            $flatComponents = $components->filter(fn ($c) => $c->duration_minutes === null);

            // Extra sessions (Schedule 2+) are only accepted when the photographer
            // explicitly turned multi-session on AND chose how the base fee applies.
            $extraSchedules = array_values($data['additional_schedules'] ?? []);
            $baseFeeMode = $config->base_fee_mode; // 'once' | 'per_schedule' | null
            $allowsMultiple = (bool) $config->allows_multiple_sessions;

            if (count($extraSchedules) > 0) {
                if (! $allowsMultiple) {
                    throw ValidationException::withMessages([
                        'additional_schedules' => ["This photographer's custom package covers a single photography session. Book separate services as separate bookings."],
                    ]);
                }
                if ($config->max_sessions !== null && count($extraSchedules) + 1 > $config->max_sessions) {
                    throw ValidationException::withMessages([
                        'additional_schedules' => ["This custom package allows up to {$config->max_sessions} sessions per booking."],
                    ]);
                }
            }

            // Each session is priced from its OWN hours — never as one continuous span.
            $sessionHours = [$hours];
            foreach ($extraSchedules as $i => $s) {
                $minutes = $s['duration_minutes'] ?? null;
                if ($minutes === null || $minutes % 60 !== 0) {
                    throw ValidationException::withMessages([
                        "additional_schedules.$i.duration_minutes" => ['Each session needs a whole number of coverage hours.'],
                    ]);
                }
                $h = intdiv($minutes, 60);
                $extraMin = $baseFeeMode === 'per_schedule' ? $minHours : max(1, (int) ($config->min_hours ?? 1));
                if ($h < $extraMin || $h > $maxHours) {
                    throw ValidationException::withMessages([
                        "additional_schedules.$i.duration_minutes" => ["Session hours must be between {$extraMin} and {$maxHours}."],
                    ]);
                }
                $sessionHours[] = $h;
            }

            $rate = ($this->isOnLocation($data) && $config->outdoor_hourly_rate !== null)
                ? (float) $config->outdoor_hourly_rate
                : (float) $config->hourly_rate;
            $baseFee = (float) ($config->base_fee ?? 0);
            $sessionCharges = [];

            if ($baseHours === null) {
                // Older config without base_hours: hours x rate for every session.
                foreach ($sessionHours as $h) {
                    $sessionCharges[] = $rate * $h;
                }
            } elseif ($baseFeeMode === 'once') {
                // Base fee (covering base_hours) is charged once across the whole booking.
                $remaining = $baseHours;
                foreach ($sessionHours as $i => $h) {
                    $billable = max(0, $h - $remaining);
                    $remaining = max(0, $remaining - $h);
                    $sessionCharges[] = ($i === 0 ? $baseFee : 0.0) + $billable * $rate;
                }
            } else {
                // 'per_schedule' (and the single-session case): each session gets its own base fee.
                foreach ($sessionHours as $h) {
                    $sessionCharges[] = $baseFee + max(0, $h - $baseHours) * $rate;
                }
            }

            $subtotal = array_sum($sessionCharges) + (float) $flatComponents->sum('price_addition');

            $snapshot = [
                'hourly_rate' => (string) $rate,
                'rate_type' => ($this->isOnLocation($data) && $config->outdoor_hourly_rate !== null) ? 'outdoor' : 'studio',
                'base_fee' => $baseHours !== null ? (string) ($config->base_fee ?? 0) : null,
                'base_hours' => $baseHours,
                'hours' => $hours,
                'duration_minutes' => $hours * 60,
                'buffer_minutes' => $bufferMinutes,
                'allows_multiple_sessions' => $allowsMultiple,
                'max_sessions' => $config->max_sessions,
                'base_fee_mode' => $baseFeeMode,
                'pricing_model' => 'hourly',
                'total_hours' => array_sum($sessionHours),
                'sessions' => array_map(fn ($h, $c) => ['hours' => $h, 'charge' => (string) round($c, 2)], $sessionHours, $sessionCharges),
                'components' => $flatComponents->map(fn ($c) => [
                    'label' => $c->label,
                    'type' => $c->type->value,
                    'price_addition' => (string) $c->price_addition,
                ])->values()->toArray(),
            ];

            return [$hours * 60, $bufferMinutes, $subtotal, null, null, $snapshot, $hours];
        }

        $subtotal = (float) ($config->base_fee ?? 0) + (float) $components->sum('price_addition');

        // The client must have selected EXACTLY ONE component carrying a real
        // coverage duration (see the 2026_09_06_211623_add_duration_to_custom_
        // package_table migration). We never guess a duration from the
        // photographer's fixed packages — a wrong guess would reserve less
        // time than the client actually booked and let a second client
        // double-book the remainder (this replaced an earlier fallback that
        // did exactly that; do not reintroduce it).
        //
        // Both zero and more-than-one duration components are rejected here:
        // zero means the calendar can't safely reserve any time at all, and
        // more than one is ambiguous (a photographer accidentally marking two
        // components as durations, or a tampered/duplicated request) — silently
        // taking the first one would let the wrong duration reserve the slot.
        $durationComponents = $components->filter(fn ($c) => $c->duration_minutes !== null);

        if ($durationComponents->count() === 0) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['Please select a photography coverage duration for your custom package.'],
            ]);
        }

        if ($durationComponents->count() > 1) {
            throw ValidationException::withMessages([
                'custom_component_ids' => ['Please select only one photography coverage duration option.'],
            ]);
        }

        $durationComponent = $durationComponents->first();

        $snapshot = [
            'base_fee' => (string) ($config->base_fee ?? 0),
            'duration_minutes' => $durationComponent->duration_minutes,
            'pricing_model' => 'fixed',
            'allows_multiple_sessions' => (bool) $config->allows_multiple_sessions,
            'max_sessions' => $config->max_sessions,
            'buffer_minutes' => $bufferMinutes,
            'components' => $components->map(fn ($c) => [
                'label' => $c->label,
                'type' => $c->type->value,
                'price_addition' => (string) $c->price_addition,
            ])->values()->toArray(),
        ];

        return [$durationComponent->duration_minutes, $bufferMinutes, $subtotal, null, null, $snapshot, null];
    }

    protected function resolveAddOns(User $photographer, array $addOnIds): array
    {
        if (empty($addOnIds)) {
            return [[], 0.0];
        }

        $addOns = $photographer->addOns()->where('status', AddOnStatus::Active)->whereIn('id', $addOnIds)->get();

        if (count($addOnIds) !== $addOns->count()) {
            throw ValidationException::withMessages([
                'add_on_ids' => ['One or more selected add-ons are invalid or unavailable.'],
            ]);
        }

        $snapshot = $addOns->map(fn ($a) => ['name' => $a->name, 'price' => (string) $a->price])->values()->toArray();

        return [$snapshot, (float) $addOns->sum('price')];
    }

    /** One slot's length — used to check that an open-ended session's START is free. */
    protected function startPointMinutes(User $photographer): int
    {
        return (int) ($photographer->slot_interval_minutes ?: 60);
    }

    protected function assertSlotIsAvailable(User $photographer, string $date, string $startTime, int $neededMinutes, string $field = 'start_time', ?string $label = null): void
    {
        $slots = $this->availabilityService->getAvailableStartTimes($photographer, $date, $neededMinutes);

        if (! in_array($startTime, $slots, true)) {
            throw ValidationException::withMessages([
                $field => [($label ? "{$label}: " : '').'This date and time is not available for booking.'],
            ]);
        }
    }

    /**
     * Only blocks against bookings whose payment is actually confirmed
     * (partially or fully paid). Pending requests and accepted-but-unpaid
     * bookings do NOT block a new request for the same slot — if the other
     * client cancels or never pays, this slot was never truly unavailable.
     * Call this from inside a transaction with the photographer row locked
     * (see CreateBookingAction::execute and the payment-confirmation
     * actions) so the check is atomic with whatever write follows it.
     *
     * Checks BOTH a booking's own primary date/time columns AND every
     * other booking's additional BookingSchedule rows for the same date —
     * a photographer with a "Wedding, Oct 20" schedule entry on booking A
     * must correctly block a new request for Oct 20 on booking B, even
     * though that date never touches booking A's own event_date column.
     */
    protected function assertNoConflict(User $photographer, string $date, string $start, string $end): void
    {
        $conflict = $photographer->bookingsAsPhotographer()
            ->where('event_date', $date)
            ->where('status', BookingStatus::Confirmed)
            ->whereIn('payment_status', [
                BookingPaymentStatus::PartiallyPaid,
                BookingPaymentStatus::FullyPaid,
            ])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();

        if (! $conflict) {
            $conflict = BookingSchedule::query()
                ->where('event_date', $date)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start)
                ->whereHas('booking', fn ($q) => $q
                    ->where('photographer_id', $photographer->id)
                    ->where('status', BookingStatus::Confirmed)
                    ->whereIn('payment_status', [
                        BookingPaymentStatus::PartiallyPaid,
                        BookingPaymentStatus::FullyPaid,
                    ])
                )
                ->exists();
        }

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_time' => ['This time slot is already booked and paid for.'],
            ]);
        }
    }
}