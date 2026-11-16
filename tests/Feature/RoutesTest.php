<?php

use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;

it('registers the sitemap routes for the default site', function (string $name, string $uri, string $method): void {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe($uri)
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getActionName())->toBe(SitemapamicController::class.'@'.$method);
})->with([
    'index' => ['sitemapamic.default.index', 'sitemap.xml', 'index'],
    'child' => ['sitemapamic.default.show', 'sitemap-{sitemap}.xml', 'show'],
]);
