<?php

namespace MityDigital\Sitemapamic\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\GraphQL\Queries\CollectionQuery;

class SitemapamicController extends Controller
{
    /**
     * Gets the cached sitemap (or renders if it needs to)
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function show()
    {
        $generator = function () {
            $entries = collect()
                ->merge($this->loadEntries())
                ->merge($this->loadCollectionTerms())
                ->merge($this->loadGlobalTaxonomies())
                ->merge($this->loadDynamicRoutes());

            return view('mitydigital/sitemapamic::sitemap', [
                'entries' => $entries
            ])->render();
        };

        $key = config('sitemapamic.cache');
        $ttl = config('sitemapamic.ttl', 'forever');

        // if the ttl is strictly 'forever', do just that
        if ($ttl == 'forever') {
            $xml = Cache::rememberForever($key, $generator);
        } else {
            $xml = Cache::remember($key, $ttl, $generator);
        }

        // add the XML header
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;

        return response($xml, 200, ['Content-Type' => 'application/xml']);
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
        return collect(array_keys(config('sitemapamic.defaults')))->map(function ($handle) {
            return Collection::findByHandle($handle)->queryEntries()->get()->filter(function (
                \Statamic\Entries\Entry $entry
            ) {
                // same site? if site is different, remove
                // if the site url is "/" (i.e. the default), then include it anyway
                if ($entry->site()->url() != '/' && $entry->site()->url() != url('/')) {
                    return false;
                }

                // is the entry published?
                if (!$entry->published()) {
                    return false;
                }

                // if future listings are private, do not include
                if ($entry->collection()->futureDateBehavior() == 'private') {
                    if ($entry->date() > now()) {
                        return false;
                    }
                }

                // if past listings are private, do not include
                if ($entry->collection()->pastDateBehavior() == 'private') {
                    if ($entry->date() < now()) {
                        return false;
                    }
                }

                // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                $includeInSitemap = $entry->get('meta_include_in_xml_sitemap');
                if ($includeInSitemap === null) {
                    // get the default config, or return true by default
                    return config('sitemapamic.defaults.'.$entry->collection()->handle().'.include', true);
                } elseif ($includeInSitemap == "false" || $includeInSitemap === false) {
                    // explicitly set to "false" or boolean false, so exclude
                    return false;
                }

                // yep, keep it
                return true;
            })->map(function ($entry) {

                $changeFreq = $entry->get('meta_change_frequency');
                if ($changeFreq == 'default') {
                    // clear back to use default
                    $changeFreq = null;
                }

                // get the site URL, or the app URL if its "/"
                $siteUrl = config('statamic.sites.sites.'.$entry->locale().'.url');
                if ($siteUrl == '/') {
                    $siteUrl = config('app.url');
                }

                return new SitemapamicUrl(
                    $siteUrl.$entry->url(),
                    Carbon::parse($entry->get('updated_at'))->toW3cString(),
                    $changeFreq ?? config('sitemapamic.defaults.'.$entry->collection()->handle().'.frequency', false),
                    $entry->get('meta_priority') ?? config('sitemapamic.defaults.'.$entry->collection()->handle().'.priority',
                        false)
                );
            })->toArray();
        })->flatten(1);
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

            return $collection->taxonomies()->map->collection($collection)->flatMap(function (
                $taxonomy
            ) use ($site) {
                return $taxonomy->queryTerms()->get()->filter(function ($term) use ($site) {
                    if (!$term->published()) {
                        return false;
                    }

                    // site is not configured, so exclude
                    if (!$term->collection()->sites()->contains($site)) {
                        return false;
                    }

                    // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                    $includeInSitemap = $term->get('meta_include_in_xml_sitemap');
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

                    $changeFreq = $term->get('meta_change_frequency');
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
                        $term->get('meta_priority') ?? config('sitemapamic.defaults.'.$term->collection()->handle().'.priority',
                            false)
                    );
                });
            });
        })->filter()->flatten(1);
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


        return collect($taxonomies)->map(function ($properties, $handle) use ($site) {

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
                    $includeInSitemap = $term->get('meta_include_in_xml_sitemap');
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

                    $changeFreq = $term->get('meta_change_frequency');
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
                        config('sitemapamic.globals.taxonomies.'.$term->taxonomy()->handle().'.frequency', false),
                        $term->get('meta_priority') ?? config('sitemapamic.globals.taxonomies.'.$term->taxonomy()->handle().'.priority',
                            false)
                    );
                });

        })->filter(fn($terms) => $terms)->flatten(1);
    }

    protected function loadDynamicRoutes(): \Illuminate\Support\Collection
    {
        // get the dynamic routes, if any are set, and only return them if they are a SitemapamicUrl
        return collect(Sitemapamic::getDynamicRoutes())
            ->flatMap(function ($closure) {
                return $closure();
            })
            ->filter(fn($route) => get_class($route) == SitemapamicUrl::class);
    }
}
