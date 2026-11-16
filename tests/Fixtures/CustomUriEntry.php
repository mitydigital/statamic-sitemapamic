<?php

namespace MityDigital\Sitemapamic\Tests\Fixtures;

use Statamic\Entries\Entry;

/** An entry class that changes its URI, to prove the sitemap asks the entry rather than rebuilding the route. */
class CustomUriEntry extends Entry
{
    public function routableUri()
    {
        $uri = parent::routableUri();

        // uri() caches what this returns, so the suffix is only ever added once
        return $uri === null ? null : $uri.'/custom';
    }
}
