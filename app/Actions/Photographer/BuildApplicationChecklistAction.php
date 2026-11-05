<?php

namespace App\Actions\Photographer;

use App\Enums\PhotographerType;
use App\Enums\PortfolioImageStatus;
use App\Models\ApplicationItemReview;
use App\Models\PhotographerApplication;
use App\Models\PhotographerPortfolioImage;
use App\Models\PhotographerProfile;

class BuildApplicationChecklistAction
{
    public const ADDITIONAL_MIN = 2;
    public const ADDITIONAL_MAX = 5;
    public const PORTFOLIO_MIN = 6;
    public const PORTFOLIO_MAX = 12;
    public const BIO_MIN_LENGTH = 20;

    // Mirrors the options offered on the registration form (see UpdatePhotographerApplicationRequest).
    private const COVERAGE_LABELS = [
        'bulan_only' => 'Bulan Only',
        'bulan_nearby' => 'Bulan and Nearby Municipalities',
        'anywhere_sorsogon' => 'Anywhere in Sorsogon',
        'travel_outside_sorsogon' => 'Travel Outside Sorsogon',
        'travel_outside_bicol' => 'Travel Outside Bicol',
    ];

    // The portfolio is verified as ONE unit (not image by image). Its single review row uses
    // item_type 'portfolio_image' with this fixed key.
    public const PORTFOLIO_REF = 'all';

    public static function additionalRef(string $path): string
    {
        return substr(sha1($path), 0, 16);
    }

    /**
     * Fingerprint of the active portfolio images. It is stored in the review row's file_path,
     * so if the applicant adds, removes or replaces any image, the old verification no longer
     * matches and the portfolio goes back to "pending".
     */
    public static function portfolioFingerprint(iterable $images): string
    {
        return sha1(collect($images)->sortBy('id')->map(fn ($i) => $i->id.':'.$i->path)->implode('|'));
    }

