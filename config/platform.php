<?php

return [
    /**
     * Flat fee added to every booking's total (see CreateBookingAction and
     * the platform_fee column on bookings). Covers the platform's dispute
     * handling / no-show support process — NOT a regulated insurance
     * product; the frontend must present it with that plain disclaimer.
     */
    'fee' => (float) env('PLATFORM_FEE', 0.00),
        'min_notice_hours' => (int) env('MIN_NOTICE_HOURS', 5),
];