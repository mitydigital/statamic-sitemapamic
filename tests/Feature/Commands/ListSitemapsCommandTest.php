<?php

use Illuminate\Support\Facades\Storage;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Tests\UsesSitemapSites;
use Statamic\Facades\Site;

uses(UsesSitemapSites::class);

beforeEach(function (): void {
    config(['sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'command-cache']]);
    Storage::fake('command-cache');
});

it('lists child files and their metadata, building them once', function (): void {
    $calls = 0;
    app(DynamicRoutes::class)->add(function ($site) use (&$calls): array {
        $calls++;

        return [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')];
    });
    $file = app(Sitemapamic::class)->manifest('editorial_eu')->file('sitemap-dynamic-1.xml');

    $this->artisan('sitemapamic:list', ['--site' => 'editorial_eu'])->expectsTable(
        ['Site', 'Source', 'Filename', 'URLs', 'Bytes', 'URL'],
        [['editorial_eu', 'dynamic', 'sitemap-dynamic-1.xml', 1, $file->bytes, 'http://example.test/nested/region/sitemap-dynamic-1.xml']],
    )->assertExitCode(0);

    expect($calls)->toBe(1);
});

it('lists every configured site when the site option is omitted', function (): void {
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/page')]);
    $service = app(Sitemapamic::class);
    $rows = [];
    foreach (Site::all() as $site) {
        foreach ($service->manifest($site)->files as $file) {
            $rows[] = [$site->handle(), $file->source, $file->filename, $file->count, $file->bytes, $service->url($site, $file->filename)];
        }
    }

    $this->artisan('sitemapamic:list')->expectsTable(['Site', 'Source', 'Filename', 'URLs', 'Bytes', 'URL'], $rows)->assertExitCode(0);
});

it('does not invent endpoints for empty or missing site selections', function (): void {
    $this->artisan('sitemapamic:list', ['--site' => 'default'])->expectsTable(['Site', 'Source', 'Filename', 'URLs', 'Bytes', 'URL'], [])->assertExitCode(0);
    $this->artisan('sitemapamic:list', ['--site' => 'missing'])->expectsOutput('Unknown sitemap site: missing')->assertExitCode(1);
});
