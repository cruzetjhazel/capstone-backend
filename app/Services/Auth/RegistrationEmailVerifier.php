<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Proves someone owns an email address BEFORE an account exists.
 * Code: 6 digits, 10 minutes, 5 wrong tries. Proof: one-time token, 60 minutes.
 */
class RegistrationEmailVerifier
{
    public const CODE_MINUTES = 10;
    private const PROOF_MINUTES = 60;
    private const MAX_TRIES = 5;

    private function key(string $type, string $email): string
    {
        return "reg-email:{$type}:".sha1(strtolower(trim($email)));
    }

    public function issueCode(string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::CODE_MINUTES);

        Cache::put($this->key('code', $email), [
            'hash' => hash('sha256', $code),
            'tries' => 0,
            'expires_at' => $expires->getTimestamp(),
        ], $expires);
        Cache::forget($this->key('proof', $email));

        return $code;
    }

    /** Returns a proof token when the code is right, otherwise null. */
    public function checkCode(string $email, string $code): ?string
    {
        $key = $this->key('code', $email);
        $entry = Cache::get($key);

        if (! $entry) {
            return null;
        }

        if ($entry['tries'] >= self::MAX_TRIES) {
            Cache::forget($key);

            return null;
        }

        if (! hash_equals($entry['hash'], hash('sha256', $code))) {
            $entry['tries']++;
            Cache::put($key, $entry, Carbon::createFromTimestamp($entry['expires_at']));

            return null;
        }

        Cache::forget($key);
        $proof = Str::random(40);
        Cache::put($this->key('proof', $email), hash('sha256', $proof), now()->addMinutes(self::PROOF_MINUTES));

        return $proof;
    }

    public function proofIsValid(string $email, ?string $proof): bool
    {
        if (! $proof) {
            return false;
        }

        $stored = Cache::get($this->key('proof', $email));

        return is_string($stored) && hash_equals($stored, hash('sha256', $proof));
    }

    public function forgetProof(string $email): void
    {
        Cache::forget($this->key('proof', $email));
    }
}