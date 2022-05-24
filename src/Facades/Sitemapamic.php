<?php

namespace MityDigital\Sitemapamic\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string addDynamicRoutes(array $routes)
 * @method static string getDynamicRoutes() : array
 *
 * @see MityDigita\Sitemapamic\Support\Sitemapamic
 */
class Sitemapamic extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \MityDigital\Sitemapamic\Support\Sitemapamic::class;
    }
}
