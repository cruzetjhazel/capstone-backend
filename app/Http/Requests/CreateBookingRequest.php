<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\BookingLocationType;
use Illuminate\Validation\Rule;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photographer_id' => ['required', 'integer', 'exists:users,id'],

            'is_custom_package' => ['sometimes', 'boolean'],
            'package_id' => ['required_if:is_custom_package,false', 'nullable', 'integer'],
            'custom_component_ids' => ['sometimes', 'array'],
            'custom_component_ids.*' => ['integer'],
            // Present when the client used the sliding-hours picker instead
            // of a discrete duration option — see CreateBookingAction::
            // resolveCustomPackage(), which requires the photographer to
            // have hourly pricing enabled for this to be accepted.
            'custom_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
            // Head-count for per-person custom pricing.
            'custom_people' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            // Head-count for per-person custom pricing.
            'custom_people' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],

            'add_on_ids' => ['sometimes', 'array'],
            'add_on_ids.*' => ['integer'],

            'event_type' => ['required', Rule::in([
                'wedding', 'birthday', 'prenup', 'graduation', 'portrait',
                'corporate_event', 'product_photography', 'family_event', 'other',
            ])],
            'custom_event_type' => ['required_if:event_type,other', 'nullable', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            // Optional override for Schedule 1's coverage length. Omitted →
            // falls back to the package's own duration_minutes (may itself
            // be null, meaning "TBD — confirm with photographer"). Ignored
            // entirely for custom packages, whose duration is always
            // resolved from the selected component/hours, never the client.
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'location_type' => ['required', Rule::in(['studio', 'client_location', 'outdoor_location', 'outside_bicol', 'other'])],
            'province_id' => ['required_if:location_type,client_location,outdoor_location,other', 'nullable', 'integer', 'exists:location_provinces,id'],
            'city_municipality_id' => ['required_if:location_type,client_location,outdoor_location,other', 'nullable', 'integer', 'exists:location_cities_municipalities,id'],
            'barangay_id' => ['required_if:location_type,client_location,outdoor_location,other', 'nullable', 'integer', 'exists:location_barangays,id'],
            'event_address' => ['required_unless:location_type,studio', 'nullable', 'string', 'max:500'],
            'guest_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:2000'],
