<?php

namespace MityDigital\Sitemapamic\Http\Controllers;

use Illuminate\Http\Request;
use MityDigital\Sitemapamic\Exceptions\SitemapBusyException;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Facades\Site;
use Statamic\StaticSite\Request as StaticSiteRequest;
use Symfony\Component\HttpFoundation\Response;

class SitemapamicController
{
    public function __construct(private Sitemapamic $sitemapamic) {}

    public function index(Request $request): Response
    {
        return $this->respond($request, 'sitemap.xml');
    }

    public function show(Request $request, string $sitemap): Response
    {
        return $this->respond($request, 'sitemap-'.$sitemap.'.xml');
    }

    private function respond(Request $request, string $filename): Response
    {
        // the site that owns this URL must also own the route's prefix, so /de/sitemap.xml never serves another site
        $site = Site::findByUrl($request->url());
        abort_if($site === null || SiteUrls::pathPrefix($site) !== trim($request->route()->getPrefix() ?? '', '/'), 404);

        // the static site generator writes whatever it gets to disk, so it must never get an empty 304
        $isStaticSite = $request instanceof StaticSiteRequest;
        $etags = $isStaticSite ? [] : $request->getETags();

        // another request is building and there is nothing to serve yet
        try {
            $document = $this->sitemapamic->document($site, $filename, $etags);
        } catch (SitemapBusyException) {
            return response('', 503, ['Retry-After' => '10']);
        }

        abort_if($document === null, 404);

        // shared caches may keep a copy but must check it each time, so a rebuild or clear is seen straight away
        $validators = [
            'Cache-Control' => 'public, no-cache',
            'ETag' => $document->etag,
        ];

        if ($document->isUnchanged()) {
            return response('', 304, $validators);
        }

        $headers = [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Length' => (string) $document->bytes,
            ...$validators,
        ];

        // the static site generator writes getContent() to disk, which a streamed response doesn't have
        if ($isStaticSite) {
            try {
                return response(stream_get_contents($document->stream), 200, $headers);
            } finally {
                fclose($document->stream);
            }
        }

        if ($request->isMethod('HEAD')) {
            fclose($document->stream);

            return response('', 200, $headers);
        }

        return response()->stream(function () use ($document): void {
            try {
                fpassthru($document->stream);
            } finally {
                fclose($document->stream);
            }
        }, 200, $headers);
    }
}
