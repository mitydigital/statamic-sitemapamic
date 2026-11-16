<?php

namespace MityDigital\Sitemapamic\Http\Controllers;

use Illuminate\Http\Request;
use MityDigital\Sitemapamic\Exceptions\SitemapBusyException;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use Statamic\Facades\Site;
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
        $site = Site::get($request->route('sitemapamic_site'));
        abort_if($site === null, 404);

        // another request is building and there is nothing to serve yet
        try {
            $document = $this->sitemapamic->document($site, $filename);
        } catch (SitemapBusyException) {
            return response('', 503, ['Retry-After' => '10']);
        }

        abort_if($document === null, 404);

        $headers = [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Length' => (string) $document->bytes,
        ];

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