'budget_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999.99'],
'budget_note' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Schedule 2+ — separate, non-contiguous photography sessions
            // for this same booking (e.g. Prenup on one date, Wedding on
            // another). Each entry can carry its own duration_minutes,
            // independent of Schedule 1 or the package — a Prenup and a
            // Wedding under the same booking are not required to be the
            // same length, and either can be left null ("TBD, confirm with
            // photographer"). An end_time submitted by the client is never
            // trusted — CreateBookingAction always derives it itself from
            // start_time + duration_minutes so a stale/tampered value can't
            // reserve the wrong window.
            'additional_schedules' => ['sometimes', 'array'],
            'additional_schedules.*.label' => ['required', 'string', 'max:255'],
            'additional_schedules.*.event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'additional_schedules.*.start_time' => ['required', 'date_format:H:i'],
            'additional_schedules.*.duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Optional: a session held somewhere other than the booking's main
            // location. Omit all of these to mean "same location".
            'additional_schedules.*.location_type' => ['sometimes', 'nullable', Rule::in(array_map(fn ($c) => $c->value, BookingLocationType::cases()))],
            'additional_schedules.*.province_id' => ['sometimes', 'nullable', 'integer', 'exists:location_provinces,id'],
            'additional_schedules.*.city_municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:location_cities_municipalities,id'],
            'additional_schedules.*.barangay_id' => ['sometimes', 'nullable', 'integer', 'exists:location_barangays,id'],
            'additional_schedules.*.event_address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $provinceId = $this->input('province_id');
            $cityMunicipalityId = $this->input('city_municipality_id');
            $barangayId = $this->input('barangay_id');

            if ($cityMunicipalityId && $provinceId) {
                $belongs = \App\Models\LocationCityMunicipality::where('id', $cityMunicipalityId)
                    ->where('province_id', $provinceId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('city_municipality_id', 'This city/municipality does not belong to the selected province.');
                }
            }

            if ($barangayId && $cityMunicipalityId) {
                $belongs = \App\Models\LocationBarangay::where('id', $barangayId)
                    ->where('city_municipality_id', $cityMunicipalityId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('barangay_id', 'This barangay does not belong to the selected city/municipality.');
                }
            }

            // Same province -> city -> barangay consistency check, per session.
            foreach ((array) $this->input('additional_schedules', []) as $i => $s) {
                $p = $s['province_id'] ?? null;
                $c = $s['city_municipality_id'] ?? null;
                $b = $s['barangay_id'] ?? null;

                if ($c && $p && ! \App\Models\LocationCityMunicipality::where('id', $c)->where('province_id', $p)->exists()) {
                    $validator->errors()->add("additional_schedules.$i.city_municipality_id", 'This city/municipality does not belong to the selected province.');
                }
                if ($b && $c && ! \App\Models\LocationBarangay::where('id', $b)->where('city_municipality_id', $c)->exists()) {
                    $validator->errors()->add("additional_schedules.$i.barangay_id", 'This barangay does not belong to the selected city/municipality.');
                }
            }

            $this->validateCoverage($validator);
        });
    }

    // PSGC codes (stable across environments). Keep in sync with NEARBY_MUNICIPALITIES in src/data/coverage.ts.
    private const SORSOGON_PROVINCE = '0506200000';
    private const BULAN = '0506203000';
    private const NEARBY = [
        '0506203000', // Bulan
        '0506209000', // Irosin
        '0506210000', // Juban
        '0506205000', // Casiguran
        '0506202000', // Barcelona
        '0506212000', // Matnog
    ];

    /** The photographer must actually cover the main location and any session-specific location. */
    private function validateCoverage($validator): void
    {
        $area = \App\Models\PhotographerApplication::where('user_id', $this->input('photographer_id'))->value('coverage_area');

        // Only the three Sorsogon-limited tiers restrict anything. No coverage on file,
        // travels outside Sorsogon, or an unrecognised value: nothing to restrict.
        if (! in_array($area, ['bulan_only', 'bulan_nearby', 'anywhere_sorsogon', 'travel_outside_sorsogon'], true)) {
            return; // travel_outside_bicol, no coverage on file, or an unrecognised value: nothing to restrict
        }

        $this->checkCoverage($validator, $area, '', $this->input('location_type'),
            $this->input('province_id'), $this->input('city_municipality_id'));

        foreach ((array) $this->input('additional_schedules', []) as $i => $s) {
            if (empty($s['location_type'])) {
                continue; // same location as the main booking, already checked above
            }
            $this->checkCoverage($validator, $area, "additional_schedules.$i.", $s['location_type'],
                $s['province_id'] ?? null, $s['city_municipality_id'] ?? null);
        }
    }

    private function checkCoverage($validator, string $area, string $prefix, ?string $type, $provinceId, $cityId): void
    {
        if (! $type || $type === 'studio') {
            return;
        }

        if ($type === 'outside_bicol') {
            $validator->errors()->add($prefix.'location_type', 'This photographer does not cover locations outside Bicol.');
            return;
        }

        if (! $provinceId || ! $cityId) {
            return; // the required_if rules already report these
        }

        $provinceCode = \Illuminate\Support\Facades\DB::table('location_provinces')->where('id', $provinceId)->value('psgc_code');
        $cityCode = \Illuminate\Support\Facades\DB::table('location_cities_municipalities')->where('id', $cityId)->value('psgc_code');

        if ($area === 'travel_outside_sorsogon') {
            return; // any Bicol province/city is fine; only "outside_bicol" was rejected above
        }

        if ($provinceCode !== self::SORSOGON_PROVINCE) {
            $validator->errors()->add($prefix.'province_id', 'This photographer does not cover the selected province.');
            return;
        }

        if ($area === 'bulan_only' && $cityCode !== self::BULAN) {
            $validator->errors()->add($prefix.'city_municipality_id', 'This photographer only covers Bulan.');
        } elseif ($area === 'bulan_nearby' && ! in_array($cityCode, self::NEARBY, true)) {
            $validator->errors()->add($prefix.'city_municipality_id', 'This photographer only covers Bulan and nearby municipalities.');
        }
    }
}