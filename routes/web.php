<?php

\Statamic\Facades\Site::all()
    ->pluck('url')
    ->map(fn($url) => \Statamic\Facades\URL::makeRelative($url))
    ->unique()
    ->each(fn($site) => Route::prefix($site)->group(function () {
        // add the standard sitemap.xml
        Route::get('sitemap.xml', 'SitemapamicController@show');

        // add the submap xml
        Route::get('sitemap_{submap}.xml', 'SitemapamicController@show');
    }));
