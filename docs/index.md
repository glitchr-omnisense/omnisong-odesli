# omnisong/odesli

Odesli (song.link) for [omnisense/omnisong](https://github.com/glitchr-omnisense/omnisong): one link
- a URL on any platform, or a platform's own id - to the same song or album on every other one. The
catalogue calls one endpoint of song.link's API, version `v1-alpha.1`:

| Operation | Endpoint | Query |
|---|---|---|
| links, release | `GET /links` | `url=<url>`, or `platform=<platform>&type=<song or album>&id=<id>`; `userCountry=<CC>` and `key=<api key>` when set |

on `https://api.song.link/v1-alpha.1/` (`Omnisong\Odesli\OdesliCatalog::BASE_URI`, the `base_uri`
option), with `Accept: application/json`. There is no sandbox.

Odesli knows where a recording is, not what is on it: no tracks, no previews, no list of an artist's
releases; and it reads neither a UPC nor an ISRC. Under the aggregator, behind `omnisong/itunes`, it
is asked by the iTunes id iTunes found ([the aggregator](https://github.com/glitchr-omnisense/omnisong/blob/1.x/docs/usage-catalog.md)).

## Installation

```sh
composer require omnisense/omnisong omnisong/odesli
```

Requires PHP 8.2, `omnisense/omnisong` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnisong\Bridge\Symfony\OmnisongBundle`, the factory is registered as soon as the
package is installed.

## Credentials

An **API key**, asked of song.link by mail (developers@song.link). song.link's keyless access is
withdrawn: a call without a key answers `401 PUBLIC_API_ACCESS_DEPRECATED` (seen 2026-10-03), which
this catalogue reports as an `UnavailableException` naming the key. The convention in these pages
and in the harness is the `ODESLI_API_KEY` environment variable.

## Options

From `OdesliCatalogFactory`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string or null | `null` | no (in practice yes, see above) | sent as `key`; empty is null and then not sent |
| `country` | string or null | `null` | no | sent as `userCountry` (upper case): the store the links point at. Null: song.link's own default (US) |
| `base_uri` | string | `https://api.song.link/v1-alpha.1/` | no | the API's root. Empty or null is the default |

A `Reference`'s own `country` overrides `country` for that call. No other option is read.

## Capabilities

| Call | Supported |
|---|---|
| `links()` by a URL on any platform | yes (`url=`) |
| `links()` by `Reference::id()` of one of 16 platforms | yes (`platform=&type=&id=`): `spotify`, `itunes`, `appleMusic`, `youtube`, `youtubeMusic`, `deezer`, `tidal`, `amazonMusic`, `amazonStore` (for `amazon`), `soundcloud`, `napster`, `pandora`, `anghami`, `boomplay`, `audiomack`, `audius` |
| `links()` / `release()` by a UPC, an ISRC, or the id of another platform (`bandcamp`, `qobuz`...) | no: `NotSupportedException`, before any call |
| `release()` | the title, the artist, the cover and the links; no tracks |
| `releases()` | no: `NotSupportedException` |

## Behaviour

**The query.** A reference with a URL is asked by its URL, whatever its platform. One with a
platform and an id is asked by `platform`, `type` (the reference's type, `album` when it has none)
and `id`. `userCountry` is the reference's country, else the `country` option, in upper case. The key
is added last, and is not part of what the catalogue remembers.

**Links.** Each entry of `linksByPlatform` whose key `Platform::fromOdesli()` knows becomes a
`PlatformLink`: its `url`, its `country` when given, and the platform's id - the `id` of the entity
the entry names in `entitiesByUniqueId`, else what follows `::` in its `entityUniqueId`. The 17 keys
read are `spotify`, `appleMusic`, `itunes`, `youtube`, `youtubeMusic`, `deezer`, `tidal`,
`amazonMusic`, `amazonStore`, `soundcloud`, `napster`, `pandora`, `anghami`, `boomplay`,
`audiomack`, `audius` and `bandcamp`; any other key (`yandex` in the recorded answer) is skipped, as
is an entry without a URL. The URLs are song.link's (`geo.music.apple.com/...` for Apple).

**Release.** The entity the answer is about (`entityUniqueId`): its `title`, `artistName`,
`thumbnailUrl` as the cover, `type: single` when the entity is a `song`, else `album`; the links
above, and `ids` from them (platform value => id). No label, UPC, date, genre or tracks. An entity
without a title is `null`.

**Memory.** Each answer (null included) is kept in the object by its query: `release()` after
`links()` of the same reference calls nothing.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnisong\Model\Reference;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Platform;
use Symfony\Component\HttpClient\HttpClient;

$odesli = (new OdesliCatalogFactory(HttpClient::create()))->create(['api_key' => getenv('ODESLI_API_KEY'), 'country' => 'DE']);

$reference = Reference::url('https://music.apple.com/de/album/perspectives-concertantes/1793146044');
foreach ($odesli->links($reference) as $value => $link) {
    printf("  %-13s %-28s id=%s\n", $value, $link->url, $link->id);
}
$release = $odesli->release($reference);
printf("%s | %s | %s | %d tracks | %s\n", $release->title, $release->artist, $release->type, count($release->tracks), $release->coverUrl);
$odesli->links(Reference::id(Platform::SPOTIFY, '4aawyAB9vmqN3uQ7FjRGTy'));
```

Run on 2026-10-26 on a `MockHttpClient` serving the package's fixture (`Tests/Fixtures/links-album.json`,
written from what album.link showed on 2026-10-03) instead of `HttpClient::create()`, with a test
key; the `>` lines are the mock's log of each request:

```
> https://api.song.link/v1-alpha.1/links?url=https://music.apple.com/de/album/perspectives-concertantes/1793146044&userCountry=DE&key=test-key
  apple_music   https://geo.music.apple.com/de/album/_/1793146044?mt=1&app=music&ls=1 id=1793146044
  tidal         https://listen.tidal.com/album/414051173 id=414051173
  amazon_music  https://music.amazon.com/albums/B0DV5P8V78 id=B0DV5P8V78
  itunes        https://geo.music.apple.com/de/album/_/1793146044?mt=1&app=itunes&ls=1 id=1793146044
  amazon        https://amazon.com/dp/B0DV5P8V78 id=B0DV5P8V78
Perspectives Concertantes | Anaelle Tourret, NDR Elbphilharmonie Orchestra, Vasily Petrenko, Stuttgarter Kammerorchester & Bar Avni | album | 0 tracks | https://is1-ssl.mzstatic.com/image/thumb/Music221/v4/03/84/2e/03842ead-96bc-0e38-6446-f61a9bfde85c/cover.jpg/512x512bb.jpg
> https://api.song.link/v1-alpha.1/links?platform=spotify&type=album&id=4aawyAB9vmqN3uQ7FjRGTy&userCountry=DE&key=test-key
```

`release()` sent nothing: it is the links' answer, remembered. In Symfony:

```yaml
omnisong:
    catalogs:
        odesli:
            factory: odesli
            options:
                api_key: '%env(default::ODESLI_API_KEY)%'
                country: 'DE'
```

## Errors

| Answer | Exception |
|---|---|
| 404, 400 `could_not_resolve_entity` | none: unknown, empty links / `null` |
| 401, 403 | `UnavailableException`: `[odesli] HTTP 401 PUBLIC_API_ACCESS_DEPRECATED: a key may now be required (options: api_key, ODESLI_API_KEY)`, or `...: the API key was refused` when a key was sent. Never remembered as an answer |
| 429 | `RateLimitedException` (`retryAfter` from `Retry-After`) |
| 5xx | `UnavailableException` |
| other 4xx | `ProviderException` (`[odesli] HTTP <status> <code>`) |
| 2xx that is not JSON | `UnavailableException` (`an answer that is not JSON`) |
| transport error, timeout | `UnavailableException`, the client's exception as previous |
| a reference it cannot read, `releases()` | `NotSupportedException`, before any call |

Under the aggregator, every `UnavailableException` skips Odesli and names it in `$incomplete`.

> [!WARNING]
> Known defect: `Retry-After` written as an HTTP date is read as `retryAfter = 0`, where the seconds until that date (or null) are expected.

The package's `composer.json` description says it reads "an ISRC, a UPC": it reads neither (above).

## Verified, and not

The package's tests (`Tests/OdesliCatalogTest.php`, 9 tests) pass (2026-10-26, PHP 8.4) on one
fixture written from album.link's page for album 1793146044, not from an API answer: song.link's
API was never answered with a key for this package (it answers 401 without one). A URL gives the
album on every platform; an id is asked by platform, type and id; the release has no tracks;
neither a UPC nor an ISRC is read; unknown is empty; 429 says when to come back; down, unreachable
or keyless is unavailable, naming the key; any other refusal is the provider's error.

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Odesli (song.link). Whoever runs it complies with [Odesli's terms](https://odesli.co/terms) (the key,
its limits, attribution); see the README's Responsibility section.
