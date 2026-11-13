<?php

namespace Omnisong\Odesli\Tests;

use Omnisong\Exception\InvalidConfigException;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures/links-album.json: "Beethoven: Symphonies Nos. 5 & 7" (Wiener
 * Philharmoniker & Carlos Kleiber) in the API's v1-alpha.1 shape, written on
 * 2026-11-14 from what album.link/i/1644892939 showed (Apple) and Deezer's API
 * (album 361794007) - the API itself answers 401 without a key; its Yandex
 * entry is invented, a platform omnisong does not map.
 */
final class OdesliCatalogTest extends TestCase
{
    private const ALBUM = 'https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939';

    /** @var list<array<mixed>> the query of each call */
    private array $calls = [];

    /** @param array<string, mixed> $options */
    private function catalog(?MockResponse $response = null, array $options = []): \Omnisong\Catalog\CatalogInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $query;
            self::assertSame('GET', $method);
            self::assertSame('/v1-alpha.1/links', parse_url($url, \PHP_URL_PATH));

            return $response ?? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/links-album.json'), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return (new OdesliCatalogFactory($http))->create($options);
    }

    public function testAUrlIsTheSameAlbumOnEveryPlatformOdesliKnows(): void
    {
        $links = $this->catalog(null, ['api_key' => 'k_test', 'country' => 'de'])->links(Reference::url(self::ALBUM));

        self::assertSame(['apple_music', 'deezer', 'itunes'], array_keys($links->toArray()), 'in the order a site shows them; Yandex is not one of ours');
        self::assertSame('https://www.deezer.com/album/361794007', $links->get(Platform::DEEZER)?->url);
        self::assertSame('361794007', $links->get(Platform::DEEZER)->id, 'the platform\'s own id');
        self::assertSame('1644892939', $links->get(Platform::APPLE_MUSIC)?->id);
        self::assertSame('DE', $links->get(Platform::APPLE_MUSIC)->country);
        self::assertSame(['url' => self::ALBUM, 'userCountry' => 'DE', 'key' => 'k_test'], $this->calls[0]);
    }

    public function testAPlatformsIdIsAskedByPlatformTypeAndId(): void
    {
        $catalog = $this->catalog();
        $links = $catalog->links(Reference::id(Platform::ITUNES, '1644892939', Reference::ALBUM, 'FR'));

        self::assertCount(3, $links);
        self::assertSame(['platform' => 'itunes', 'type' => 'album', 'id' => '1644892939', 'userCountry' => 'FR'], $this->calls[0], 'no key: none sent');
    }

    public function testTheReleaseIsItsTitleArtistCoverAndLinksWithoutTracks(): void
    {
        $catalog = $this->catalog();
        $release = $catalog->release(Reference::url(self::ALBUM));

        self::assertNotNull($release);
        self::assertNotNull($release->links);
        self::assertSame('Beethoven: Symphonies Nos. 5 & 7', $release->title);
        self::assertStringStartsWith('Wiener Philharmoniker & Carlos Kleiber', (string) $release->artist);
        self::assertStringEndsWith('/22UMGIM89320.rgb.jpg/512x512bb.jpg', (string) $release->coverUrl);
        self::assertSame(Release::ALBUM, $release->type);
        self::assertSame([], $release->tracks);
        self::assertSame('1644892939', $release->ids['itunes'], 'what the next catalogue can be asked by');
        self::assertTrue($release->links->has(Platform::DEEZER));

        $catalog->links(Reference::url(self::ALBUM));
        self::assertCount(1, $this->calls, 'asked once: the release and its links are one answer');
    }

    public function testNeitherAUpcNorAnIsrcIsRead(): void
    {
        $catalog = $this->catalog();
        foreach ([Reference::upc('028948637751'), Reference::isrc('QZZZZ1234567'), Reference::id(Platform::QOBUZ, 'abc')] as $reference) {
            try {
                $catalog->links($reference);
                self::fail('NotSupportedException expected for '.$reference);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);

        $this->expectException(NotSupportedException::class);
        $catalog->releases('Carlos Kleiber');
    }

    public function testUnknownIsEmptyNotAnError(): void
    {
        $catalog = $this->catalog(new MockResponse('{"statusCode":404,"code":"could_not_resolve_entity"}', ['http_code' => 404]));

        self::assertCount(0, $catalog->links(Reference::url('https://open.spotify.com/album/unknown')));
        self::assertNull($catalog->release(Reference::url('https://open.spotify.com/album/unknown')));
    }

    public function testTooManyRequestsSayWhenToComeBack(): void
    {
        try {
            $this->catalog(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '42']]))->links(Reference::url(self::ALBUM));
            self::fail('RateLimitedException expected');
        } catch (RateLimitedException $e) {
            self::assertSame(42, $e->retryAfter);
            self::assertSame('odesli', $e->catalog);
        }
    }

    /** Retry-After may be an HTTP date (RFC 9110 §10.2.3): the seconds until then. */
    public function testARetryAfterDateIsTheSecondsUntilThen(): void
    {
        try {
            $this->catalog(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => gmdate(\DATE_RFC7231, time() + 120)]]))->links(Reference::url(self::ALBUM));
            self::fail('RateLimitedException expected');
        } catch (RateLimitedException $e) {
            self::assertGreaterThanOrEqual(118, $e->retryAfter);
            self::assertLessThanOrEqual(120, $e->retryAfter);
        }
    }

    public function testDownUnreachableOrWithoutItsKeyIsUnavailable(): void
    {
        $responses = [
            new MockResponse('Bad gateway', ['http_code' => 502]),
            new MockResponse('{"statusCode":401,"code":"PUBLIC_API_ACCESS_DEPRECATED"}', ['http_code' => 401]),
            new MockResponse('', ['error' => 'Could not resolve host']),
        ];
        foreach ($responses as $response) {
            try {
                $this->catalog($response)->links(Reference::url(self::ALBUM));
                self::fail('UnavailableException expected');
            } catch (UnavailableException $e) {
                self::assertNotInstanceOf(RateLimitedException::class, $e);
            }
        }
    }

    public function testTheKeylessRefusalNamesTheKey(): void
    {
        $this->expectException(UnavailableException::class);
        $this->expectExceptionMessage('[odesli] HTTP 401 PUBLIC_API_ACCESS_DEPRECATED: a key may now be required (options: api_key, ODESLI_API_KEY)');
        $this->catalog(new MockResponse('{"statusCode":401,"code":"PUBLIC_API_ACCESS_DEPRECATED"}', ['http_code' => 401]))->links(Reference::url(self::ALBUM));
    }

    /** An answer whose entityUniqueId is a list names no entity it can be read by: a ProviderException, not a TypeError. */
    public function testAnEntityUniqueIdThatIsAListIsAProviderException(): void
    {
        $answer = ['entityUniqueId' => ['ITUNES_ALBUM::1644892939'], 'entitiesByUniqueId' => ['ITUNES_ALBUM::1644892939' => ['id' => '1644892939', 'title' => 'Beethoven: Symphonies Nos. 5 & 7']], 'linksByPlatform' => []];
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('[odesli] ');
        $this->catalog(new MockResponse((string) json_encode($answer)))->release(Reference::url(self::ALBUM));
    }

    /** An api_key or a country that is a list is a misconfiguration the factory says, not a TypeError. */
    public function testAnApiKeyOrACountryThatIsAListIsAConfigError(): void
    {
        foreach (['api_key', 'country'] as $option) {
            try {
                $this->catalog(null, [$option => ['DE']]);
                self::fail('InvalidConfigException expected');
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($option, $e->getMessage());
            }
        }
    }

    public function testAnyOtherRefusalIsTheProvidersError(): void
    {
        try {
            $this->catalog(new MockResponse('{"statusCode":400,"code":"invalid_request"}', ['http_code' => 400]))->links(Reference::url(self::ALBUM));
            self::fail('ProviderException expected');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnavailableException::class, $e);
            self::assertSame(400, $e->status);
        }
    }

    /**
     * Odesli not reached: nothing of the key anywhere in what a log of the whole chain writes -
     * the messages, the previous exception (a copy of the HTTP client's, its class kept), the
     * traces' arguments when zend.exception_ignore_args is off.
     */
    public function testNoPartOfAnUnreachableChainHoldsTheKey(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: api.song.link for "%s".', $url));
        });
        try {
            (new OdesliCatalogFactory($http))->create(['api_key' => 'odesli-key-123'])->links(Reference::url(self::ALBUM));
            self::fail('no exception');
        } catch (\Omnisong\Exception\UnavailableException $e) {
            self::assertInstanceOf(\Symfony\Component\HttpClient\Exception\TransportException::class, $e->getPrevious());
            self::assertStringContainsString('key=***', $e->getMessage());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                self::assertFalse(str_contains($one->getMessage(), 'odesli-key-123'), $one->getMessage());
                self::assertFalse(str_contains(print_r($one->getTrace(), true), 'odesli-key-123'), 'a trace holds the key');
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
