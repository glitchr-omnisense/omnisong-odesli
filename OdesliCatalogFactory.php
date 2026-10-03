<?php

namespace Omnisong\Odesli;

use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Config;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Odesli (song.link): one link to the same song or album on every platform.
 *
 *   options:
 *     api_key: '%env(default::ODESLI_API_KEY)%'   # from developers@song.link; without one the API answers 401 since the public access was withdrawn
 *     country: 'DE'                               # userCountry: the store the links point at (default: Odesli's, US)
 *     base_uri: 'https://api.song.link/v1-alpha.1/'
 */
final class OdesliCatalogFactory extends CatalogFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnisong.factory_name' => 'odesli',
            'omnisong.required_options' => [],
            'api_key' => null,
            'country' => null,
            'base_uri' => OdesliCatalog::BASE_URI,
        ]);
    }

    protected function build(Config $c): CatalogInterface
    {
        return new OdesliCatalog($this->http ?? HttpClient::create(), $c['api_key'] ?: null, $c['country'] ?: null, (string) ($c['base_uri'] ?: OdesliCatalog::BASE_URI));
    }
}
