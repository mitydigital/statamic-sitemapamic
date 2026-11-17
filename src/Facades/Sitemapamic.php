<?php

namespace MityDigital\Sitemapamic\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapDocument;
use MityDigital\Sitemapamic\Support\Sitemapamic as Support;
use Statamic\Sites\Site;

/**
 * @method static void addDynamicRoutes(Closure $routes, array|string|null $sites = null)
 * @method static string url(Site|string|null $site = null, string $filename = 'sitemap.xml')
 * @method static Manifest manifest(Site|string|null $site = null)
 * @method static void clearCache(Site|string|null $site = null)
 * @method static void markStale(Site|string|null $site = null)
 * @method static SitemapDocument|null document(Site $site, string $filename, array $etags = [])
 *
 * @see Support
 */
class Sitemapamic extends Facade
{
    // the service is scoped, so resolve it from the container on every call
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return Support::class;
    }
}
