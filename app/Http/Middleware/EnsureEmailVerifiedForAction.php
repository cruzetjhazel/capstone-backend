<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerifiedForAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Administrators are never blocked by email verification.
        if (! $user || $user->isAdministrator() || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        return self::deny();
    }

    public static function deny(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Verify your email address first. Check your inbox (and spam), or use the Resend button to get a new link.',
            'errors' => null,
            'code' => 'email_not_verified',
        ], 403);
    }
}