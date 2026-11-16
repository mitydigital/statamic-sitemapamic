<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\StaticSite\StaticSiteUrls;
use MityDigital\Sitemapamic\Tests\UsesSitemapSites;
use Statamic\StaticSite\Generator;
use Statamic\StaticSite\Page;
use Statamic\StaticSite\Request as StaticSiteRequest;
use Statamic\StaticSite\Route;
use Statamic\StaticSite\ServiceProvider as StaticSiteServiceProvider;
use Wilderborn\Partyline\ServiceProvider as PartylineServiceProvider;

uses(UsesSitemapSites::class);

beforeEach(function (): void {
    $this->app->register(PartylineServiceProvider::class);
    $this->app->register(StaticSiteServiceProvider::class);
    config(['statamic.ssg.base_url' => 'http://example.test']);
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')]);
});

it('lists the sitemap files of every site on the generator host', function (): void {
    expect(app(StaticSiteUrls::class)->urls())->toBe([
        '/sitemap.xml', '/sitemap-dynamic-1.xml',
        '/nested/region/sitemap.xml', '/nested/region/sitemap-dynamic-1.xml',
        '/nested/sitemap.xml', '/nested/sitemap-dynamic-1.xml',
    ]);
});

it('includes a site with its own domain when it is the generator host', function (): void {
    config(['statamic.ssg.base_url' => 'https://ISLAND.example.test']);

    expect(app(StaticSiteUrls::class)->urls())->toContain('/branch/sitemap.xml', '/branch/sitemap-dynamic-1.xml')
        ->not->toContain('/deep/sitemap.xml');
});

it('leaves out sites on other hosts when the generator base_url is relative', function (): void {
    config(['statamic.ssg.base_url' => '/']);

    expect(app(StaticSiteUrls::class)->urls())->not->toContain('/branch/sitemap.xml', '/deep/sitemap.xml')
        ->toContain('/sitemap.xml');
});

it('lists only sitemap.xml for a site in single mode', function (): void {
    config(['sitemapamic.mode' => 'single']);

    expect(app(StaticSiteUrls::class)->urls())->toBe(['/sitemap.xml', '/nested/region/sitemap.xml', '/nested/sitemap.xml']);
});

it('leaves out a site with nothing in its sitemap', function (): void {
    app()->forgetInstance(DynamicRoutes::class);
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')], 'nested_parent');

    expect(app(StaticSiteUrls::class)->urls())->toBe(['/nested/sitemap.xml', '/nested/sitemap-dynamic-1.xml']);
});

it('rebuilds a cached sitemap so the export matches the content', function (): void {
    config(['sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'ssg-cache']]);
    Storage::fake('ssg-cache');
    $pages = ['first'];
    app()->forgetInstance(DynamicRoutes::class);
    app(DynamicRoutes::class)->add(function ($site) use (&$pages): array {
        return array_map(fn (string $page): SitemapamicUrl => new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/'.$page), $pages);
    }, 'default');
    $this->get('http://example.test/sitemap-dynamic-1.xml')->assertOk();

    $pages = ['first', 'second'];
    app(StaticSiteUrls::class)->urls();

    expect($this->get('http://example.test/sitemap-dynamic-1.xml')->streamedContent())->toContain('http://example.test/second');
});

it('answers the generator with a plain response it can write to disk', function (): void {
    $request = StaticSiteRequest::create('/');
    $request->setConfig(['base_url' => 'http://example.test']);
    $request->setPage(new Page(app(Filesystem::class), [], new Route('http://example.test/nested/region/sitemap.xml')));

    $response = app(Kernel::class)->handle($request);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->exception)->toBeNull()
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($response->getContent()))
        ->and($response->getContent())->toContain('<loc>http://example.test/nested/region/sitemap-dynamic-1.xml</loc>');
});

it('adds its URLs to the generator when the generator is resolved', function (): void {
    $extraUrls = (fn () => $this->extraUrls)->call(app(Generator::class));

    expect($extraUrls)->toHaveCount(1)
        ->and($extraUrls->first()())->toContain('/sitemap.xml', '/nested/region/sitemap-dynamic-1.xml');
});

it('writes every sitemap file when the whole site is generated', function (): void {
    $destination = $this->fixturePath.'/static';
    config(['statamic.ssg.destination' => $destination, 'statamic.ssg.copy' => [], 'statamic.ssg.failures' => 'errors']);

    $this->artisan('statamic:ssg:generate', ['--no-interaction' => true])->assertSuccessful();

    foreach (['', '/nested', '/nested/region'] as $prefix) {
        expect(File::get($destination.$prefix.'/sitemap.xml'))->toContain('<loc>http://example.test'.$prefix.'/sitemap-dynamic-1.xml</loc>')
            ->and(File::get($destination.$prefix.'/sitemap-dynamic-1.xml'))->toContain('<loc>http://example.test'.$prefix.'/page</loc>');
    }
    expect(File::exists($destination.'/branch/sitemap.xml'))->toBeFalse();
});
