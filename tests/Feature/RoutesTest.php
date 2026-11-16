<?php

use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;

it('registers named path-only sitemap routes for the default site', function (string $name, string $uri, string $method): void {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe($uri)
        ->and($route->getDomain())->toBeNull()
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getActionName())->toBe(SitemapamicController::class.'@'.$method);
})->with([
    'index' => ['sitemapamic.index', 'sitemap.xml', 'index'],
    'child' => ['sitemapamic.show', 'sitemap-{sitemap}.xml', 'show'],
]);
