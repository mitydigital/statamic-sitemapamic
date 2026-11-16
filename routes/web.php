<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;
use MityDigital\Sitemapamic\Support\SiteUrls;
use Statamic\Facades\Site;

// one pair of routes per path, shared by sites on different domains; the controller works out the site from the full URL
Site::all()
    ->map(fn ($site): string => SiteUrls::pathPrefix($site))
    ->unique()
    ->each(fn (string $prefix) => Route::prefix($prefix)
        ->name(Str::of($prefix)->replace('/', '.')->prepend('sitemapamic.')->finish('.')->toString())
        ->group(function (): void {
            Route::get('sitemap.xml', [SitemapamicController::class, 'index'])
                ->name('index');

            Route::get('sitemap-{sitemap}.xml', [SitemapamicController::class, 'show'])
                ->where('sitemap', '[A-Za-z0-9_-]+')
                ->name('show');
        }));
