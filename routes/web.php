<?php

use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;

\Statamic\Facades\Site::all()
    ->pluck('url')
    ->map(fn($url) => \Statamic\Facades\URL::makeRelative($url))
    ->unique()
    ->each(fn($site) => Route::prefix($site)->group(function () {
        // add the standard sitemap.xml
        Route::get('sitemap.xml', [SitemapamicController::class, 'show']);

        // add the submap xml if multiple mode is enabled
        if (config('sitemapamic.mode', 'single') === 'multiple') {
            Route::get('sitemap_{submap}.xml', [SitemapamicController::class, 'show']);
        }
    }));
