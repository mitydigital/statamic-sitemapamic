<?php

namespace MityDigital\Sitemapamic\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\URL;
use Statamic\GraphQL\Queries\CollectionQuery;

class SitemapamicController extends Controller
{
    /**
     * Gets the cached sitemap (or renders if it needs to)
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        $mode = config('sitemapamic.mode', 'single');
        $key = Sitemapamic::getCacheKey();
        $ttl = config('sitemapamic.ttl', 'forever');

        // get the loaders
        $loaders = Sitemapamic::getLoaders();

        if ($mode === 'single') {
            $generator = function () use ($loaders) {
                $entries = $loaders
                    ->map(fn($loader) => $loader())
                    ->flatten(1);
                return view('mitydigital/sitemapamic::sitemap', [
                    'entries' => $entries
                ])->render();
            };
        } elseif ($mode === 'multiple') {
            if ($request->submap) {
                if (!$loaders->has($request->submap)) {
                    abort(404);
                }
                $key .= '.'.$request->submap;

                $generator = function () use ($loaders, $request) {
                    $entries = $loaders->get($request->submap)();
                    return view('mitydigital/sitemapamic::sitemap', [
                        'entries' => $entries
                    ])->render();
                };
            } else {
                $generator = function () use ($loaders) {
                    // return the view with submaps defined
                    return view('mitydigital/sitemapamic::index', [
                        'domain' => rtrim(URL::makeAbsolute(Site::current()->url()), '/\\'),
                        'submaps' => $loaders->keys()
                    ])->render();
                };
            }
        }

        // add site to key
        $key .= '.'.Site::current();

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
}
