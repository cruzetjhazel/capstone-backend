<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 500);

        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->transform($n));

        return response()->json(['data' => $notifications]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification)
    {
        // Scoped to the authenticated user — not a route-model-bound lookup,
        // so one user can never mark/read another user's notification.
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return response()->json(['data' => $this->transform($record->fresh())]);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // Known "booking.*" sub-types that mean the client owes a payment —
    // used to infer the "Pay Deposit / Balance" quick action for legacy
    // notification payloads that don't carry an explicit `action` key.
    private const PAYMENT_DUE_SUBTYPES = ['booking.accepted'];

    protected function transform(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        // Newer classes (e.g. ServiceTrackerUpdatedNotification::toDatabase())
        // already write title/description directly — use them as-is.
        if (array_key_exists('title', $data) || array_key_exists('description', $data)) {
            return [
                'id' => $notification->id,
                'type' => $data['type'] ?? 'system',
                'title' => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'booking_id' => isset($data['booking_id']) ? (string) $data['booking_id'] : null,
                'application_id' => isset($data['application_id']) ? (string) $data['application_id'] : null,
                'appeal_id' => isset($data['appeal_id']) ? (string) $data['appeal_id'] : null,
                'payment_id' => isset($data['payment_id']) ? (string) $data['payment_id'] : null,
                'report_id' => isset($data['report_id']) ? (string) $data['report_id'] : null,
                'action' => $data['action'] ?? null,
                'read' => $notification->read_at !== null,
                'created_at' => optional($notification->created_at)->toIso8601String(),
            ];
        }

        // Legacy classes (e.g. BookingAcceptedNotification::toArray()) write
        // a dot-notation `type` ("booking.accepted") and a `message` string
        // instead. Normalize that into the same shape the frontend expects.
        $rawType = (string) ($data['type'] ?? 'system');
        [$category] = explode('.', $rawType, 2) + [null, null];
        $type = in_array($category, ['booking', 'payment', 'message'], true) ? $category : 'system';
        $title = $rawType === 'system' ? 'Notification' : ucwords(str_replace('.', ' ', $rawType));

        return [
            'id' => $notification->id,
            'type' => $type,
            'title' => $title,
            'description' => $data['message'] ?? '',
            'booking_id' => isset($data['booking_id']) ? (string) $data['booking_id'] : null,
            'payment_id' => isset($data['payment_id']) ? (string) $data['payment_id'] : null,
            'action' => in_array($rawType, self::PAYMENT_DUE_SUBTYPES, true) ? 'pay' : null,
            'read' => $notification->read_at !== null,
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }
}