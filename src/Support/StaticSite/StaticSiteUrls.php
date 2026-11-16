<?php

namespace MityDigital\Sitemapamic\Support\StaticSite;

use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;

class StaticSiteUrls
{
    public function __construct(private Sitemapamic $sitemapamic) {}

    /**
     * The sitemap files of every site the static site generator exports, rebuilt so they match the content
     * being generated. The generator requests every URL through its one base_url, so a site on another host
     * is left out rather than written over the root.
     *
     * @return list<string> paths relative to the generator's base_url, e.g. '/de/sitemap.xml'
     */
    public function urls(): array
    {
        $urls = [];
        foreach (Site::all() as $site) {
            if (! $this->isExported($site)) {
                continue;
            }

            $this->sitemapamic->markStale($site);
            $manifest = $this->sitemapamic->manifest($site);
            if ($manifest->index === null) {
                continue;
            }

            $prefix = SiteUrls::pathPrefix($site);
            $filenames = array_unique(['sitemap.xml', ...array_keys($manifest->files)]);
            foreach ($filenames as $filename) {
                $urls[] = '/'.ltrim($prefix.'/'.$filename, '/');
            }
        }

        return $urls;
    }

    private function isExported(StatamicSite $site): bool
    {
        $siteHost = parse_url($site->url(), PHP_URL_HOST);
        if ($siteHost === null) {
            return true;
        }

        $baseUrl = config('statamic.ssg.base_url') ?? config('app.url');

        return strcasecmp($siteHost, (string) parse_url((string) $baseUrl, PHP_URL_HOST)) === 0;
    }
}
