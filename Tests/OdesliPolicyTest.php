<?php

namespace Omnisong\Odesli\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Model\Reference;
use Omnisong\Odesli\OdesliCatalog;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Odesli's declared policy (policy.json, as its pages read on 2026-11-10)
 * and the waits it asks for: a 429's Retry-After and a 503's hold the next
 * calls with nothing sent; an answer held in memory costs no request.
 */
final class OdesliPolicyTest extends TestCase
{
    private const ALBUM = 'https://music.apple.com/de/album/perspectives-concertantes/1793146044';

    private int $sent = 0;

    /**
     * @param list<MockResponse>                    $responses
     * @param array{policy?: array<string, mixed>} $entry
     */
    private function registry(array $responses, array $entry = []): Registry
    {
        $http = new MockHttpClient(function () use (&$responses): MockResponse {
            ++$this->sent;

            return array_shift($responses) ?? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/links-album.json'));
        });

        return new Registry([new OdesliCatalogFactory($http)], ['odesli' => ['factory' => 'odesli', 'options' => ['api_key' => 'k_test']] + $entry]);
    }

    public function testThePolicyFileSaysAKeyAndLeavesTheLimitsUnknown(): void
    {
        $policy = UsagePolicy::fromFile(OdesliCatalog::POLICY);
        $registry = $this->registry([]);

        self::assertSame('odesli', $policy->provider);
        self::assertFalse($policy->knowsLimits(), 'the keyed limit is agreed, not published');
        self::assertTrue($policy->requirements->key);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('unknown', $policy->rights->commercial->value);
        self::assertEquals($policy, $registry->policy('odesli'));
        self::assertStringContainsString('"omnisense": {"policy": "policy.json"}', (string) file_get_contents(__DIR__.'/../composer.json'), 'declared for omnisense-policies');
    }

    public function testA429sRetryAfterHoldsTheNextCallsWithNothingSent(): void
    {
        $registry = $this->registry([new MockResponse('{"statusCode":429}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])]);

        foreach (['get', 'guarded'] as $i => $access) {
            try {
                $registry->{$access}('odesli')->links(Reference::url(self::ALBUM.'?'.$i));
                self::fail('Rate limited.');
            } catch (RateLimitedException $e) {
                self::assertSame('odesli', $e->catalog);
                self::assertGreaterThanOrEqual(29, $e->retryAfter);
            }
        }
        self::assertSame(1, $this->sent, 'the second call held, not sent');
    }

    public function testA503sRetryAfterHoldsTheNextCalls(): void
    {
        $registry = $this->registry([new MockResponse('', ['http_code' => 503, 'response_headers' => ['retry-after' => '120']])]);
        $catalog = $registry->guarded('odesli');

        foreach ([1, 2] as $i) {
            try {
                $catalog->links(Reference::url(self::ALBUM.'?'.$i));
                self::fail('Down.');
            } catch (\Omnisong\Exception\UnavailableException) {
            }
        }
        self::assertSame(1, $this->sent);
    }

    public function testAnAnswerHeldInMemoryCostsNoRequest(): void
    {
        $registry = $this->registry([], ['policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute']]]]);
        $catalog = $registry->guarded('odesli');

        $catalog->links(Reference::url(self::ALBUM));
        $release = $catalog->release(Reference::url(self::ALBUM));

        self::assertNotNull($release);
        self::assertSame(1, $this->sent, 'links() and release() of one recording: one answer');
        self::assertSame(1, $registry->guard('odesli')->remaining('links'), 'counted once');
    }

    public function testAUpcItCannotReadCostsNothing(): void
    {
        $registry = $this->registry([], ['policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute']]]]);

        try {
            $registry->guarded('odesli')->links(Reference::upc('4015372820954'));
            self::fail('Odesli reads no UPC.');
        } catch (\Omnisong\Exception\NotSupportedException) {
        }
        self::assertSame(0, $this->sent);
        self::assertSame(1, $registry->guard('odesli')->remaining('links'));
    }
}
