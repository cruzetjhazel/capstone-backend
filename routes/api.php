<?php

use App\Http\Controllers\Api\AppealController;
use App\Http\Controllers\Api\Admin\AppealController as AdminAppealController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\PhotographerApplicationController as AdminPhotographerApplicationController;
use App\Http\Controllers\Api\PhotographerApplicationController;
use App\Http\Controllers\Api\Admin\PhotographerPortfolioController as AdminPhotographerPortfolioController;
use App\Http\Controllers\Api\Photographer\PortfolioController;
use App\Http\Controllers\Api\Photographer\ProfileController;
use App\Http\Controllers\Api\PublicPhotographerController;
use App\Http\Controllers\Api\Photographer\AddOnController;
use App\Http\Controllers\Api\Photographer\CustomPackageController;
use App\Http\Controllers\Api\Photographer\PackageController;
use App\Http\Controllers\Api\PublicPhotographerAddOnController;
use App\Http\Controllers\Api\PublicPhotographerPackageController;
use App\Http\Controllers\Api\Photographer\AvailabilityWindowController;
use App\Http\Controllers\Api\Photographer\BlockedDateController;
use App\Http\Controllers\Api\Photographer\BookingHourController;
use App\Http\Controllers\Api\PublicPhotographerAvailabilityController;
use App\Http\Controllers\Api\Client\BookingController as ClientBookingController;
use App\Http\Controllers\Api\Photographer\BookingController as PhotographerBookingController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Photographer\PaymentConfigController;
use App\Http\Controllers\Api\Client\PaymentController as ClientPaymentController;
use App\Http\Controllers\Api\Photographer\PaymentController as PhotographerPaymentController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Photographer\PaymentReferenceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Client\ProfileController as ClientProfileController;
use App\Http\Controllers\Api\Client\FavoritePackageController;
use App\Http\Controllers\Api\Client\FavoritePhotographerController;
use App\Http\Controllers\Api\Photographer\ClientController;
use App\Http\Controllers\Api\Photographer\ServiceTrackerController;
use App\Http\Controllers\Api\Client\ReviewController as ClientReviewController;
use App\Http\Controllers\Api\Photographer\ReviewController as PhotographerReviewController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\PublicPhotographerCustomPackageController;
use App\Http\Controllers\Api\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Photographer\AnalyticsController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ServiceSearchLogController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Client\ContactChangeController;