    public function execute(PhotographerApplication $application): array
    {
        $application->loadMissing(['user.photographerProfile', 'itemReviews.reviewer']);

        $base = "/admin/photographer-applications/{$application->id}";
        $reviews = $application->itemReviews->keyBy(fn (ApplicationItemReview $r) => $r->item_type.'|'.$r->item_key);
        $isStudio = $this->isStudio($application);

        $items = [];

        $single = [
            'government_id' => ['Government ID', $application->government_id_path, 'government-id'],
            'selfie_with_id' => ['Selfie holding ID', $application->selfie_with_id_path, 'selfie-with-id'],
            'business_permit' => ['Business permit / DTI registration', $application->business_permit_path, 'business-permit'],
        ];
        foreach ($single as $type => [$label, $path, $segment]) {
            if ($path) {
                $items[] = $this->item($reviews, $type, $type, $label, $path, "{$base}/documents/{$segment}");
            }
        }

        foreach (array_values($application->additional_document_paths ?? []) as $i => $path) {
            $ref = self::additionalRef($path);
            $items[] = $this->item($reviews, 'additional_document', $ref, 'Additional document '.($i + 1), $path, "{$base}/additional-documents/{$ref}");
        }

        $images = PhotographerPortfolioImage::query()
            ->where('user_id', $application->user_id)
            ->where('status', PortfolioImageStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        // ONE review for the whole portfolio. The images are still listed (so they can be viewed,
        // zoomed and downloaded) and all carry the portfolio's single status.
        $portfolioReview = $images->isEmpty()
            ? null
            : $this->item($reviews, 'portfolio_image', self::PORTFOLIO_REF, 'Photography portfolio', self::portfolioFingerprint($images), "{$base}/portfolio");
        $portfolioStatus = $portfolioReview['status'] ?? 'pending';

        $portfolioItems = [];
        foreach ($images as $i => $image) {
            $portfolioItems[] = [
                'type' => 'portfolio_image',
                'ref' => (string) $image->id,
                'label' => 'Portfolio image '.($i + 1),
                'filename' => basename($image->path),
                'extension' => strtolower(pathinfo($image->path, PATHINFO_EXTENSION)),
                'endpoint' => "{$base}/portfolio/{$image->id}",
                'status' => $portfolioStatus,
                'feedback' => $portfolioReview['feedback'] ?? null,
                'reviewed_at' => $portfolioReview['reviewed_at'] ?? null,
                'reviewed_by' => $portfolioReview['reviewed_by'] ?? null,
            ];
        }

        $of = fn (string $type) => array_values(array_filter($items, fn ($i) => $i['type'] === $type));
        $additional = $of('additional_document');
        $portfolio = $portfolioItems;
        $permit = $of('business_permit');

        $businessMissing = $this->missingBusinessFields($application, $isStudio);
        $profileMissing = $this->missingProfileFields($application);
        $accountMissing = $this->missingAccountFields($application);

        $requirements = [
            [
                'key' => 'account_info', 'label' => 'Account & classification', 'required' => true,
                'status' => $accountMissing === [] ? 'submitted' : 'missing',
                'detail' => $accountMissing === [] ? 'All details provided' : 'Missing: '.implode(', ', $accountMissing),
            ],
            [
                'key' => 'business_info', 'label' => 'Applicant & business profile', 'required' => true,
                'status' => $businessMissing === [] ? 'submitted' : 'missing',
                'detail' => $businessMissing === [] ? 'All details provided' : 'Missing: '.implode(', ', $businessMissing),
            ],
            $this->docRow('government_id', 'Government ID', true, $of('government_id')),
            $this->docRow('selfie_with_id', 'Selfie holding ID', true, $of('selfie_with_id')),
            $this->countRow('additional_documents', 'Additional supporting documents', self::ADDITIONAL_MIN, self::ADDITIONAL_MAX, $additional),
        ];

        if ($isStudio || $permit !== []) {
            $requirements[] = $this->docRow('business_permit', 'Business permit / DTI registration', false, $permit);
        }

        $requirements[] = $this->countRow('portfolio', 'Photography portfolio', self::PORTFOLIO_MIN, self::PORTFOLIO_MAX, $portfolio);
        $requirements[] = [
            'key' => 'public_profile', 'label' => 'Public profile (bio, style, link)', 'required' => true,
            'status' => $profileMissing === [] ? 'submitted' : 'missing',
            'detail' => $profileMissing === [] ? 'All details provided' : 'Missing: '.implode(', ', $profileMissing),
        ];

        // Problems with what the applicant submitted (used by submit AND approve).
        $problems = [];
        foreach ($requirements as $r) {
            if ($r['required'] && $r['status'] === 'missing') {
                $problems[] = "{$r['label']}: {$r['detail']}";
            }
        }
        if (count($additional) > self::ADDITIONAL_MAX) {
            $problems[] = 'Additional supporting documents: more than '.self::ADDITIONAL_MAX.' uploaded.';
        }

        // Problems with the admin's inspection (approve only).
        $reviewBlockers = [];
        $pending = 0;
        foreach ($items as $i) {
            if ($i['status'] === 'needs_revision') {
                $reviewBlockers[] = "{$i['label']} is flagged for revision.";
            } elseif ($i['status'] === 'pending') {
                $pending++;
            }
        }
        if ($portfolioReview) {
            if ($portfolioStatus === 'needs_revision') {
                $reviewBlockers[] = 'Photography portfolio is flagged for revision.';
            } elseif ($portfolioStatus === 'pending') {
                $pending++;
            }
        }
        if ($pending > 0) {
            $reviewBlockers[] = "{$pending} submitted item(s) still need to be reviewed.";
        }

        // Grouped, field-level view for the admin review modal. Built from the same
        // data and predicates as $requirements above, so both views always agree.
        $groups = $this->buildGroups($application, $isStudio, $items, $portfolioItems, $portfolioReview);

        $groupMissing = [];
        foreach ($groups as $g) {
            foreach ($g['rows'] as $row) {
                if ($row['required'] && $row['status'] === 'missing') {
                    $groupMissing[] = $row['label'];
                }
            }
        }
        // Safety net: if a required row is missing, approval must be blocked even if
        // the requirement rows above did not catch it.
        if ($groupMissing !== [] && $problems === []) {
            $problems[] = 'Missing required items: '.implode(', ', $groupMissing).'.';
        }

        $required = array_values(array_filter($requirements, fn ($r) => $r['required']));
        $statuses = array_count_values(array_column($items, 'status'));
        if ($portfolioReview) {
            // The whole portfolio counts as ONE reviewable item.
            $statuses[$portfolioStatus] = ($statuses[$portfolioStatus] ?? 0) + 1;
        }
        $groupRequiredTotal = array_sum(array_column($groups, 'required_total'));
        $groupRequiredSubmitted = array_sum(array_column($groups, 'required_submitted'));

        return [
            'requirements' => $requirements,
            'groups' => $groups,
            'items' => array_merge($items, $portfolioItems),
            'problems' => $problems,
            'summary' => [
                'required_total' => $groupRequiredTotal,
                'required_submitted' => $groupRequiredSubmitted,
                'missing_required' => $groupMissing,
                'pending_count' => $statuses['pending'] ?? 0,
                'needs_revision_count' => $statuses['needs_revision'] ?? 0,
                'reviewed_count' => $statuses['reviewed'] ?? 0,
                'reviewable_total' => count($items) + ($portfolioReview ? 1 : 0),
                'ready_for_approval' => $problems === [] && $reviewBlockers === [],
                'blockers' => array_merge($problems, $reviewBlockers),
            ],
        ];
    }

    private function item($reviews, string $type, string $key, string $label, string $path, string $endpoint): array
    {
        $review = $reviews->get($type.'|'.$key);
        // A review only counts if it was made on the file that is there now.
        $current = $review && $review->file_path === $path ? $review : null;

        return [
            'type' => $type,
            'ref' => $key,
            'label' => $label,
            'filename' => basename($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'endpoint' => $endpoint,
            'status' => $current?->status ?? 'pending',
            'feedback' => $current?->feedback,
            'reviewed_at' => $current?->reviewed_at?->toIso8601String(),
            'reviewed_by' => $current?->reviewer?->name,
        ];
    }

    private function aggregate(array $items): string
    {
        if ($items === []) {
            return 'missing';
        }
        $statuses = array_column($items, 'status');
        if (in_array('needs_revision', $statuses, true)) {
            return 'needs_revision';
        }
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        return 'reviewed';
    }

    private function docRow(string $key, string $label, bool $required, array $items): array
    {
        return [
            'key' => $key, 'label' => $label, 'required' => $required,
            'status' => $this->aggregate($items),
            'detail' => $items === [] ? 'Not submitted' : 'Uploaded',
        ];
    }

    private function countRow(string $key, string $label, int $min, int $max, array $items): array
    {
        $count = count($items);

        return [
            'key' => $key, 'label' => $label, 'required' => true,
            'status' => $count < $min ? 'missing' : $this->aggregate($items),
            'detail' => $count < $min
                ? "{$count} of {$min} minimum uploaded"
                : "{$count} uploaded (allowed {$min} to {$max})",
        ];
    }

    private function isStudio(PhotographerApplication $application): bool
    {
        return (bool) $application->is_studio_owner || $application->photographer_type === PhotographerType::Studio;
    }

    private function missingBusinessFields(PhotographerApplication $application, bool $isStudio): array
    {
        $missing = [];

        if (blank($application->business_name)) $missing[] = 'business name';
        if (blank($application->location)) $missing[] = 'location';
        if ($application->years_active === null) $missing[] = 'years active';
        if ($isStudio && empty($application->team_size)) $missing[] = 'team size';
        if (empty($application->services)) $missing[] = 'services';
        if (blank($application->coverage_area)) $missing[] = 'coverage area';
        if (empty($application->shooting_types)) $missing[] = 'shooting types';

        return $missing;
    }

    // ASSUMPTION (verify in Step 0): photographer_profiles has bio, style, facebook, instagram, website.
    private function missingProfileFields(PhotographerApplication $application): array
    {
        $profile = $application->user?->photographerProfile;
        $missing = [];

        if (mb_strlen(trim((string) ($profile?->bio ?? ''))) < self::BIO_MIN_LENGTH) {
            $missing[] = 'bio ('.self::BIO_MIN_LENGTH.'+ characters)';
        }

        $style = $profile?->style;
        if (is_string($style)) {
            $decoded = json_decode($style, true);
            $style = is_array($decoded) ? $decoded : array_filter([$style]);
        }
        if (empty($style)) {
            $missing[] = 'photography style';
        }

        if (blank($profile?->facebook) && blank($profile?->instagram) && blank($profile?->website)) {
            $missing[] = 'social or website link';
        }

        return $missing;
    }

    private function missingAccountFields(PhotographerApplication $application): array
    {
        $user = $application->user;
        $missing = [];

        if (blank($user?->name)) $missing[] = 'full name';
        if (blank($user?->email)) $missing[] = 'email';
        if (blank($user?->phone_number)) $missing[] = 'phone number';
        if (! $application->is_freelancer && ! $application->is_studio_owner && $application->photographer_type === null) {
            $missing[] = 'photographer classification';
        }

        return $missing;
    }

    /** @return string[] */
    private function profileStyles(?PhotographerProfile $profile): array
    {
        $style = $profile?->style;
        if (is_string($style)) {
            $decoded = json_decode($style, true);
            $style = is_array($decoded) ? $decoded : array_filter([$style]);
        }

        return array_values(array_filter((array) $style, fn ($s) => filled($s)));
    }

    private function fieldRow(string $key, string $label, bool $required, mixed $value, bool $filled, ?string $detail = null): array
    {
        $shown = (is_array($value) ? $value !== [] : filled($value)) ? $value : null;

        return [
            'key' => $key,
            'label' => $label,
            'required' => $required,
            'kind' => 'field',
            'status' => $filled ? 'submitted' : ($required ? 'missing' : 'optional'),
            'value' => $shown,
            'detail' => $detail,
        ];
    }

    private function fileRow(string $key, string $label, bool $required, array $items, ?int $min = null, ?int $max = null): array
    {
        $count = count($items);

        if ($count === 0) {
            $status = $required ? 'missing' : 'optional';
            $detail = ! $required
                ? 'Not submitted. Optional, so it does not affect approval.'
                : ($min !== null ? "Not submitted (minimum {$min})" : 'Not submitted');
        } elseif ($min !== null && $count < $min) {
            $status = 'missing';
            $detail = "{$count} of {$min} minimum uploaded";
        } else {
            $status = $this->aggregate($items);
            $detail = $min !== null && $max !== null ? "{$count} uploaded (allowed {$min} to {$max})" : "{$count} uploaded";
        }

        $byStatus = array_count_values(array_column($items, 'status'));

        return [
            'key' => $key,
            'label' => $label,
            'required' => $required,
            'kind' => 'files',
            'item_type' => $items[0]['type'] ?? null,
            'status' => $status,
            'value' => null,
            'detail' => $detail,
            'count' => $count,
            'item_counts' => [
                'pending' => $byStatus['pending'] ?? 0,
                'reviewed' => $byStatus['reviewed'] ?? 0,
                'needs_revision' => $byStatus['needs_revision'] ?? 0,
            ],
        ];
    }

    private function group(string $key, string $label, array $rows): array
    {
        $rows = array_values($rows);
        $required = array_filter($rows, fn ($r) => $r['required']);

        $pending = $reviewed = $revision = 0;
        foreach ($rows as $r) {
            if ($r['kind'] === 'files') {
                $pending += $r['item_counts']['pending'];
                $reviewed += $r['item_counts']['reviewed'];
                $revision += $r['item_counts']['needs_revision'];
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'rows' => $rows,
            'required_total' => count($required),
            'required_submitted' => count(array_filter($required, fn ($r) => $r['status'] !== 'missing')),
            'missing_count' => count(array_filter($required, fn ($r) => $r['status'] === 'missing')),
            'pending_count' => $pending,
            'reviewed_count' => $reviewed,
            'needs_revision_count' => $revision,
        ];
    }

    private function buildGroups(PhotographerApplication $application, bool $isStudio, array $items, array $portfolioItems, ?array $portfolioReview): array
    {
        $of = fn (string $type) => array_values(array_filter($items, fn ($i) => $i['type'] === $type));
        $user = $application->user;
        $profile = $user?->photographerProfile;
        $styles = $this->profileStyles($profile);

        // Classification comes from the two registration checkboxes (legacy single column as fallback).
        $classification = [];
        if ($application->is_freelancer) $classification[] = 'Freelance Photographer';
        if ($application->is_studio_owner) $classification[] = 'Studio Owner';
        if ($classification === [] && $application->photographer_type !== null) {
            $classification[] = $application->photographer_type === PhotographerType::Studio ? 'Studio Owner' : 'Freelance Photographer';
        }

        $bio = trim((string) ($profile?->bio ?? ''));
        $bioDetail = $bio === '' ? null : (mb_strlen($bio) < self::BIO_MIN_LENGTH
            ? 'Only '.mb_strlen($bio).' of '.self::BIO_MIN_LENGTH.' minimum characters'
            : null);

        $humanize = fn (array $values) => array_map(fn ($v) => ucfirst(str_replace('_', ' ', (string) $v)), $values);

        $priceMin = $application->price_min;
        $priceMax = $application->price_max;
        $price = null;
        if ($priceMin !== null && $priceMax !== null) {
            $price = '₱'.number_format((float) $priceMin).' – ₱'.number_format((float) $priceMax);
        } elseif ($priceMin !== null || $priceMax !== null) {
            $price = '₱'.number_format((float) ($priceMin ?? $priceMax));
        }

        // Portfolio row: counted as ONE item, with the status of the single portfolio review.
        $portfolioRow = $this->fileRow('portfolio', 'Portfolio images', true, $portfolioItems, self::PORTFOLIO_MIN, self::PORTFOLIO_MAX);
        $pStatus = $portfolioReview['status'] ?? null;
        $portfolioRow['item_counts'] = [
            'pending' => $pStatus === 'pending' ? 1 : 0,
            'reviewed' => $pStatus === 'reviewed' ? 1 : 0,
            'needs_revision' => $pStatus === 'needs_revision' ? 1 : 0,
        ];

        $docs = [
            $this->fileRow('government_id', 'Government ID', true, $of('government_id')),
            $this->fileRow('selfie_with_id', 'Selfie holding ID', true, $of('selfie_with_id')),
        ];

        // Required row first, so a red "missing" never sits next to the optional permit row.
        $businessDocs = [
            $this->fileRow('additional_documents', 'Additional supporting documents', true, $of('additional_document'), self::ADDITIONAL_MIN, self::ADDITIONAL_MAX),
        ];
        if ($isStudio || $of('business_permit') !== []) {
            $businessDocs[] = $this->fileRow('business_permit', 'Business permit / DTI registration', false, $of('business_permit'));
        }

        $applicant = [
            $this->fieldRow('full_name', 'Full name', true, $user?->name, filled($user?->name)),
            $this->fieldRow('email', 'Email address', true, $user?->email, filled($user?->email)),
            $this->fieldRow('phone', 'Phone number', true, $user?->phone_number, filled($user?->phone_number)),
            $this->fieldRow('bio', 'Bio', true, $bio, mb_strlen($bio) >= self::BIO_MIN_LENGTH, $bioDetail),
        ];

        $photography = [
            $this->fieldRow('classification', 'Photographer classification', true, $classification, $classification !== []),
        ];
        if (! $isStudio) {
            $photography[] = $this->fieldRow('years_experience', 'Years of experience', true, $application->years_active !== null ? (string) $application->years_active : null, $application->years_active !== null);
        }
        $photography[] = $this->fieldRow('styles', 'Photography styles', true, $styles, $styles !== []);

        $services = [
            $this->fieldRow('services', 'Services offered', true, $application->services ?? [], ! empty($application->services)),
            $this->fieldRow('other_services', 'Other services', false, $application->other_services, filled($application->other_services)),
            $this->fieldRow('shooting_types', 'Shooting types', true, $humanize($application->shooting_types ?? []), ! empty($application->shooting_types)),
            $this->fieldRow('coverage_area', 'Coverage area', true, self::COVERAGE_LABELS[$application->coverage_area] ?? $application->coverage_area, filled($application->coverage_area)),
            // Not collected at registration (packages are created after approval), so never required.
            $this->fieldRow('price_range', 'Base price range', false, $price, $price !== null),
        ];

        $business = [
            $this->fieldRow('business_name', $isStudio ? 'Studio / business name' : 'Photography brand name', true, $application->business_name, filled($application->business_name)),
            $this->fieldRow('location', $isStudio ? 'Studio location' : 'Based in', true, $application->location, filled($application->location)),
        ];
        if ($isStudio) {
            $business[] = $this->fieldRow('years_operating', 'Years operating', true, $application->years_active !== null ? (string) $application->years_active : null, $application->years_active !== null);
            $business[] = $this->fieldRow('team_size', 'Team size', true, $application->team_size !== null ? (string) $application->team_size : null, ! empty($application->team_size));
        }

        $anySocial = filled($profile?->facebook) || filled($profile?->instagram) || filled($profile?->website);
        $social = [
            $this->fieldRow('social_minimum', 'At least one link', true, $anySocial ? 'Provided' : null, $anySocial, 'Facebook, Instagram or website'),
            $this->fieldRow('facebook', 'Facebook', false, $profile?->facebook, filled($profile?->facebook)),
            $this->fieldRow('instagram', 'Instagram', false, $profile?->instagram, filled($profile?->instagram)),
            $this->fieldRow('website', 'Website', false, $profile?->website, filled($profile?->website)),
        ];

        return [
            $this->group('identity', 'Identity Verification', $docs),
            $this->group('business_documents', 'Business Documents', $businessDocs),
            $this->group('applicant', 'Applicant Profile', $applicant),
            $this->group('photography', 'Photography Details', $photography),
            $this->group('services', 'Services and Pricing', $services),
            $this->group('business', 'Business Profile', $business),
            $this->group('portfolio', 'Photography Portfolio', [$portfolioRow]),
            $this->group('social', 'Social Links', $social),
        ];
    }
}