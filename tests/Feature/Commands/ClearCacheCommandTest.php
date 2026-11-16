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

    $this->artisan('sitemapamic:clear', ['--site' => 'default'])
        ->expectsOutput('✅ Cleared the sitemap cache for default')
        ->doesntExpectOutput('✅ Cleared the sitemap cache for archive')
        ->expectsOutput('Snip snip and whoosh, the sitemap for "default" is gone.')
        ->assertExitCode(0);

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

    $command = $this->artisan('sitemapamic:clear');
    foreach (Site::all() as $site) {
        $command->expectsOutput('✅ Cleared the sitemap cache for '.$site->handle());
    }
    $command->expectsOutput('Snip snip and whoosh, all of your sitemaps are gone.')->assertExitCode(0);
    $command->run();

    foreach (Site::all() as $site) {
        expect(app(SitemapCache::class)->manifest($site->handle()))->toBeNull();
    }
    expect(Storage::disk('command-cache')->allFiles())->toBe([]);
});

it('succeeds when there is nothing cached yet', function (): void {
    $this->artisan('sitemapamic:clear')->expectsOutput('Snip snip and whoosh, all of your sitemaps are gone.')->assertExitCode(0);
});

it('rejects an unknown site instead of clearing another one', function (): void {
    $default = app(Sitemapamic::class)->manifest('default');

    $this->artisan('sitemapamic:clear', ['--site' => 'not-configured'])
        ->expectsOutput('❌ Uh oh... there is no site with the handle "not-configured".')
        ->doesntExpectOutputToContain('✅')
        ->assertExitCode(1);

    expect(app(SitemapCache::class)->manifest('default'))->toEqual($default);
});

it('names a site by its name and handle when they differ', function (): void {
    Site::setSites([
        'en' => ['name' => 'Australia', 'locale' => 'en_AU', 'url' => '/'],
        'de' => ['name' => 'Germany', 'locale' => 'de_DE', 'url' => '/de/'],
    ]);

    $this->artisan('sitemapamic:clear')
        ->expectsOutput('✅ Cleared the sitemap cache for Australia (en)')
        ->expectsOutput('✅ Cleared the sitemap cache for Germany (de)')
        ->assertExitCode(0);
});

it('keeps clearing the other sites when one fails, and reports the failure', function (): void {
    $default = app(Sitemapamic::class)->manifest('default');
    $this->partialMock(SitemapCache::class, function ($mock): void {
        $mock->shouldReceive('clear')->with('archive')->andThrow(new RuntimeException('The disk is read-only.'));
        $mock->shouldReceive('clear')->with(Mockery::not('archive'))->passthru();
    });
    app()->forgetScopedInstances();

    $this->artisan('sitemapamic:clear')
        ->expectsOutput('✅ Cleared the sitemap cache for default')
        ->expectsOutput('❌ Could not clear the sitemap cache for archive: The disk is read-only.')
        ->expectsOutput('✅ Cleared the sitemap cache for preview')
        ->expectsOutput('Uh oh... Sitemapamic could not clear the cache for "archive".')
        ->doesntExpectOutputToContain('Snip snip')
        ->assertExitCode(1);

    expect(app(SitemapCache::class)->manifest('default'))->toBeNull()
        ->and(Storage::disk('command-cache')->exists($default->directory))->toBeFalse();
});
