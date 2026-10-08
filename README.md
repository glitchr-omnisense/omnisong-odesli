# omnisong/odesli

Odesli (song.link) for [glitchr/omnisong](https://github.com/glitchr-studio/omnisong): one link -
a URL on any platform, or a platform's own id - to the same song or album on every other one,
through the application's HTTP client.

```php
$odesli = (new OdesliCatalogFactory($http))->create(['api_key' => '...', 'country' => 'DE']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `glitchr/omnisong` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnisong:
    catalogs:
        odesli:
            factory: odesli
            options:
                api_key: '%env(default::ODESLI_API_KEY)%'   # asked from developers@song.link
                country: 'DE'                               # userCountry: the store the links point at
                base_uri: 'https://api.song.link/v1-alpha.1/'
```

`links()` reads `linksByPlatform` (Spotify, Apple Music, iTunes, YouTube, YouTube Music, Deezer,
Tidal, Amazon Music, Amazon, SoundCloud, Napster, Pandora, Anghami, Boomplay, Audiomack, Audius,
Bandcamp), each link with the platform's own id; `release()` gives the title, the artist, the cover
and those links - no tracks, Odesli has none; `releases()` is not supported. Odesli reads neither a
UPC nor an ISRC (`NotSupportedException`): behind `omnisong/itunes` the aggregator comes back with
the iTunes id the release was found under, and Odesli is asked by that
(`?platform=itunes&type=album&id=...`).

What it answers: unknown (404) is an empty `PlatformLinks` / `null`; 429 is a
`RateLimitedException` with `Retry-After`; 5xx, a transport error, and a missing or refused key
(401, 403) are an `UnavailableException` - the aggregator skips Odesli and says so in
`$incomplete`, so a site does not cache a half answer; any other 4xx is a `ProviderException`.

The key: song.link's keyless access is withdrawn - a call without one answers `401
PUBLIC_API_ACCESS_DEPRECATED` (seen 2026-10-03). Ask developers@song.link for a key and set it as
`api_key` (`ODESLI_API_KEY`); without it this catalogue is skipped and named in `$incomplete`, and
the release still comes with iTunes' tracks and Apple's links.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
