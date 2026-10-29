<?php

namespace Omnisong\Odesli\Tests;

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
 * Fixtures/links-album.json: "Perspectives Concertantes" (Anaëlle Tourret) in
 * the API's v1-alpha.1 shape, written from what album.link/i/1793146044 showed
 * on 2026-10-03 - the API itself answers 401 without a key.
 */
final class OdesliCatalogTest extends TestCase
{
    private const ALBUM = 'https://music.apple.com/de/album/perspectives-concertantes/1793146044';

    /** @var list<array<string, string>> the query of each call */
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

        self::assertSame(['apple_music', 'tidal', 'amazon_music', 'itunes', 'amazon'], array_keys($links->toArray()), 'in the order a site shows them; Yandex is not one of ours');
        self::assertSame('https://listen.tidal.com/album/414051173', $links->get(Platform::TIDAL)->url);
        self::assertSame('414051173', $links->get(Platform::TIDAL)->id, 'the platform\'s own id');
        self::assertSame('B0DV5P8V78', $links->get(Platform::AMAZON_MUSIC)->id);
        self::assertSame('1793146044', $links->get(Platform::APPLE_MUSIC)->id);
        self::assertSame('DE', $links->get(Platform::APPLE_MUSIC)->country);
        self::assertSame(['url' => self::ALBUM, 'userCountry' => 'DE', 'key' => 'k_test'], $this->calls[0]);
    }

    public function testAPlatformsIdIsAskedByPlatformTypeAndId(): void
    {
        $catalog = $this->catalog();
        $links = $catalog->links(Reference::id(Platform::ITUNES, '1793146044', Reference::ALBUM, 'FR'));

        self::assertCount(5, $links);
        self::assertSame(['platform' => 'itunes', 'type' => 'album', 'id' => '1793146044', 'userCountry' => 'FR'], $this->calls[0], 'no key: none sent');
    }

    public function testTheReleaseIsItsTitleArtistCoverAndLinksWithoutTracks(): void
    {
        $catalog = $this->catalog();
        $release = $catalog->release(Reference::url(self::ALBUM));

        self::assertSame('Perspectives Concertantes', $release->title);
        self::assertStringStartsWith('Anaelle Tourret, NDR Elbphilharmonie Orchestra', $release->artist);
        self::assertStringEndsWith('/cover.jpg/512x512bb.jpg', $release->coverUrl);
        self::assertSame(Release::ALBUM, $release->type);
        self::assertSame([], $release->tracks);
        self::assertSame('1793146044', $release->ids['itunes'], 'what the next catalogue can be asked by');
        self::assertTrue($release->links->has(Platform::TIDAL));

        $catalog->links(Reference::url(self::ALBUM));
        self::assertCount(1, $this->calls, 'asked once: the release and its links are one answer');
    }

    public function testNeitherAUpcNorAnIsrcIsRead(): void
    {
        $catalog = $this->catalog();
        foreach ([Reference::upc('4015372820954'), Reference::isrc('DEAR41234567'), Reference::id(Platform::QOBUZ, 'abc')] as $reference) {
            try {
                $catalog->links($reference);
                self::fail('NotSupportedException expected for '.$reference);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);

        $this->expectException(NotSupportedException::class);
        $catalog->releases('Anaëlle Tourret');
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
}
