<?php

namespace App\Support;

use App\Models\CyberKey;
use Illuminate\Support\Facades\Cookie;

class PersistentLogin
{
    public const COOKIE = 'aisyiyah_jatinom';

    /** Legacy cookie name from previous client branding. */
    public const LEGACY_COOKIE = 'arruhama_stay';

    public static function queue(CyberKey $user): void
    {
        $payload = json_encode([
            'urut' => (int) $user->urut,
            'exp' => now()->addYears(5)->timestamp,
        ]);

        Cookie::queue(cookie(
            self::COOKIE,
            $payload,
            5256000,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax'
        ));

        // Drop legacy cookie if still present after rebrand.
        if (request()->cookie(self::LEGACY_COOKIE)) {
            Cookie::queue(Cookie::forget(self::LEGACY_COOKIE));
        }
    }

    public static function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
        Cookie::queue(Cookie::forget(self::LEGACY_COOKIE));
    }

    public static function rawFromRequest(): ?string
    {
        $raw = request()->cookie(self::COOKIE);
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        $legacy = request()->cookie(self::LEGACY_COOKIE);
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return null;
    }

    public static function userFromCookie(?string $raw): ?CyberKey
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $urut = (int) ($data['urut'] ?? 0);
        $exp = (int) ($data['exp'] ?? 0);

        if ($urut <= 0 || $exp < time()) {
            return null;
        }

        return CyberKey::query()->where('urut', $urut)->first();
    }
}
