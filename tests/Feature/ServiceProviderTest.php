<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\RouteCacheCommand;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Facades\Sitemapamic as SitemapamicFacade;
use MityDigital\Sitemapamic\Http\Controllers\SitemapamicController;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Tests\UsesSitemapSites;
use Statamic\Facades\Site;

uses(UsesSitemapSites::class);

it('registers exactly two named serialisable routes per configured site', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->getName() ?? '', 'sitemapamic.'));

    expect($routes)->toHaveCount(2 * Site::all()->count());
    foreach (Site::all() as $site) {
        foreach (['index', 'show'] as $action) {
            $route = Route::getRoutes()->getByName('sitemapamic.'.$site->handle().'.'.$action);
            expect($route->methods())->toBe(['GET', 'HEAD'])
                ->and($route->getActionName())->toBe(SitemapamicController::class.'@'.$action)
                ->and($route->defaults['sitemapamic_site'])->toBe($site->handle());
            $route->prepareForSerialization();
        }
    }
});

it('generates canonical named URLs independently of request host and current site', function (string $handle, string $base): void {
    $request = Request::create('https://untrusted.example/arbitrary');
    app('url')->setRequest($request);
    Site::setCurrent('archive');

    expect(SitemapamicFacade::url($handle))->toBe($base.'/sitemap.xml')
        ->and(SitemapamicFacade::url($handle, 'sitemap-dynamic-2.xml'))->toBe($base.'/sitemap-dynamic-2.xml');
})->with([
    ['default', 'http://example.test'],
    ['editorial_eu', 'http://example.test/nested/region'],
    ['archive', 'https://island.example.test/branch'],
    ['preview', 'http://preview.example.test:8088/deep'],
]);

it('supports site-first manifest calls through the facade and rejects unknown handles', function (): void {
    Site::setCurrent('editorial_eu');

    expect(SitemapamicFacade::manifest()->site)->toBe('editorial_eu')
        ->and(SitemapamicFacade::manifest('default')->site)->toBe('default')
        ->and(SitemapamicFacade::manifest(Site::get('archive'))->site)->toBe('archive')
        ->and(fn () => SitemapamicFacade::manifest('missing'))->toThrow(InvalidArgumentException::class, 'Unknown sitemap site');
});

it('keeps URL generation constrained to sitemap filenames', function (string $filename): void {
    expect(fn () => SitemapamicFacade::url('default', $filename))->toThrow(InvalidArgumentException::class);
})->with(['../sitemap.xml', '/sitemap.xml', 'sitemap-../secret.xml', 'sitemap-foo.bar.xml']);

it('takes precedence over the native Statamic catch-all route', function (string $url): void {
    $route = Route::getRoutes()->match(Request::create($url));

    expect($route->getActionName())->toBe(SitemapamicController::class.'@index');
})->with(['http://example.test/sitemap.xml', 'http://example.test/nested/region/sitemap.xml', 'https://island.example.test/branch/sitemap.xml']);

it('permits an explicit application route to replace an endpoint on its own domain', function (): void {
    Route::domain('example.test')->get('sitemap.xml', fn () => response('application override'));

    $this->get('http://example.test/sitemap.xml')->assertOk()->assertContent('application override');
    expect(Route::getRoutes()->match(Request::create('https://island.example.test/branch/sitemap.xml'))->getActionName())
        ->toBe(SitemapamicController::class.'@index');
});

it('round trips the application routes through Laravels route cache format', function (): void {
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')]);
    $routes = Route::getRoutes();
    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }
    $command = new class(app(Filesystem::class)) extends RouteCacheCommand
    {
        public function cacheFile(RouteCollection $routes): string
        {
            return $this->buildRouteCacheFile($routes);
        }
    };
    File::ensureDirectoryExists($this->fixturePath);
    $path = $this->fixturePath.'/cached-routes.php';
    File::put($path, $command->cacheFile($routes));
    Route::setRoutes(new RouteCollection);

    require $path;

    foreach (['http://example.test', 'http://example.test/nested/region', 'https://island.example.test/branch'] as $base) {
        $response = $this->get($base.'/sitemap.xml')->assertOk()->assertStreamed();
        expect($response->streamedContent())->toContain($base.'/sitemap-dynamic-1.xml');
        $this->get($base.'/sitemap-dynamic-1.xml')->assertOk()->streamedContent();
    }
    expect(SitemapamicFacade::url('editorial_eu'))->toBe('http://example.test/nested/region/sitemap.xml');
});

it('registers the en de gb fixture without treating its handles as addon defaults', function (): void {
    Site::setSites([
        'en' => ['locale' => 'en_AU', 'url' => '/'],
        'de' => ['locale' => 'de_DE', 'url' => '/de/'],
        'gb' => ['locale' => 'en_GB', 'url' => 'http://gb.example.test'],
    ]);
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    Route::getRoutes()->refreshNameLookups();
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')]);

    foreach (['en' => 'http://example.test', 'de' => 'http://example.test/de', 'gb' => 'http://gb.example.test'] as $handle => $base) {
        expect(SitemapamicFacade::url($handle))->toBe($base.'/sitemap.xml');
        $this->get($base.'/sitemap.xml')->assertOk()->streamedContent();
    }
});

it('scopes the service to each request and keeps dynamic routes for the whole process', function (): void {
    $sitemapamic = app(Sitemapamic::class);
    $routes = app(DynamicRoutes::class);
    $sitemapamic->addDynamicRoutes(fn (): array => [], 'default');

    expect(app(Sitemapamic::class))->toBe($sitemapamic);

    app()->forgetScopedInstances();

    expect(app(Sitemapamic::class))->not->toBe($sitemapamic)
        ->and(app(DynamicRoutes::class))->toBe($routes)
        ->and(app(DynamicRoutes::class)->sources(Site::get('default')))->toHaveCount(1);
});

it('resolves the facade from the current scope', function (): void {
    $first = SitemapamicFacade::getFacadeRoot();

    app()->forgetScopedInstances();

    expect(SitemapamicFacade::getFacadeRoot())->not->toBe($first)
        ->and(SitemapamicFacade::getFacadeRoot())->toBe(app(Sitemapamic::class));
});
