<?php

namespace MityDigital\Sitemapamic\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\URL;

class Sitemapamic
{
    protected $dynamicRoutes = [];

    /**
     * Add a closure that creates dynamic routes for Sitemapamic.
     *
     * @see https://docs.mity.com.au/sitemapamic/configuration/dynamic-routes
     *
     * @param $routesClosure
     * @return void
     */
    public function addDynamicRoutes($routesClosure)
    {
        $this->dynamicRoutes[] = $routesClosure;
    }

    /**
     * Clears the Sitemapamic cache.
     *
     * Accepts an array of keys when 'mode' is set to 'multiple'. This will allow individual sitemaps to be cleared.
     *
     * Passing nothing, or an empty array, will clear the entire Sitemapamic cache.
     *
     * @param  array  $keys  An array of keys, only for "multiple" configuration.
     * @return bool
     */
    public function clearCache(array $keys = []): bool
    {
        if (count($keys) === 0) {
            // clear everything
            foreach ($this->getCacheKeys() as $key) {
                Cache::forget($key);
            }
        } else {
            // only clear what was requested
            $siteKey = $this->getCacheKey();
            foreach ($keys as $key) {
                if (starts_with($key, $siteKey.'.')) {
                    // we already have the cache key, so exclude it
                    Cache::forget($key);
                } else {
                    // add the cache key
                    Cache::forget($this->getCacheKey().'.'.$key);
                }
            }
        }

        return true;
    }

    /**
     * Get the cache keys used by the current Sitemapamic configuration.
     *
     * @return array
     */
    public function getCacheKeys()
    {
        $key = $this->getCacheKey();
        $mode = config('sitemapamic.mode', 'single');

        $keys = [];
        $sites = collect(Site::all()->keys());

        if ($mode === 'single') {
            if ($sites->count() > 1) {
                // return the single statamic key for each site
                return $sites
                    ->map(fn($site) => $key.'.'.$site)
                    ->toArray();
            } else {
                // just return the key
                return [$key.'.'.Site::default()];
            }
        } elseif ($mode === 'multiple') {
            // get the loaders
            $loaders = $this->getLoaders();

            // get the keys relevant to the setup
            if ($sites->count() > 1) {
                return $this->getLoaders()
                    ->keys()
                    ->map(fn($loader) => $key.'.'.$loader)
                    ->prepend($key)
                    ->map(fn($key) => $sites
                        ->map(fn($site) => $key.'.'.$site))
                    ->flatten()
                    ->toArray();
            } else {
                return $this->getLoaders()
                    ->keys()
                    ->map(fn($loader) => $key.'.'.$loader)
                    ->prepend($key)
                    ->toArray();
            }
        }

        return [];
    }

    /**
     * Returns the root Sitemapamic cache key.
     *
     * @return string|mixed
     */
    public function getCacheKey()
    {
        return config('sitemapamic.cache', 'sitemapamic');
    }

