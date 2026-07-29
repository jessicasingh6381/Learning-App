<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class StudentUsername
{
    private const RESERVED = [
        'admin',
        'administrator',
        'api',
        'help',
        'login',
        'logout',
        'owner',
        'root',
        'staff',
        'student',
        'support',
        'system',
        'teacher',
        'webmaster',
    ];

    public static function normalize(?string $username): string
    {
        return Str::lower(trim((string) $username));
    }

    public static function isReserved(string $username): bool
    {
        return in_array(self::normalize($username), self::RESERVED, true);
    }

    public static function suggest(string $firstName, string $lastName): string
    {
        $base = Str::of($firstName.'.'.$lastName)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9._-]+/', '.')
            ->trim('.-_')
            ->substr(0, 32)
            ->toString();

        $base = strlen($base) >= 3 ? $base : 'learner';
        $candidate = $base;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = Str::limit($base, 34, '').'-'.Str::lower(Str::random(5));
        }

        return $candidate;
    }
}
