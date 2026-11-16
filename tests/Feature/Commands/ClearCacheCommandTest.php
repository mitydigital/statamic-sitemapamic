<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Tests\UsesSitemapSites;
use Statamic\Facades\Site;

uses(UsesSitemapSites::class);

beforeEach(function (): void {
    config(['sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'command-cache']]);
    Storage::fake('command-cache');
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')]);
});

it('clears only the selected site and leaves the rest of the application cache alone', function (): void {
    $service = app(Sitemapamic::class);
    $default = $service->manifest('default');
    $archive = $service->manifest('archive');
    Cache::forever('unrelated-application-value', 'preserved');

    $this->artisan('sitemapamic:clear', ['--site' => 'default'])->expectsOutput('Sitemapamic cache cleared.')->assertExitCode(0);

    $cache = app(SitemapCache::class);
    expect($cache->manifest('default'))->toBeNull()
        ->and(Storage::disk('command-cache')->exists($default->directory))->toBeFalse()
        ->and($cache->manifest('archive'))->toEqual($archive)
        ->and($cache->isFresh($archive))->toBeTrue()
        ->and(Cache::get('unrelated-application-value'))->toBe('preserved');
});

it('clears every site when the option is omitted', function (): void {
    foreach (Site::all() as $site) {
        app(Sitemapamic::class)->manifest($site);
    }

    $this->artisan('sitemapamic:clear')->expectsOutput('Sitemapamic cache cleared.')->assertExitCode(0);

    foreach (Site::all() as $site) {
        expect(app(SitemapCache::class)->manifest($site->handle()))->toBeNull();
    }
    expect(Storage::disk('command-cache')->allFiles())->toBe([]);
});

it('succeeds when there is nothing cached yet', function (): void {
    $this->artisan('sitemapamic:clear')->expectsOutput('Sitemapamic cache cleared.')->assertExitCode(0);
});

it('rejects an unknown site instead of clearing another one', function (): void {
    $default = app(Sitemapamic::class)->manifest('default');

    $this->artisan('sitemapamic:clear', ['--site' => 'not-configured'])->expectsOutput('Unknown sitemap site: not-configured')->assertExitCode(1);

    expect(app(SitemapCache::class)->manifest('default'))->toEqual($default);
});
