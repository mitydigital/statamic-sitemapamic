<?php

namespace MityDigital\Sitemapamic\Support\Content;

use Closure;
use Generator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Enums\ChangeFrequency;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Sites\Site;

class DynamicRoutes
{
    /** @var list<array{callback: Closure, sites: ?list<string>}> */
    private array $registrations = [];

    /** @param list<string>|string|null $sites */
    public function add(Closure $callback, array|string|null $sites = null): void
    {
        $selectedSites = is_string($sites) ? [$sites] : $sites;
        foreach ($selectedSites ?? [] as $siteHandle) {
            if (! is_string($siteHandle) || trim($siteHandle) === '') {
                throw new InvalidArgumentException('Dynamic route sites must contain nonempty site handles.');
            }
        }

        // null, or '*' anywhere in the list, means every site
        if ($selectedSites !== null && in_array('*', $selectedSites, true)) {
            $selectedSites = null;
        }

        $this->registrations[] = ['callback' => $callback, 'sites' => $selectedSites];
    }

    /** @return list<Source> */
    public function sources(Site $site): array
    {
        // every registration for the site is read as one source
        foreach ($this->registrations as $registration) {
            if ($this->appliesToSite($registration['sites'], $site)) {
                return [new Source($site->handle(), dynamic: true)];
            }
        }

        return [];
    }

    /** @return Generator<int, SitemapamicUrl> */
    public function urls(Site $site): Generator
    {
        foreach ($this->registrations as $number => $registration) {
            if (! $this->appliesToSite($registration['sites'], $site)) {
                continue;
            }

            // yield each URL so the keys run on across registrations
            foreach ($this->registrationUrls($number, $registration['callback'], $site) as $url) {
                yield $url;
            }
        }
    }

    /** @return Generator<int, SitemapamicUrl> */
    private function registrationUrls(int $number, Closure $callback, Site $site): Generator
    {
        $urls = $callback($this->siteWithAbsoluteUrl($site));
        if (! is_iterable($urls)) {
            throw new InvalidArgumentException('Dynamic route registration '.$number.' must return an iterable.');
        }

        foreach ($urls as $url) {
            if (! $url instanceof SitemapamicUrl) {
                throw new InvalidArgumentException('Dynamic route registration '.$number.' must yield SitemapamicUrls.');
            }

            yield $this->validated($url);
        }
    }

    /** @param list<string>|null $selectedSites */
    private function appliesToSite(?array $selectedSites, Site $site): bool
    {
        return $selectedSites === null || in_array($site->handle(), $selectedSites, true);
    }

    // give the callback a site whose URL is absolute, even when the site's URL is relative
    private function siteWithAbsoluteUrl(Site $site): Site
    {
        $config = array_replace($site->rawConfig(), ['url' => SiteUrls::baseUrl($site)]);

        return new Site($site->handle(), $config, $site->isDefault());
    }

    private function validated(SitemapamicUrl $url): SitemapamicUrl
    {
        $this->ensureAbsoluteUrl($url->loc);
        foreach ($url->alternates as $alternate) {
            $this->ensureAbsoluteUrl($alternate['href'] ?? null);
        }

        // copy the URL so the callback's object is never changed
        $url = clone $url;

        // an invalid change frequency or priority is left out
        if (ChangeFrequency::fromValue($url->changefreq) === null) {
            $url->changefreq = null;
        }

        if ($url->priority !== null && ($url->priority < 0 || $url->priority > 1 || is_nan($url->priority))) {
            $url->priority = null;
        }

        return $url;
    }

    private function ensureAbsoluteUrl(mixed $url): void
    {
        if (! is_string($url)) {
            throw new InvalidArgumentException('Dynamic sitemap URLs must be strings, '.get_debug_type($url).' given.');
        }

        // filter_var only accepts ASCII, so encode any other characters first
        $asciiUrl = preg_replace_callback('/[^\x00-\x7F]+/', fn (array $match) => rawurlencode($match[0]), $url);
        $isHttp = Str::startsWith(Str::lower($url), ['http://', 'https://']);
        if (! $isHttp || filter_var($asciiUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Dynamic sitemap URLs must be absolute http or https URLs: '.$url);
        }
    }
}
