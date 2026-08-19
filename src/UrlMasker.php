<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge;

/**
 * Removes credentials from an endpoint URL before it is put into an exception
 * message. Failure messages travel into {@see \Rasuvaeff\Yii3Outbox\Processor}
 * logs verbatim, so a token carried in the query string or in the userinfo
 * component would otherwise be persisted by every log shipper the application
 * has.
 *
 * Scheme, host, port and path survive — the message stays diagnosable. Query
 * values, the userinfo password and the fragment are replaced with a fixed
 * placeholder; query keys survive so the reader still sees which parameter was
 * sent.
 *
 * @internal
 */
final readonly class UrlMasker
{
    private const string PLACEHOLDER = '***';

    public static function mask(string $url): string
    {
        /** @var array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|false $parts */
        $parts = parse_url($url);

        if ($parts === false) {
            return self::PLACEHOLDER;
        }

        $masked = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';

        if (isset($parts['user'])) {
            $masked .= $parts['user'] . (isset($parts['pass']) ? ':' . self::PLACEHOLDER : '') . '@';
        }

        $masked .= $parts['host'] ?? '';
        $masked .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $masked .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $masked .= '?' . self::maskQuery($parts['query']);
        }

        if (isset($parts['fragment'])) {
            $masked .= '#' . self::PLACEHOLDER;
        }

        return $masked === '' ? self::PLACEHOLDER : $masked;
    }

    private static function maskQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $key = strstr($pair, '=', before_needle: true);
            $pairs[] = $key === false ? self::PLACEHOLDER : $key . '=' . self::PLACEHOLDER;
        }

        return implode('&', $pairs);
    }
}
