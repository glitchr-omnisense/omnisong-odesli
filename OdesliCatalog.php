<?php

namespace Omnisong\Odesli;

use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * song.link's API (v1-alpha.1): given a URL on any platform, or a platform's
 * own id, the same song or album everywhere else. It knows where a recording
 * is, not what is on it: no tracks, no previews, no list of an artist's
 * releases - and it reads neither a UPC nor an ISRC (the aggregator comes
 * back with the id another catalogue found).
 */
final class OdesliCatalog implements CatalogInterface
{
    public const BASE_URI = 'https://api.song.link/v1-alpha.1/';

    /** Our platforms as the API's "platform" parameter names them. */
    private const PLATFORMS = [
        'spotify' => 'spotify', 'itunes' => 'itunes', 'apple_music' => 'appleMusic', 'youtube' => 'youtube', 'youtube_music' => 'youtubeMusic',
        'deezer' => 'deezer', 'tidal' => 'tidal', 'amazon_music' => 'amazonMusic', 'amazon' => 'amazonStore', 'soundcloud' => 'soundcloud',
        'napster' => 'napster', 'pandora' => 'pandora', 'anghami' => 'anghami', 'boomplay' => 'boomplay', 'audiomack' => 'audiomack', 'audius' => 'audius',
    ];

    /** @var array<string, array<string, mixed>|null> what was answered already, by query: links() and release() ask the same thing */
    private array $answers = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $apiKey = null,
        private readonly ?string $country = null,
        private readonly string $baseUri = self::BASE_URI,
    ) {
    }

    public function getName(): string
    {
        return 'odesli';
    }

    public function links(Reference $reference): PlatformLinks
    {
        $answer = $this->ask($reference);
        if (null === $answer) {
            return PlatformLinks::none();
        }
        $links = [];
        foreach ((array) ($answer['linksByPlatform'] ?? []) as $key => $link) {
            $platform = Platform::fromOdesli((string) $key);
            if (null === $platform || !\is_array($link) || empty($link['url'])) {
                continue;
            }
            $unique = (string) ($link['entityUniqueId'] ?? '');
            $id = $answer['entitiesByUniqueId'][$unique]['id'] ?? (str_contains($unique, '::') ? substr($unique, strpos($unique, '::') + 2) : null);
            $links[] = new PlatformLink($platform, (string) $link['url'], null !== $id ? (string) $id : null, null, isset($link['country']) ? (string) $link['country'] : null);
        }

        return new PlatformLinks($links);
    }

    public function release(Reference $reference): ?Release
    {
        $answer = $this->ask($reference);
        $entity = $answer['entitiesByUniqueId'][$answer['entityUniqueId'] ?? ''] ?? null;
        if (!\is_array($entity) || empty($entity['title'])) {
            return null;
        }
        $links = $this->links($reference);
        $ids = [];
        foreach ($links as $platform => $link) {
            if (null !== $link->id) {
                $ids[$platform] = $link->id;
            }
        }

        return new Release(
            (string) $entity['title'],
            isset($entity['artistName']) ? (string) $entity['artistName'] : null,
            coverUrl: isset($entity['thumbnailUrl']) ? (string) $entity['thumbnailUrl'] : null,
            type: 'song' === ($entity['type'] ?? null) ? Release::SINGLE : Release::ALBUM,
            links: $links,
            ids: $ids,
        );
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        throw NotSupportedException::operation('odesli', 'list an artist\'s releases');
    }

    /**
     * @return array<string, mixed>|null null when Odesli does not know the recording
     *
     * @throws NotSupportedException a UPC, an ISRC, or the id of a platform Odesli does not take
     * @throws UnavailableException  unreachable, down, rate limited, or the key refused
     */
    private function ask(Reference $reference): ?array
    {
        if (null !== $reference->url) {
            $query = ['url' => $reference->url];
        } elseif ($reference->platform && null !== $reference->id && isset(self::PLATFORMS[$reference->platform->value])) {
            $query = ['platform' => self::PLATFORMS[$reference->platform->value], 'type' => $reference->type ?? Reference::ALBUM, 'id' => $reference->id];
        } else {
            throw NotSupportedException::reference('odesli', $reference);
        }
        if ($country = $reference->country ?? $this->country) {
            $query['userCountry'] = strtoupper($country);
        }
        $memo = http_build_query($query);
        if (\array_key_exists($memo, $this->answers)) {
            return $this->answers[$memo];
        }
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $query['key'] = $this->apiKey;
        }

        try {
            $response = $this->http->request('GET', rtrim($this->baseUri, '/').'/links', ['query' => $query, 'headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            throw new UnavailableException('odesli', $e->getMessage(), null, $e);
        }
        $answer = json_decode($body, true);
        $code = \is_array($answer) ? (string) ($answer['code'] ?? '') : '';

        if (429 === $status) {
            throw new RateLimitedException('odesli', self::retryAfter($headers['retry-after'][0] ?? null));
        }
        if (404 === $status || (400 === $status && 'could_not_resolve_entity' === $code)) {
            return $this->answers[$memo] = null;
        }
        if (401 === $status || 403 === $status) {
            // Not "unknown": the links are there, the key is not. Skipped, never cached as an empty answer.
            throw new UnavailableException('odesli', \sprintf('HTTP %d%s: %s', $status, $code ? ' '.$code : '', $this->apiKey ? 'the API key was refused' : 'a key may now be required (options: api_key, ODESLI_API_KEY)'), $status);
        }
        if ($status >= 500) {
            throw new UnavailableException('odesli', \sprintf('HTTP %d', $status), $status);
        }
        if ($status >= 400) {
            throw new ProviderException('odesli', \sprintf('HTTP %d%s', $status, $code ? ' '.$code : ''), $status);
        }
        if (!\is_array($answer)) {
            throw new UnavailableException('odesli', 'an answer that is not JSON', $status);
        }

        return $this->answers[$memo] = $answer;
    }

    /** Retry-After in seconds, or as an HTTP date (RFC 9110 §10.2.3): the seconds until then, at least 1. */
    private static function retryAfter(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int) $value;
        }
        $at = strtotime($value);

        return false === $at ? null : max(1, $at - time());
    }

}
