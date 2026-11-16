<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;

it('registers path-only sitemap routes for the default site', function (string $url, string $uri, string $method): void {
    $route = Route::getRoutes()->match(Request::create($url));

    expect($route->uri())->toBe($uri)
        ->and($route->getDomain())->toBeNull()
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getActionName())->toBe(SitemapamicController::class.'@'.$method)
        ->and($route->defaults['sitemapamic_prefix'])->toBe('');
})->with([
    'index' => ['http://localhost/sitemap.xml', 'sitemap.xml', 'index'],
    'child' => ['http://localhost/sitemap-pages-1.xml', 'sitemap-{sitemap}.xml', 'show'],
]);
