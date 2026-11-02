<?php

namespace App\Services\Photographer;

use App\Models\User;

class ProfileCompletenessService
{
    /**
     * Full setup checklist: Module 3 (profile + portfolio) plus the
     * Module 4/7 prerequisites (a published package, GCash payment info)
     * that determine whether a photographer is actually bookable.
     *
     * `items` lists every requirement individually so the UI can say exactly
     * what is missing. The rules are the same ones used before
     * (PhotographerProfile::isComplete(), portfolio minimum, active package,
     * GCash name + number); they are just reported one by one.
     */
    public function evaluate(User $user): array
    {
        $profile = $user->photographerProfile;
        $activeCount = $user->activePortfolioImageCount();
        $minPortfolio = \App\Actions\Photographer\ArchivePortfolioImageAction::MIN_ACTIVE;

        $profileComplete = (bool) ($profile && $profile->isComplete());
        $portfolioMinimumMet = $activeCount >= $minPortfolio;

        $hasActivePackage = $user->hasActivePackage();

        $paymentConfig = $user->paymentConfig;
        $hasGcashName = (bool) ($paymentConfig && filled($paymentConfig->gcash_account_name));
        $hasGcashNumber = (bool) ($paymentConfig && filled($paymentConfig->gcash_account_number));
        $gcashConfigured = $hasGcashName && $hasGcashNumber;

        $module3Met = $profileComplete && $portfolioMinimumMet;

        $emailVerified = $user->hasVerifiedEmail();

        $items = [
            $this->item(
                'verified_email',
                'Verified email address',
                $emailVerified,
                $emailVerified ? null : 'Check your inbox (or spam) for the verification link'
            ),
            $this->item('bio', 'Bio / About Us', (bool) ($profile && filled($profile->bio))),
            $this->item('style', 'Photography style (at least 1 tag)', (bool) ($profile && filled($profile->style))),
            $this->item('social_link', 'Social or website link (at least 1)', (bool) ($profile && $profile->hasRequiredSocialLink())),
            $this->item('profile_photo', 'Profile picture', (bool) ($profile && filled($profile->profile_photo_path))),
            $this->item('cover_photo', 'Cover photo', (bool) ($profile && filled($profile->cover_photo_path))),
            $this->item('gcash_account_name', 'GCash account name', $hasGcashName),
            $this->item('gcash_account_number', 'GCash account number', $hasGcashNumber),
            $this->item('active_package', 'At least one published package', $hasActivePackage),
            $this->item(
                'portfolio_photos',
                "Portfolio photos (at least {$minPortfolio})",
                $portfolioMinimumMet,
                "{$activeCount}/{$minPortfolio} uploaded"
            ),
        ];

        $total = count($items);
        $completed = count(array_filter($items, fn ($i) => $i['complete']));

        return [
            'profile_complete' => $profileComplete,
            'active_portfolio_count' => $activeCount,
            'portfolio_minimum_met' => $portfolioMinimumMet,
            'portfolio_minimum_required' => $minPortfolio,
            'portfolio_maximum_allowed' => \App\Actions\Photographer\UploadPortfolioImageAction::MAX_ACTIVE,
            'module_3_requirements_met' => $module3Met,
            'has_active_package' => $hasActivePackage,
            'gcash_configured' => $gcashConfigured,
            'email_verified' => $emailVerified,
            'fully_bookable' => $module3Met && $hasActivePackage && $gcashConfigured && $emailVerified,

            'items' => $items,
            'completed_count' => $completed,
            'total_count' => $total,
            'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 0,
        ];
    }

    protected function item(string $key, string $label, bool $complete, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'detail' => $detail,
        ];
    }
}