    /**
     * Get all of the content loaders used by the current configuration.
     *
     * Includes entries, collection terms, global taxonomies and dynamic routes.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getLoaders()
    {
        $loaders = collect()
            ->merge($this->loadEntries())
            ->merge($this->loadCollectionTerms())
            ->merge($this->loadGlobalTaxonomies());

        // if we have any dynamic routes, let's load those too
        if ($this->hasDynamicRoutes()) {
            $loaders = $loaders->merge($this->loadDynamicRoutes());
        }

        return $loaders;
    }

    /**
     * Gets all published entries for all configured collections.
     *
     * Returns a collection of \MityDigital\Sitemapamic\Models\SitemapamicUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadEntries(): \Illuminate\Support\Collection
    {
        return collect(config('sitemapamic.defaults'))->mapWithKeys(function ($properties, $handle) {
            return [
                $handle => function () use ($properties, $handle) {
                    return Collection::findByHandle($handle)->queryEntries()->get()->filter(function (
                        \Statamic\Entries\Entry $entry
                    ) {
                        // same site? if site is different, remove
                        if ($entry->site() != Site::current()) {
                            return false;
                        }

                        // is the entry published?
                        if (!$entry->published()) {
                            return false;
                        }

                        // are we an external redirect?
                        if($entry->blueprint()->handle() === 'link' && isset($entry->redirect) && URL::isExternal($entry->redirect)) {
                            return false;
                        }

                        // if future listings are private or unlisted, do not include
                        if ($entry->collection()->futureDateBehavior() == 'private' || $entry->collection()->futureDateBehavior() == 'unlisted') {
                            if ($entry->date() > now()) {
                                return false;
                            }
                        }

                        // if past listings are private or unlisted, do not include
                        if ($entry->collection()->pastDateBehavior() == 'private' || $entry->collection()->pastDateBehavior() == 'unlisted') {
                            if ($entry->date() < now()) {
                                return false;
                            }
                        }

                        // if we happened to have the SEO pro addon & we have no index page, do not include
                        if($entry->has('seo')){
                            $seo = $entry->get('seo');
                            if(isset($seo['robots']) && in_array('noindex', $seo['robots'])){
                                return false;
                            }
                        }

                        // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                        $includeInSitemap = $entry->get(config('sitemapamic.mappings.include', 'meta_include_in_xml_sitemap'));
                        if ($includeInSitemap === null || $includeInSitemap == 'default') {
                            // get the default config, or return true by default
                            return config('sitemapamic.defaults.'.$entry->collection()->handle().'.include', true);
                        } elseif ($includeInSitemap == "false" || $includeInSitemap === false) {
                            // explicitly set to "false" or boolean false, so exclude
                            return false;
                        }

                        // yep, keep it
                        return true;
                    })->map(function ($entry) {

                        $changeFreq = $entry->get(config('sitemapamic.mappings.change_frequency', 'meta_change_frequency'));
                        if ($changeFreq == 'default') {
                            // clear back to use default
                            $changeFreq = null;
                        }

                        // return the entry as a Sitemapamic URL
                        return new SitemapamicUrl(
                            URL::makeAbsolute($entry->url()),
                            Carbon::parse($entry->get('updated_at'))->toW3cString(),
                            $changeFreq ?? config('sitemapamic.defaults.'.$entry->collection()->handle().'.frequency',
                            false),
                            $entry->get(config('sitemapamic.mappings.priority', 'meta_priority')) ?? config('sitemapamic.defaults.'.$entry->collection()->handle().'.priority',
                            false)
                        );
                    })->toArray();
                }
            ];
        });
    }

    /**
     * Gets the Taxonomy pages for the collections where they are used.
     *
     * lastmod will be set to the Term's updated_at time, or the latest entry's
     * updated_at time, whichever is more recent.
     *
     * Returns a collection of \MityDigital\Sitemapamic\Models\SitemapamicUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadCollectionTerms(): \Illuminate\Support\Collection
    {
        // get the current site key based on the url
        $site = 'default';
        foreach (config('statamic.sites.sites') as $key => $props) {
            if ($props['url'] == url('/')) {
                $site = $key;
                break;
            }
        }

        return collect(config('sitemapamic.defaults'))->map(function ($properties, $handle) use ($site) {

            // if there is a property called includeTaxonomies, and its false (or the collection is disabled) then exclude it
            // this has been added for backwards compatibility
            if (isset($properties['includeTaxonomies']) && (!$properties['includeTaxonomies'] || !$properties['include'])) {
                return false;
            }

            $collection = Collection::findByHandle($handle);

            return $collection->taxonomies()->map->collection($collection)->mapWithKeys(function ($taxonomy) use (
                $properties,
                $handle,
                $site
            ) {
                return [
                    $handle.'_'.$taxonomy->handle => function () use ($taxonomy, $site) {

                        return $taxonomy->queryTerms()->get()->filter(function ($term) use ($site) {
                            if (!$term->published()) {
                                return false;
                            }

                            // site is not configured, so exclude
                            if (!$term->collection()->sites()->contains($site)) {
                                return false;
                            }

                            // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                            $includeInSitemap = $term->get(config('sitemapamic.mappings.include', 'meta_include_in_xml_sitemap'));
                            if ($includeInSitemap === null) {
                                // get the default config, or return true by default
                                return config('sitemapamic.defaults.'.$term->collection()->handle().'.include', true);
                            } elseif ($includeInSitemap === false) {
                                // explicitly set to false, so exclude
                                return false;
                            }

                            return true; // this far, accept it
                        })->map(function ($term) {
                            // get the term mod date
                            $lastMod = $term->get('updated_at');

                            // get entries
                            $termEntries = $term->queryEntries()->orderBy('updated_at', 'desc');

                            // if this term has entries, get the greater of the two updated_at timestamps
                            if ($termEntries->count() > 0) {
                                // get the last modified entry
                                $entryLastMod = $termEntries->first()->get('updated_at');

                                // entry date is after the term's mod date
                                if ($entryLastMod > $lastMod) {
                                    $lastMod = $entryLastMod;
                                }
                            }

                            $changeFreq = $term->get(config('sitemapamic.mappings.change_frequency', 'meta_change_frequency'));
                            if ($changeFreq == 'default') {
                                // clear back to use default
                                $changeFreq = null;
                            }


                            // get the site URL, or the app URL if its "/"
                            $siteUrl = config('statamic.sites.sites.'.$term->locale().'.url');
                            if ($siteUrl == '/') {
                                $siteUrl = config('app.url');
                            }

                            return new SitemapamicUrl(
                                $siteUrl.$term->url(),
                                Carbon::parse($lastMod)->toW3cString(),
                                $changeFreq ?? config('sitemapamic.defaults.'.$term->collection()->handle().'.frequency',
                                false),
                                $term->get(config('sitemapamic.mappings.priority', 'meta_priority')) ?? config('sitemapamic.defaults.'.$term->collection()->handle().'.priority',
                                false)
                            );
                        });
                    }
                ];
            });
        })->flatMap(fn($items) => $items);
    }

    protected function loadGlobalTaxonomies(): \Illuminate\Support\Collection
    {
        // are we configured to load the global taxonomies?
        // if so, what?
        $taxonomies = config('sitemapamic.globals.taxonomies', []);

        if (empty($taxonomies)) {
            // return an empty collection - either set to false, or not set yet
            return collect();
        }

        // get the current site key based on the url
        $site = 'default';
        foreach (config('statamic.sites.sites') as $key => $props) {
            if ($props['url'] == url('/')) {
                $site = $key;
                break;
            }
        }

        return collect($taxonomies)->mapWithKeys(function ($properties, $handle) use ($site) {
            return [
                $handle => function () use ($handle, $site) {

                    // get the taxonomy repository
                    $taxonomy = Taxonomy::find($handle);

                    // if the taxonomy isn't configured for the site, get out
                    if (!$taxonomy->sites()->contains($site)) {
                        return null;
                    }

                    // does a view exist for this taxonomy?
                    // if not, it will 404, so let's not do any more
                    if (!view()->exists($handle.'/show')) {
                        return null;
                    }

                    // get the terms
                    return Term::whereTaxonomy($handle)
                        ->filter(function ($term) {
                            // should we include this term?
                            // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                            $includeInSitemap = $term->get(config('sitemapamic.mappings.include', 'meta_include_in_xml_sitemap'));
                            if ($includeInSitemap === "false" || $includeInSitemap === false) {
                                // explicitly set to "false" or boolean false, so exclude
                                return false;
                            }

                            // there is no meta field for the term, so include it
                            // Why? Because if we made it this far, the Taxonomy is part of the global config, so
                            // we want to include it. So just include it.
                            return true;
                        })
                        ->map(function (\Statamic\Taxonomies\LocalizedTerm $term) {
                            // get the term mod date
                            $lastMod = $term->get('updated_at');

                            // get entries
                            $termEntries = $term->queryEntries()->orderBy('updated_at', 'desc');

                            // if this term has entries, get the greater of the two updated_at timestamps
                            if ($termEntries->count() > 0) {
                                // get the last modified entry
                                $entryLastMod = $termEntries->first()->get('updated_at');

                                // entry date is after the term's mod date
                                if ($entryLastMod > $lastMod) {
                                    $lastMod = $entryLastMod;
                                }
                            }

                            $changeFreq = $term->get(config('sitemapamic.mappings.change_frequency', 'meta_change_frequency'));
                            if ($changeFreq == 'default') {
                                // clear back to use default
                                $changeFreq = null;
                            }

                            // get the site URL, or the app URL if its "/"
                            $siteUrl = config('statamic.sites.sites.'.$term->locale().'.url');
                            if ($siteUrl == '/') {
                                $siteUrl = config('app.url');
                            }

                            return new SitemapamicUrl(
                                $siteUrl.$term->url(),
                                Carbon::parse($lastMod)->toW3cString(),
                                $changeFreq ??
                                config('sitemapamic.globals.taxonomies.'.$term->taxonomy()->handle().'.frequency',
                                    false),
                                $term->get(config('sitemapamic.mappings.priority', 'meta_priority')) ?? config('sitemapamic.globals.taxonomies.'.$term->taxonomy()->handle().'.priority',
                                false)
                            );
                        });
                }
            ];
        });
    }

    public function hasDynamicRoutes(): bool
    {
        return (bool) count($this->dynamicRoutes);
    }

    protected function loadDynamicRoutes(): \Illuminate\Support\Collection
    {
        // get the dynamic routes, if any are set, and only return them if they are a SitemapamicUrl
        return collect([
            'dynamic' => function () {
                return collect($this->getDynamicRoutes())
                    ->flatMap(function ($closure) {
                        return $closure();
                    })
                    ->filter(fn($route) => get_class($route) == SitemapamicUrl::class);
            }
        ]);
    }

    /**
     * Returns the dynamic routes
     *
     * @return array|mixed
     */
    public function getDynamicRoutes()
    {
        return $this->dynamicRoutes;
    }
}