Route::prefix('auth')->group(function () {
    // Per-email failed-attempt limiting is handled inside AuthController::login.
    // This is only a loose per-IP backstop.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:30,1');

    // The route name must stay "verification.verify": Laravel's built-in email uses it.
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.verify');

    // Link sent to the NEW address of an email change. Name is required by VerifyNewEmail.
    Route::get('email/change/verify/{id}/{hash}', [ContactChangeController::class, 'verifyEmailChange'])
        ->middleware('throttle:10,1')
        ->name('email-change.verify');

    Route::post('register-client', [AuthController::class, 'register'])->middleware('throttle:register');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('reactivate', [AuthController::class, 'reactivate']);
        Route::post('appeal', [AppealController::class, 'store']);
    });

    // Already gated by a valid Sanctum token — no brute-force risk here,
    // so these stay on the default rate limit rather than the strict
    // per-email "auth" one (which would otherwise lump every user behind
    // a shared/office IP into one 5-per-minute bucket).
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::post('auth/register-photographer', [AuthController::class, 'registerPhotographer'])->middleware('throttle:register');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('photographer')->group(function () {
        Route::get('application', [PhotographerApplicationController::class, 'show']);
        Route::patch('application', [PhotographerApplicationController::class, 'update']);
        Route::post('application/submit', [PhotographerApplicationController::class, 'submit'])->middleware('verified');
        Route::post('application/reapply', [PhotographerApplicationController::class, 'reapply'])->middleware('verified');
        Route::get('application/documents/{type}', [PhotographerApplicationController::class, 'downloadDocument']);
        });

    Route::prefix('admin/photographer-applications')->group(function () {
        Route::get('/', [AdminPhotographerApplicationController::class, 'index']);
        Route::get('{photographerApplication}', [AdminPhotographerApplicationController::class, 'show']);
        Route::get('{photographerApplication}/documents/{type}', [AdminPhotographerApplicationController::class, 'downloadDocument']);
        Route::get('{photographerApplication}/additional-documents/{ref}', [AdminPhotographerApplicationController::class, 'downloadAdditionalDocument']);
        Route::get('{photographerApplication}/portfolio/{portfolioImage}', [AdminPhotographerApplicationController::class, 'portfolioImage']);
        Route::post('{photographerApplication}/reviews', [AdminPhotographerApplicationController::class, 'reviewItem']);
        Route::post('{photographerApplication}/approve', [AdminPhotographerApplicationController::class, 'approve']);
        Route::post('{photographerApplication}/reject', [AdminPhotographerApplicationController::class, 'reject']);
        Route::post('{photographerApplication}/request-revision', [AdminPhotographerApplicationController::class, 'requestRevision']);
    });
});

Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:auth');
Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:auth');
Route::post('auth/reset-password/validate', [PasswordResetController::class, 'validateToken'])->middleware('throttle:30,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('client')->group(function () {
        Route::get('profile', [ClientProfileController::class, 'show']);
        Route::patch('profile', [ClientProfileController::class, 'update']);
        Route::post('change-password', [ClientProfileController::class, 'changePassword']);
        Route::post('deactivate', [ClientProfileController::class, 'deactivate']);

        Route::post('change-contact-number', [ContactChangeController::class, 'changeContactNumber'])->middleware('throttle:5,1');
        Route::post('change-email', [ContactChangeController::class, 'requestEmailChange'])->middleware('throttle:5,1');
        Route::post('change-email/resend', [ContactChangeController::class, 'resendEmailChange'])->middleware('throttle:3,1');
        Route::post('change-email/cancel', [ContactChangeController::class, 'cancelEmailChange']);

        Route::get('favorites', [FavoritePhotographerController::class, 'index']);
        Route::post('favorites/{user}', [FavoritePhotographerController::class, 'store']);
        Route::delete('favorites/{user}', [FavoritePhotographerController::class, 'destroy']);

        Route::get('favorites/packages', [FavoritePackageController::class, 'index']);
        Route::post('favorites/packages/{package}', [FavoritePackageController::class, 'store']);
        Route::delete('favorites/packages/{package}', [FavoritePackageController::class, 'destroy']);

        Route::get('activity-logs', [ActivityLogController::class, 'mine']);
    });
});

Route::get('photographers', [PublicPhotographerController::class, 'index']);
Route::get('photographers/featured', [PublicPhotographerController::class, 'featured']);
Route::get('photographers/{user}', [PublicPhotographerController::class, 'show']);

Route::get('locations/provinces', [LocationController::class, 'provinces']);
Route::get('locations/cities-municipalities', [LocationController::class, 'citiesMunicipalities']);
Route::get('locations/barangays', [LocationController::class, 'barangays']);

