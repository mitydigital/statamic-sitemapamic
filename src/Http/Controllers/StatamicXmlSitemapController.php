<?php

namespace MityDigital\StatamicXmlSitemap\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MityDigital\StatamicXmlSitemap\Models\SitemapUrl;
use Statamic\Facades\Collection;

class StatamicXmlSitemapController extends Controller
{
    /**
     * Gets the cached sitemap (or renders if it needs to)
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function show()
    {
        $xml = Cache::rememberForever(config('statamic.sitemap.cache'), function () {
            $entries = collect()
                ->merge($this->loadEntries())
                ->merge($this->loadCollectionTerms());

            return view('mitydigital/statamic-xml-sitemap::sitemap', [
                'entries' => $entries
            ])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Gets all published entries for all collections.
     *
     * Returns a collection of \MityDigital\StatamicXmlSitemap\Models\SitemapUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadEntries(): \Illuminate\Support\Collection
    {
        return Collection::all()
            ->flatMap(function ($collection) {
                // get the entries, and
                // filter for published and included
                return $collection->queryEntries()->get()->filter(function (\Statamic\Entries\Entry $entry) {
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
                        return config('statamic.sitemap.defaults.'.$entry->collection()->handle().'.include', true);
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

                    return new SitemapUrl([
                        'loc'        => config('app.url').$entry->url(),
                        'lastmod'    => Carbon::parse($entry->get('updated_at'))->toW3cString(),
                        'changefreq' => $changeFreq ??
                            config('statamic.sitemap.defaults.'.$entry->collection()->handle().'.frequency', false),
                        'priority'   => $entry->get('meta_priority') ??
                            config('statamic.sitemap.defaults.'.$entry->collection()->handle().'.priority', false)
                    ]);
                });
            });
    }

    /**
     * Gets the Taxonomy pages for the collections where they are used.
     *
     * lastmod will be set to the Term's updated_at time, or the latest entry's
     * updated_at time, whichever is more recent.
     *
     * Returns a collection of \MityDigital\StatamicXmlSitemap\Models\SitemapUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function loadCollectionTerms(): \Illuminate\Support\Collection
    {
        return Collection::all()
            ->flatMap(function ($collection) {
                return $collection->taxonomies()->map->collection($collection)->flatMap(function ($taxonomy) {
                    return $taxonomy->queryTerms()->get()->filter(function ($term) {
                        if (!$term->published()) {
                            return false;
                        }

                        // include_xml_sitemap is one of null (when not set, so default to true), then either false or true
                        $includeInSitemap = $term->get('meta_include_in_xml_sitemap');
                        if ($includeInSitemap === null) {
                            // get the default config, or return true by default
                            return config('statamic.sitemap.defaults.'.$term->collection()->handle().'.include', true);
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

                        return new SitemapUrl([
                            'loc'        => config('app.url').$term->url(),
                            'lastmod'    => Carbon::parse($lastMod)->toW3cString(),
                            'changefreq' => $changeFreq ??
                                config('statamic.sitemap.defaults.'.$term->collection()->handle().'.frequency', false),
                            'priority'   => $term->get('meta_priority') ??
                                config('statamic.sitemap.defaults.'.$term->collection()->handle().'.priority', false)
                        ]);
                    });
                });
            });
    }
}