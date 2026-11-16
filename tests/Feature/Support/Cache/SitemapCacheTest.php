<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapFile;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;

/** @return resource */
function sitemapCacheStream(string $contents)
{
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

/** Store a child file and an index in a new directory and return its manifest. */
function publishedSitemapCacheManifest(SitemapCache $cache, string $site = 'default', ?int $expiresAt = null): Manifest
{
    $directory = $cache->newDirectory($site);
    $cache->store($directory, 'sitemap-collection-pages-1.xml', sitemapCacheStream('<urlset/>'));
    $cache->store($directory, 'sitemap.xml', sitemapCacheStream('<sitemapindex/>'));

    $child = new SitemapFile('sitemap-collection-pages-1.xml', 'collection-pages', 1, 9);

    $manifest = new Manifest(
        site: $site,
        version: $cache->version($site),
        directory: $directory,
        builtAt: now()->getTimestamp(),
        expiresAt: $expiresAt,
        configHash: $cache->configHash(),
        files: [$child->filename => $child],
        index: new SitemapFile('sitemap.xml', 'index', 1, 15),
    );
    $cache->publish($manifest);

    return $manifest;
}

beforeEach(function (): void {
    config(['sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'sitemap-cache']]);
    Storage::fake('sitemap-cache');
    $this->cache = new SitemapCache;
});

it('reads back a published manifest', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    expect($this->cache->manifest('default'))->toEqual($manifest)
        ->and($this->cache->manifest('regional'))->toBeNull();
});

it('treats a just-published manifest as fresh', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    expect($this->cache->isFresh($manifest))->toBeTrue();
});

it('keeps the same version until it is marked stale', function (): void {
    $version = $this->cache->version('default');

    expect($this->cache->version('default'))->toBe($version);

    $this->cache->markStale('default');

    expect($this->cache->version('default'))->not->toBe($version);
});

it('is stale after markStale but keeps the manifest and files to serve', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    $this->cache->markStale('default');

    expect($this->cache->isFresh($manifest))->toBeFalse()
        ->and($this->cache->manifest('default'))->toEqual($manifest)
        ->and(Storage::disk('sitemap-cache')->exists($manifest->directory.'/sitemap.xml'))->toBeTrue();
});

it('only marks the given site stale', function (): void {
    $default = publishedSitemapCacheManifest($this->cache, 'default');
    $regional = publishedSitemapCacheManifest($this->cache, 'regional');

    $this->cache->markStale('regional');

    expect($this->cache->isFresh($default))->toBeTrue()
        ->and($this->cache->isFresh($regional))->toBeFalse();
});

it('expires by TTL at the exact second', function (): void {
    $this->freezeTime();
    $manifest = publishedSitemapCacheManifest($this->cache, expiresAt: now()->getTimestamp() + 60);

    $this->travel(59)->seconds();
    expect($this->cache->isFresh($manifest))->toBeTrue();

    $this->travel(1)->seconds();
    expect($this->cache->isFresh($manifest))->toBeFalse();
});

it('never expires a manifest without an expiry', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    $this->travel(10)->years();

    expect($this->cache->isFresh($manifest))->toBeTrue();
});

it('is stale after a config change', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    config(['sitemapamic.mappings.priority' => 'custom_priority']);

    expect($this->cache->isFresh($manifest))->toBeFalse();
});

it('clear removes the manifest and every file for the site', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);
    $version = $this->cache->version('default');

    $this->cache->clear('default');

    expect($this->cache->manifest('default'))->toBeNull()
        ->and(Storage::disk('sitemap-cache')->exists($manifest->directory))->toBeFalse()
        ->and(Storage::disk('sitemap-cache')->allFiles('sitemapamic/default'))->toBe([])
        ->and($this->cache->version('default'))->not->toBe($version);
});

it('clear leaves other sites alone', function (): void {
    $regional = publishedSitemapCacheManifest($this->cache, 'regional');
    publishedSitemapCacheManifest($this->cache, 'default');

    $this->cache->clear('default');

    expect($this->cache->manifest('regional'))->toEqual($regional)
        ->and(Storage::disk('sitemap-cache')->exists($regional->directory.'/sitemap.xml'))->toBeTrue();
});

