<?php

namespace MityDigital\Sitemapamic\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string addDynamicRoutes($routesClosure)
 * @method static getDynamicRoutes() : array
 * @method static getLoaders() : array
 * @method static getCacheKey() : string|mixed
 * @method static getCacheKeys() : array
 * @method static array clearCache(array $keys) : bool
 * @method static hasDynamicRoutes() : bool
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