Route::post('search-logs', [ServiceSearchLogController::class, 'store']);
Route::get('search-logs/popular', [ServiceSearchLogController::class, 'popular']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('photographer')->group(function () {
        Route::post('profile', [ProfileController::class, 'store']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);
        Route::get('profile/completeness', [ProfileController::class, 'completeness']);
        Route::post('change-password', [ProfileController::class, 'changePassword']);
        Route::post('deactivate', [ProfileController::class, 'deactivate']);

        Route::get('portfolio', [PortfolioController::class, 'index']);
        Route::post('portfolio', [PortfolioController::class, 'store'])->middleware('verified.action');
        Route::patch('portfolio/reorder', [PortfolioController::class, 'reorder']);
        Route::post('portfolio/{portfolioImage}/archive', [PortfolioController::class, 'archive']);
        Route::post('portfolio/{portfolioImage}/restore', [PortfolioController::class, 'restore']);
        Route::delete('portfolio/{portfolioImage}', [PortfolioController::class, 'destroy']);
    });

    Route::post('admin/photographer-portfolio-images/{portfolioImage}/archive', [AdminPhotographerPortfolioController::class, 'archive']);
});
Route::get('photographers/{user}/packages', [PublicPhotographerPackageController::class, 'index']);
Route::get('photographers/{user}/add-ons', [PublicPhotographerAddOnController::class, 'index']);
Route::get('photographers/{user}/custom-package', [PublicPhotographerCustomPackageController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('photographer')->group(function () {
        Route::apiResource('packages', PackageController::class)->except(['destroy'])->parameters(['packages' => 'package']);
        Route::delete('packages/{package}', [PackageController::class, 'destroy']);
        Route::post('packages/{package}/publish', [PackageController::class, 'publish']);
        Route::post('packages/{package}/revert-to-draft', [PackageController::class, 'revertToDraft']);
        Route::post('packages/{package}/archive', [PackageController::class, 'archive']);
        Route::post('packages/{package}/restore', [PackageController::class, 'restore']);

        Route::apiResource('add-ons', AddOnController::class)->parameters(['add-ons' => 'addOn']);
        Route::post('add-ons/{addOn}/archive', [AddOnController::class, 'archive']);
        Route::post('add-ons/{addOn}/restore', [AddOnController::class, 'restore']);

        Route::get('clients', [ClientController::class, 'index']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::patch('clients/{walkInClient}', [ClientController::class, 'update']);
        Route::post('clients/{walkInClient}/archive', [ClientController::class, 'archive']);
        Route::post('clients/{walkInClient}/restore', [ClientController::class, 'restore']);
        Route::delete('clients/{walkInClient}', [ClientController::class, 'destroy']);

        Route::get('custom-package/config', [CustomPackageController::class, 'showConfig']);
        Route::patch('custom-package/config', [CustomPackageController::class, 'updateConfig']);
        Route::get('custom-package/components', [CustomPackageController::class, 'indexComponents']);
        Route::post('custom-package/components', [CustomPackageController::class, 'storeComponent']);
        Route::patch('custom-package/components/{component}', [CustomPackageController::class, 'updateComponent']);
        Route::post('custom-package/components/{component}/archive', [CustomPackageController::class, 'archiveComponent']);
        Route::post('custom-package/components/{component}/restore', [CustomPackageController::class, 'restoreComponent']);
    });
});
Route::get('photographers/{user}/availability/calendar', [PublicPhotographerAvailabilityController::class, 'calendar']);
Route::get('photographers/{user}/availability/slots', [PublicPhotographerAvailabilityController::class, 'slots']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('photographer')->group(function () {
        Route::get('availability-windows', [AvailabilityWindowController::class, 'index']);
        Route::post('availability-windows', [AvailabilityWindowController::class, 'store']);
        Route::patch('availability-windows/{availabilityWindow}', [AvailabilityWindowController::class, 'update']);
        Route::delete('availability-windows/{availabilityWindow}', [AvailabilityWindowController::class, 'destroy']);

        Route::get('blocked-dates', [BlockedDateController::class, 'index']);
        Route::post('blocked-dates', [BlockedDateController::class, 'store']);
        Route::patch('blocked-dates/{blockedDate}', [BlockedDateController::class, 'update']);
        Route::delete('blocked-dates/{blockedDate}', [BlockedDateController::class, 'destroy']);

        Route::get('booking-hours', [BookingHourController::class, 'index']);
        Route::post('booking-hours', [BookingHourController::class, 'store']);
        // Must come BEFORE the {bookingHour} route. Otherwise "interval" is read as an ID and the request returns 404,
        // so the slot interval chosen on the photographer calendar was never saved.
        Route::get('booking-hours/interval', [BookingHourController::class, 'interval']);
        Route::patch('booking-hours/interval', [BookingHourController::class, 'updateInterval']);
        Route::patch('booking-hours/{bookingHour}', [BookingHourController::class, 'update'])->whereNumber('bookingHour');
        Route::delete('booking-hours/{bookingHour}', [BookingHourController::class, 'destroy']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('client')->group(function () {
        Route::get('bookings', [ClientBookingController::class, 'index']);
        Route::post('bookings', [ClientBookingController::class, 'store'])->middleware('verified.action');
        Route::get('bookings/{booking}', [ClientBookingController::class, 'show']);
        Route::post('bookings/{booking}/request-cancellation', [ClientBookingController::class, 'requestCancellation']);
            Route::post('bookings/{booking}/accept-offer', [ClientBookingController::class, 'acceptOffer']);
       Route::post('bookings/{booking}/budget-request', [\App\Http\Controllers\Api\Client\BudgetRequestController::class, 'store']);
   Route::post('bookings/{booking}/budget-request/accept', [\App\Http\Controllers\Api\Client\BudgetRequestController::class, 'accept']);
   Route::post('bookings/{booking}/budget-request/reject', [\App\Http\Controllers\Api\Client\BudgetRequestController::class, 'reject']);
   Route::post('bookings/{booking}/decline-offer', [ClientBookingController::class, 'declineOffer']);
        Route::post('bookings/{booking}/report-non-completion', [ClientBookingController::class, 'reportNonCompletion']);
        Route::post('bookings/{booking}/dispute-non-completion', [ClientBookingController::class, 'disputeNonCompletion']);
        Route::post('bookings/{booking}/reschedule', [ClientBookingController::class, 'requestReschedule']);
        Route::get('bookings/{booking}/reschedule-slots', [ClientBookingController::class, 'rescheduleSlots']);
        Route::post('bookings/{booking}/request-modification', [ClientBookingController::class, 'requestModification']);
        Route::post('bookings/{booking}/extensions', [ClientBookingController::class, 'requestExtension']);
        Route::get('bookings/{booking}/payment-info', [ClientPaymentController::class, 'paymentInfo']);
        Route::post('bookings/{booking}/payments', [ClientPaymentController::class, 'store'])->middleware('verified.action');
        Route::get('payments', [ClientPaymentController::class, 'index']);
    });

    Route::prefix('photographer')->group(function () {
        Route::get('bookings', [PhotographerBookingController::class, 'index']);
        Route::post('bookings/manual', [PhotographerBookingController::class, 'storeManual']);
        Route::get('bookings/{booking}', [PhotographerBookingController::class, 'show']);
        Route::post('bookings/{booking}/accept', [PhotographerBookingController::class, 'accept']);
           Route::post('bookings/{booking}/reject', [PhotographerBookingController::class, 'reject']);
   Route::post('bookings/{booking}/budget-request/respond', [\App\Http\Controllers\Api\Photographer\BudgetRequestController::class, 'respond']);
        Route::post('bookings/{booking}/report-non-completion', [PhotographerBookingController::class, 'reportNonCompletion']);
        Route::post('bookings/{booking}/dispute-non-completion', [PhotographerBookingController::class, 'disputeNonCompletion']);
        Route::post('bookings/{booking}/cancellation/approve', [PhotographerBookingController::class, 'approveCancellation']);
        Route::post('bookings/{booking}/cancellation/reject', [PhotographerBookingController::class, 'rejectCancellation']);
        Route::get('bookings/{booking}/accommodation-candidates', [PhotographerBookingController::class, 'accommodationCandidates']);
        Route::post('bookings/{booking}/accommodate', [PhotographerBookingController::class, 'accommodate']);
        Route::post('bookings/{booking}/reschedule/approve', [PhotographerBookingController::class, 'approveReschedule']);
        Route::post('bookings/{booking}/reschedule/reject', [PhotographerBookingController::class, 'rejectReschedule']);
        Route::post('bookings/{booking}/modification/approve', [PhotographerBookingController::class, 'approveModification']);
        Route::post('bookings/{booking}/modification/reject', [PhotographerBookingController::class, 'rejectModification']);
        Route::post('bookings/{booking}/cancel', [PhotographerBookingController::class, 'cancel']);
        Route::post('bookings/{booking}/extensions/{extension}/approve', [PhotographerBookingController::class, 'approveExtension']);
        Route::post('bookings/{booking}/extensions/{extension}/decline', [PhotographerBookingController::class, 'declineExtension']);
        Route::patch('bookings/{booking}/service-tracker', [ServiceTrackerController::class, 'update']);
        Route::post('bookings/{booking}/confirm-shoot', [ServiceTrackerController::class, 'confirmShoot']);
        Route::post('bookings/{booking}/complete', [ServiceTrackerController::class, 'complete']);

        Route::get('payment-config', [PaymentConfigController::class, 'show']);
        Route::post('payment-config', [PaymentConfigController::class, 'store']);
        Route::post('bookings/{booking}/payments/onsite', [PhotographerPaymentController::class, 'storeOnsite']);
        Route::get('payments', [PhotographerPaymentController::class, 'index']);

        Route::get('payment-references', [PaymentReferenceController::class, 'index']);
        Route::post('payment-references', [PaymentReferenceController::class, 'store']);
        Route::post('payment-references/{paymentReference}/invalidate', [PaymentReferenceController::class, 'invalidate']);

        Route::post('payments/{payment}/verify', [PhotographerPaymentController::class, 'verify']);
        Route::post('payments/{payment}/reject', [PhotographerPaymentController::class, 'reject']);

        Route::get('activity-logs', [ActivityLogController::class, 'mine']);
    });

    Route::get('admin/payments', [AdminPaymentController::class, 'index']);
    Route::post('admin/payments/{payment}/refund', [AdminPaymentController::class, 'refund']);
    Route::get('admin/bookings', [AdminBookingController::class, 'index']);
    Route::get('admin/bookings/{booking}', [AdminBookingController::class, 'show']);
    Route::post('admin/bookings/{booking}/cancel', [AdminBookingController::class, 'cancel']);
    Route::post('admin/bookings/{booking}/non-completion/resolve', [AdminBookingController::class, 'resolveNonCompletion']);
    Route::get('admin/dashboard-stats', [AdminDashboardController::class, 'stats']);
    Route::get('admin/analytics', [AdminAnalyticsController::class, 'index']);
    Route::get('admin/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('admin/activity-logs/archive', [ActivityLogController::class, 'archive']);
    Route::get('admin/settings', [AdminSettingsController::class, 'show']);
    Route::put('admin/settings', [AdminSettingsController::class, 'update']);

    Route::prefix('admin/users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('{user}', [AdminUserController::class, 'show']);
        Route::get('{user}/suspension-impact', [AdminUserController::class, 'suspensionImpact']);
        Route::post('{user}/suspend', [AdminUserController::class, 'suspend']);
        Route::post('{user}/reactivate', [AdminUserController::class, 'reactivate']);
        Route::patch('{user}/contact-number', [AdminUserController::class, 'updateContactNumber']);
    });

    Route::prefix('admin/appeals')->group(function () {
        Route::get('/', [AdminAppealController::class, 'index']);
        Route::patch('{appeal}/decide', [AdminAppealController::class, 'decide']);
    });

    Route::prefix('admin/reports')->group(function () {
        Route::get('/', [AdminReportController::class, 'index']);
        Route::get('{report}', [AdminReportController::class, 'show']);
        Route::patch('{report}/status', [AdminReportController::class, 'updateStatus']);
        Route::post('{report}/notes', [AdminReportController::class, 'addNote']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('client')->group(function () {
        Route::get('reviews', [ClientReviewController::class, 'index']);
        Route::post('reviews', [ClientReviewController::class, 'store'])->middleware('verified.action');
    });

    Route::prefix('photographer')->group(function () {
        Route::get('reviews', [PhotographerReviewController::class, 'index']);
        Route::post('reviews/{review}/reply', [PhotographerReviewController::class, 'reply']);
        Route::post('reviews/{review}/report', [PhotographerReviewController::class, 'report']);
        Route::get('analytics', [AnalyticsController::class, 'index']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('client')->group(function () {
        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports', [ReportController::class, 'store'])->middleware('verified.action');
        Route::get('reports/{report}', [ReportController::class, 'show']);
    });

    Route::prefix('photographer')->group(function () {
        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports', [ReportController::class, 'store'])->middleware('verified.action');
        Route::get('reports/{report}', [ReportController::class, 'show']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});