it('publish removes older directories for the site', function (): void {
    $older = publishedSitemapCacheManifest($this->cache);
    $abandoned = $this->cache->newDirectory('default');
    $this->cache->store($abandoned, 'sitemap.xml', sitemapCacheStream('<sitemapindex/>'));

    $newer = publishedSitemapCacheManifest($this->cache);

    expect(Storage::disk('sitemap-cache')->directories('sitemapamic/default'))->toBe([$newer->directory])
        ->and(Storage::disk('sitemap-cache')->exists($older->directory))->toBeFalse()
        ->and($this->cache->manifest('default'))->toEqual($newer);
});

it('opens a file listed in the manifest with its byte length', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    $index = $this->cache->open($manifest, 'sitemap.xml');
    $child = $this->cache->open($manifest, 'sitemap-collection-pages-1.xml');

    expect(stream_get_contents($index->stream))->toBe('<sitemapindex/>')
        ->and($index->bytes)->toBe(15)
        ->and(stream_get_contents($child->stream))->toBe('<urlset/>')
        ->and($child->bytes)->toBe(9);

    fclose($index->stream);
    fclose($child->stream);
});

it('returns null for a filename not in the manifest', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);

    expect($this->cache->open($manifest, 'sitemap-missing-1.xml'))->toBeNull();
});

it('forgets the manifest and throws when a listed file is missing from the disk', function (): void {
    $manifest = publishedSitemapCacheManifest($this->cache);
    Storage::disk('sitemap-cache')->delete($manifest->directory.'/sitemap-collection-pages-1.xml');

    expect(fn () => $this->cache->open($manifest, 'sitemap-collection-pages-1.xml'))
        ->toThrow(RuntimeException::class, 'sitemap-collection-pages-1.xml')
        ->and($this->cache->manifest('default'))->toBeNull();
});

it('keeps a newer manifest when an older one fails to open', function (): void {
    $first = publishedSitemapCacheManifest($this->cache);
    $second = publishedSitemapCacheManifest($this->cache);

    expect(fn () => $this->cache->open($first, 'sitemap.xml'))
        ->toThrow(RuntimeException::class)
        ->and($this->cache->manifest('default')->directory)->toBe($second->directory);
});

it('forgets the manifest when a throwing disk cannot read a listed file', function (): void {
    Storage::fake('sitemap-cache', ['throw' => true]);
    $cache = new SitemapCache;
    $manifest = publishedSitemapCacheManifest($cache);
    Storage::disk('sitemap-cache')->delete($manifest->directory.'/sitemap-collection-pages-1.xml');

    expect(fn () => $cache->open($manifest, 'sitemap-collection-pages-1.xml'))
        ->toThrow(RuntimeException::class, 'is missing for site default')
        ->and($cache->manifest('default'))->toBeNull();
});

it('throws when a file cannot be written', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturnFalse();
    Storage::set('sitemap-cache', $disk);

    expect(fn () => $this->cache->store('sitemapamic/default/x', 'sitemap.xml', sitemapCacheStream('x')))
        ->toThrow(RuntimeException::class, 'sitemap.xml');
});

it('locks each site separately', function (): void {
    $default = $this->cache->lock('default');
    $regional = $this->cache->lock('regional');

    expect($default->get())->toBeTrue()
        ->and($this->cache->lock('default')->get())->toBeFalse()
        ->and($regional->get())->toBeTrue();

    $default->release();
    $regional->release();
});

it('lets a build lock expire soon after PHP stops the request', function (string $timeLimit, int $lifetime): void {
    $this->freezeTime();
    // max_execution_time also limits this test process, so put it back as soon as the lock is taken
    $original = ini_get('max_execution_time');
    ini_set('max_execution_time', $timeLimit);
    try {
        $lock = $this->cache->lock('default');
        $held = $lock->get();
    } finally {
        ini_set('max_execution_time', $original);
    }

    $this->travel($lifetime - 1)->seconds();
    $stillHeld = ! $this->cache->lock('default')->get();
    $this->travel(2)->seconds();

    expect($held)->toBeTrue()
        ->and($stillHeld)->toBeTrue()
        ->and($this->cache->lock('default')->get())->toBeTrue();
})->with([
    'time limit plus a margin' => ['100', 130],
    'never under a minute' => ['10', 60],
    'no time limit' => ['0', 600],
]);

it('reads whether caching is enabled', function (): void {
    expect($this->cache->enabled())->toBeTrue();

    config(['sitemapamic.cache.enabled' => false]);

    expect($this->cache->enabled())->toBeFalse();
});
