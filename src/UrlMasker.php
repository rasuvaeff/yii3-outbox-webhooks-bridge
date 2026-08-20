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
 * {@see self::scrub()} covers the other half of the same leak: text that came
 * from somewhere else and happens to carry the endpoint's secrets.
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

    /**
     * Removes from arbitrary text every secret the endpoint URL carries.
     *
     * Masking the URL this class interpolates is only half the job: the
     * publisher also appends text it did not write — `getLastError()` from a
     * delivery, or the message of whatever the dispatcher threw — and a PSR-18
     * client routinely puts the whole request URI into that message
     * (`cURL error 7: Failed to connect ... for https://host/e?access_token=…`).
     * Appended verbatim, it puts the credential straight back into the log the
     * masking exists to keep clean.
     *
     * The URL is replaced with its masked form wherever it appears intact, and
     * each secret it carries is then removed wherever else it occurs — the
     * upstream text is free to quote a token on its own, percent-encoded or
     * not. A short query value (`?page=1`) makes this over-redact the text
     * around it; the opposite mistake is the expensive one.
     */
    public static function scrub(string $text, string $url): string
    {
        // An empty $url needs no guard: str_replace ignores an empty search
        // string, and an empty URL carries no secrets to remove either.
        $scrubbed = str_replace($url, self::mask($url), $text);

        return str_replace(self::secrets($url), self::PLACEHOLDER, $scrubbed);
    }

    /**
     * The secret-carrying substrings of a URL, longest first so that a secret
     * which is a prefix of another one cannot half-replace it.
     *
     * @return list<string>
     */
    private static function secrets(string $url): array
    {
        /** @var array{user?: string, pass?: string, query?: string, fragment?: string}|false $parsed */
        $parsed = parse_url($url);
        $parts = $parsed === false ? [] : $parsed;

        $secrets = [];

        foreach ([$parts['pass'] ?? '', $parts['fragment'] ?? ''] as $secret) {
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }

            $key = strstr($pair, '=', before_needle: true);
            $value = $key === false ? $pair : substr($pair, strlen($key) + 1);

            if ($value !== '') {
                $secrets[] = $value;
            }
        }

        // A value the URL carries percent-encoded can reach the log decoded,
        // and the other way round; both spellings have to go. foreach walks a
        // copy, so a variant appended here is not itself decoded again.
        foreach ($secrets as $secret) {
            $variant = rawurldecode($secret);

            if ($variant !== $secret) {
                $secrets[] = $variant;
            }
        }

        // Longest first: a secret that is a prefix of another would otherwise
        // replace its head and leave the tail of the longer one in the log.
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
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
