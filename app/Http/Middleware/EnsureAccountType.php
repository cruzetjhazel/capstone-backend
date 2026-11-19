<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountType
{
    public function handle(Request $request, Closure $next, string $type)
    {
        $user = $request->user();

        $allowed = match ($type) {
            'client' => $user?->isClient(),
            'photographer' => $user?->isPhotographer(),
            'admin' => $user?->isAdministrator(),
            default => false,
        };

        if (! $allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Your account type cannot use this area.',
            ], 403);
        }

        return $next($request);
    }
}