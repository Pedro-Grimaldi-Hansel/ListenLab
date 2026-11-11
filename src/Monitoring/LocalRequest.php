<?php
declare(strict_types=1);

namespace ListenLab\Monitoring;

final class LocalRequest
{
    public static function allows(array $server, int $port): bool
    {
        if ($port < 1 || $port > 65535 || !is_string($server['HTTP_HOST'] ?? null)) return false;
        $authority = strtolower($server['HTTP_HOST']);
        $allowed = [];
        foreach (['127.0.0.1', '[::1]', 'localhost'] as $host) {
            $allowed[] = $host . ':' . $port;
            if ($port === 80) $allowed[] = $host;
        }
        if (!in_array($authority, $allowed, true)) return false;
        if (($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            $navigation = ($server['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate'
                && ($server['HTTP_SEC_FETCH_DEST'] ?? '') === 'document'
                && in_array($server['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)
                && in_array(parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH), ['/', '/dashboard', '/dashboard/', '/analytics', '/analytics/', '/tests', '/tests/'], true);
            if (!$navigation) return false;
        }
        if (isset($server['HTTP_ORIGIN'])) {
            if (!is_string($server['HTTP_ORIGIN'])) return false;
            $expected = 'http://' . $authority;
            $origin = strtolower($server['HTTP_ORIGIN']);
            if ($port === 80) {
                $expected = preg_replace('/:80$/D', '', $expected);
                $origin = preg_replace('/:80$/D', '', $origin);
            }
            if ($origin !== $expected) return false;
        }
        return true;
    }
}
