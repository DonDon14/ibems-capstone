<?php

namespace Config;

final class LocalDevelopmentBaseUrl
{
    public static function resolve(string $environment, mixed $host, mixed $https = null): ?string
    {
        if ($environment !== 'development' || ! is_string($host)) {
            return null;
        }

        $matched = preg_match(
            '/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::(?<port>\d{1,5}))?\z/i',
            $host,
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        if (isset($matches['port'])) {
            $port = (int) $matches['port'];
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        $isHttps = is_string($https)
            && $https !== ''
            && strtolower($https) !== 'off'
            && $https !== '0';

        return ($isHttps ? 'https://' : 'http://') . $host . '/';
    }
}
