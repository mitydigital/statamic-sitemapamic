<?php

namespace MityDigital\Sitemapamic\Support;

use InvalidArgumentException;
use Statamic\Facades\URL;
use Statamic\Sites\Site;

class SiteUrls
{
    /** Relative site URLs belong to app.url, regardless of the current request. */
    public static function baseUrl(Site $site): string
    {
        $url = $site->url();
        if (str_starts_with($url, '/')) {
            $url = rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    public static function contentUrl(Site $site, string $uri): string
    {
        return URL::tidy(self::baseUrl($site).'/'.ltrim($uri, '/'));
    }

    public static function sitemapUrl(Site $site, string $filename = 'sitemap.xml'): string
    {
        $routeName = 'sitemapamic.'.$site->handle().'.';
        if ($filename === 'sitemap.xml') {
            $path = route($routeName.'index', [], false);
        } elseif (preg_match('/^sitemap-([A-Za-z0-9_-]+)\.xml$/D', $filename, $matches)) {
            $path = route($routeName.'show', ['sitemap' => $matches[1]], false);
        } else {
            throw new InvalidArgumentException('Invalid sitemap filename: '.$filename);
        }

        // route() would take the scheme from the request and drop the port, so use the site's own URL
        $base = parse_url(self::baseUrl($site));
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        return $origin.$path;
    }
}
