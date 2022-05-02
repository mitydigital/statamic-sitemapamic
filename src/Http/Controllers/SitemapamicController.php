<?php

namespace MityDigital\Sitemapamic\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Models\SitemapUrl;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Collection;
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
        $key = config('statamic.sitemapamic.cache') . '-' . url('/');
        $xml = Cache::rememberForever($key, function () {
            $entries = collect()
                ->merge($this->loadEntries())
                ->merge($this->loadCollectionTerms());

            return view('mitydigital/sitemapamic::sitemap', [
                'entries' => $entries
            ])->render();
        });

        // add the XML header
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $xml;

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Gets all published entries for all configured collections.
     *
     * Returns a collection of \MityDigital\Sitemapamic\Models\SitemapUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadEntries(): \Illuminate\Support\Collection
    {
        return collect(array_keys(config('statamic.sitemapamic.defaults')))->map(function ($handle) {
            return Collection::findByHandle($handle)->queryEntries()->get()->filter(function (
                \Statamic\Entries\Entry $entry
            ) {
                // same site? if site is different, remove
                // if the site url is "/" (i.e. the default), then include it anyway
                if ($entry->site()->url() != '/' && $entry->site()->url() != url('/'))
                {
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
                    return config('statamic.sitemapamic.defaults.'.$entry->collection()->handle().'.include', true);
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
                if ($siteUrl == '/')
                {
                    $siteUrl = config('app.url');
                }

                return new SitemapUrl([
                    'loc'        => $siteUrl.$entry->url(),
                    'lastmod'    => Carbon::parse($entry->get('updated_at'))->toW3cString(),
                    'changefreq' => $changeFreq ??
                        config('statamic.sitemapamic.defaults.'.$entry->collection()->handle().'.frequency', false),
                    'priority'   => $entry->get('meta_priority') ??
                        config('statamic.sitemapamic.defaults.'.$entry->collection()->handle().'.priority', false)
                ]);
            })->toArray();
        })->flatten(1);
    }

    /**
     * Gets the Taxonomy pages for the collections where they are used.
     *
     * lastmod will be set to the Term's updated_at time, or the latest entry's
     * updated_at time, whichever is more recent.
     *
     * Returns a collection of \MityDigital\Sitemapamic\Models\SitemapUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadCollectionTerms(): \Illuminate\Support\Collection
    {
        // get the current site key based on the url
        $site = 'default';
        foreach(config('statamic.sites.sites') as $key => $props)
        {
            if ($props['url'] == url('/'))
            {
                $site = $key;
                break;
            }
        }

        return collect(config('statamic.sitemapamic.defaults'))->map(function ($properties, $handle) use ($site) {

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
                    if(!$term->collection()->sites()->contains($site))
                    {
                        return false;
                    }

                    // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                    $includeInSitemap = $term->get('meta_include_in_xml_sitemap');
                    if ($includeInSitemap === null) {
                        // get the default config, or return true by default
                        return config('statamic.sitemapamic.defaults.'.$term->collection()->handle().'.include', true);
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
                    if ($siteUrl == '/')
                    {
                        $siteUrl = config('app.url');
                    }

                    return new SitemapUrl([
                        'loc'        => $siteUrl.$term->url(),
                        'lastmod'    => Carbon::parse($lastMod)->toW3cString(),
                        'changefreq' => $changeFreq ??
                            config('statamic.sitemapamic.defaults.'.$term->collection()->handle().'.frequency', false),
                        'priority'   => $term->get('meta_priority') ??
                            config('statamic.sitemapamic.defaults.'.$term->collection()->handle().'.priority', false)
                    ]);
                });
            });
        })->filter()->flatten(1);
    }
}