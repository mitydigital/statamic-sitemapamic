<?php

use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Facades\Site;

foreach (Site::all() as $site) {
    $base = parse_url(SiteUrls::baseUrl($site));
    Route::domain($base['host'])
        ->prefix(trim($base['path'] ?? '', '/'))
        ->name('sitemapamic.'.$site->handle().'.')
        ->group(function () use ($site): void {
            Route::get('sitemap.xml', [SitemapamicController::class, 'index'])
                ->defaults('sitemapamic_site', $site->handle())
                ->name('index');

            Route::get('sitemap-{sitemap}.xml', [SitemapamicController::class, 'show'])
                ->where('sitemap', '[A-Za-z0-9_-]+')
                ->defaults('sitemapamic_site', $site->handle())
                ->name('show');
        });
}
