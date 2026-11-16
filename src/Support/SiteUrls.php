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

    /** The path a site's sitemap routes sit under, without slashes, e.g. '' or 'de'. */
    public static function pathPrefix(Site $site): string
    {
        return trim(parse_url(self::baseUrl($site), PHP_URL_PATH) ?? '', '/');
    }

    public static function sitemapUrl(Site $site, string $filename = 'sitemap.xml'): string
    {
        if ($filename !== 'sitemap.xml' && ! preg_match('/^sitemap-[A-Za-z0-9_-]+\.xml$/D', $filename)) {
            throw new InvalidArgumentException('Invalid sitemap filename: '.$filename);
        }

        return self::baseUrl($site).'/'.$filename;
    }
}
