<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxWebhooksBridge\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3OutboxWebhooksBridge\UrlMasker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UrlMasker::class)]
final class UrlMaskerTest
{
    #[DataProvider('maskProvider')]
    public function masksTheCredentialCarryingParts(string $url, string $expected): void
    {
        Assert::same(UrlMasker::mask($url), $expected);
    }

    public static function maskProvider(): iterable
    {
        yield 'credential-free url is untouched' => [
            'https://hooks.example.com/events',
            'https://hooks.example.com/events',
        ];
        yield 'query value masked, key kept' => [
            'https://hooks.example.com/events?token=s3cret',
            'https://hooks.example.com/events?token=***',
        ];
        yield 'every query value masked' => [
            'https://h.example.com/e?a=1&b=2',
            'https://h.example.com/e?a=***&b=***',
        ];
        yield 'bare query value masked whole' => [
            'https://h.example.com/e?s3cret',
            'https://h.example.com/e?***',
        ];
        yield 'empty pair skipped' => [
            'https://h.example.com/e?a=1&&b=2',
            'https://h.example.com/e?a=***&b=***',
        ];
        yield 'userinfo password masked' => [
            'https://user:p4ss@h.example.com/e',
            'https://user:***@h.example.com/e',
        ];
        yield 'user without password keeps no placeholder' => [
            'https://user@h.example.com/e',
            'https://user@h.example.com/e',
        ];
        yield 'fragment masked' => [
            'https://h.example.com/e#s3cret',
            'https://h.example.com/e#***',
        ];
        yield 'port survives' => [
            'https://h.example.com:8443/e?k=v',
            'https://h.example.com:8443/e?k=***',
        ];
        yield 'relative url keeps its path' => [
            '/relative/path?k=v',
            '/relative/path?k=***',
        ];
        yield 'unparsable url is replaced entirely' => [
            'https://:8443',
            '***',
        ];
    }

    /**
     * The reason the class exists: whatever the endpoint carries as a secret —
     * in the query string, in the userinfo component or in the fragment — must
     * not survive into the message the worker logs. Host and scheme must
     * survive, or the log line stops being diagnosable.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function secretsNeverSurviveAndTheHostAlwaysDoes(string $host, string $token, string $placement, array $query): void
    {
        // Each placement must actually be exercised; a uniform draw over three
        // gives ~33% each, so a third of the floor cannot be tripped by a seed.
        Classify::cover($placement === 'query', 'secret in query', 15.0);
        Classify::cover($placement === 'userinfo', 'secret in userinfo', 15.0);
        Classify::cover($placement === 'fragment', 'secret in fragment', 15.0);
        Classify::when($query !== [], 'extra query parameters present');

        $pairs = [];

        foreach ($query as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        if ($placement === 'query') {
            $pairs[] = 'token=' . $token;
        }

        $url = 'https://'
            . ($placement === 'userinfo' ? 'svc:' . $token . '@' : '')
            . $host . '.example.com/hooks'
            . ($pairs === [] ? '' : '?' . implode('&', $pairs))
            . ($placement === 'fragment' ? '#' . $token : '');

        $masked = UrlMasker::mask($url);

        Assert::false(str_contains($masked, $token));
        Assert::true(str_contains($masked, $host . '.example.com'));
        Assert::true(str_starts_with($masked, 'https://'));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function secretsNeverSurviveAndTheHostAlwaysDoesGenerators(): array
    {
        return [
            // The alphabets are disjoint from the token format on purpose: an
            // underscore cannot appear in a host label or a query key, so a
            // surviving part can never be mistaken for the leaked token.
            'host' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', minLength: 1, maxLength: 20),
            'token' => Gen::stringMatching('^sk_[a-z0-9]{12,24}$'),
            'placement' => Gen::elements(['query', 'userinfo', 'fragment']),
            'query' => Gen::dictOf(
                Gen::stringFrom('abcdefghijklmnopqrstuvwxyz', minLength: 1, maxLength: 8),
                Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789', minLength: 0, maxLength: 12),
                maxSize: 4,
            ),
        ];
    }

    /**
     * Masking a masked URL must be a no-op: the failure path can compose a
     * message out of an already-masked endpoint, and a second pass must not
     * degrade a URL that is already safe.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function maskingIsIdempotent(string $url): void
    {
        $once = UrlMasker::mask($url);

        Assert::same(UrlMasker::mask($once), $once);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function maskingIsIdempotentGenerators(): array
    {
        return [
            'url' => Gen::frequency([
                [1, Gen::url()],
                [2, Gen::stringMatching('^https://[a-z]{3,8}\.example\.(com|dev)(:[0-9]{2,4})?/hooks(\?[a-z]{1,6}=[a-z0-9]{1,10})?(#[a-z0-9]{1,8})?$')],
                [2, Gen::stringMatching('^https://[a-z]{2,6}:[a-z0-9]{4,12}@[a-z]{3,8}\.example\.com/hooks$')],
            ]),
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function maskingIsIdempotentExamples(): iterable
    {
        yield 'already masked query' => ['https://h.example.com/hooks?token=***'];
        yield 'already masked userinfo' => ['https://user:***@h.example.com/hooks'];
        yield 'already masked fragment' => ['https://h.example.com/hooks#***'];
        yield 'the placeholder itself' => ['***'];
        yield 'unparsable url' => ['https://:8443'];
        yield 'empty string' => [''];
    }

    /**
     * The masker runs inside a failure path, where the URL is whatever the
     * endpoint provider stored — it must never add a second failure of its own.
     */
    #[Property(runs: 200, timeoutMs: 1000, auto: true)]
    public function maskingNeverThrowsAndNeverReturnsAnEmptyString(string $url): void
    {
        Assert::true(UrlMasker::mask($url) !== '');
    }
}
