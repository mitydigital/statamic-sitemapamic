<?php

use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Facades\Site;

// routes match on path only, and the controller works out the site from the full URL like Statamic does
$prefixes = Site::all()->map(fn ($site): string => SiteUrls::pathPrefix($site))->unique()->values();

foreach ($prefixes as $prefix) {
    Route::prefix($prefix)->group(function () use ($prefix): void {
        Route::get('sitemap.xml', [SitemapamicController::class, 'index'])
            ->defaults('sitemapamic_prefix', $prefix);

        Route::get('sitemap-{sitemap}.xml', [SitemapamicController::class, 'show'])
            ->where('sitemap', '[A-Za-z0-9_-]+')
            ->defaults('sitemapamic_prefix', $prefix);
    });
}
