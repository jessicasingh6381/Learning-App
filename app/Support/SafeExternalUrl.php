<?php

namespace App\Support;

final class SafeExternalUrl
{
    /**
     * @return array{url: string, domain: string}|null
     */
    public static function inspect(mixed $value): ?array
    {
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['https', 'http'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $blockedName = $host === 'localhost'
            || ! str_contains($host, '.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
        $blockedIp = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if ($blockedName || $blockedIp) {
            return null;
        }

        return ['url' => $value, 'domain' => $host];
    }
}
