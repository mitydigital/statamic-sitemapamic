<?php

namespace MityDigital\Sitemapamic\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string addDynamicRoutes($routesClosure)
 * @method static string getDynamicRoutes() : array
 * @method static array getLoaders() : array
 * @method static string|mixed getCacheKey() : string|mixed
 * @method static array getCacheKeys() : array
 * @method static array clearCache(array $keys) : bool
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
