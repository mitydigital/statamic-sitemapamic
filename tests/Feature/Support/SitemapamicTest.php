<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapDocument;
use MityDigital\Sitemapamic\Exceptions\SitemapBusyException;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use Statamic\Facades\Site;

/** Read a document's stream, close it and return its contents. */
function readSitemapDocument(SitemapDocument $document): string
{
    $contents = stream_get_contents($document->stream);
    fclose($document->stream);

    return $contents;
}

beforeEach(function (): void {
    config([
        'sitemapamic.collections' => [],
        'sitemapamic.taxonomies' => [],
        'sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'sitemap-cache'],
    ]);
    Storage::fake('sitemap-cache');

    $this->builds = 0;
    $this->pages = ['a', 'b'];
    app(Sitemapamic::class)->addDynamicRoutes(function (): array {
        $this->builds++;

        return array_map(fn (string $page): SitemapamicUrl => new SitemapamicUrl('http://localhost/'.$page), $this->pages);
    });
    $this->sitemapamic = app(Sitemapamic::class);
    $this->site = Site::get('default');
});

it('returns the index and a child file with their byte lengths', function (): void {
    $index = $this->sitemapamic->document($this->site, 'sitemap.xml');
    $child = $this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml');

    $indexXml = readSitemapDocument($index);
    $childXml = readSitemapDocument($child);

    expect($index->bytes)->toBe(strlen($indexXml))
        ->and($indexXml)->toContain('<loc>http://localhost/sitemap-dynamic-1.xml</loc>')
        ->and($child->bytes)->toBe(strlen($childXml))
        ->and($childXml)->toContain('<loc>http://localhost/a</loc>', '<loc>http://localhost/b</loc>');
});

it('returns null for an unknown file', function (): void {
    expect($this->sitemapamic->document($this->site, 'sitemap-missing-1.xml'))->toBeNull();
});

it('returns null for the index of an empty site', function (): void {
    $this->pages = [];

    expect($this->sitemapamic->document($this->site, 'sitemap.xml'))->toBeNull()
        ->and($this->sitemapamic->manifest($this->site)->isEmpty())->toBeTrue();
});

it('does not rebuild for a second request', function (): void {
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap.xml'));
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap.xml'));

    expect($this->builds)->toBe(1);
});

it('rebuilds after being marked stale and serves the new content', function (): void {
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));
    $this->pages = ['c'];

    $this->sitemapamic->markStale($this->site);

    expect(readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml')))->toContain('http://localhost/c')
        ->and($this->builds)->toBe(2);
});

it('serves the stale file while another request holds the build lock', function (): void {
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));
    $this->sitemapamic->markStale($this->site);
    $this->pages = ['c'];
    $lock = app(SitemapCache::class)->lock('default');
    $lock->get();

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));

    expect($xml)->toContain('http://localhost/a')
        ->and($xml)->not->toContain('http://localhost/c')
        ->and($this->builds)->toBe(1);
    $lock->release();
});

it('waits up to ten seconds then throws when there is no manifest and the lock is held', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = app(SitemapCache::class)->lock('default');
    $lock->get();
    $started = now();

    // Laravel gives up one 250ms sleep before the deadline
    expect(fn () => $this->sitemapamic->document($this->site, 'sitemap.xml'))->toThrow(SitemapBusyException::class)
        ->and($started->diffInMilliseconds(now()))->toBeGreaterThanOrEqual(9750)->toBeLessThanOrEqual(10000)
        ->and($this->builds)->toBe(0);
    $lock->release();
});

it('builds again when the version changes during a build and publishes the second build', function (): void {
    $cache = app(SitemapCache::class);
    $this->sitemapamic->addDynamicRoutes(function () use ($cache): array {
        // a content change lands while the first build is running
        if ($this->builds === 1) {
            $cache->markStale('default');
        }

        return [];
    });

    $manifest = $this->sitemapamic->manifest($this->site);

    expect($this->builds)->toBe(2)
        ->and($cache->manifest('default'))->toEqual($manifest)
        ->and($cache->isFresh($manifest))->toBeTrue()
        ->and(Storage::disk('sitemap-cache')->directories('sitemapamic/default'))->toBe([$manifest->directory]);
});

it('does not publish when the version keeps changing and serves the stale manifest', function (): void {
    $cache = app(SitemapCache::class);
    $stale = $this->sitemapamic->manifest($this->site);
    $cache->markStale('default');
    $this->sitemapamic->addDynamicRoutes(function () use ($cache): array {
        $cache->markStale('default');

        return [];
    });

    $served = $this->sitemapamic->manifest($this->site);

    expect($served)->toEqual($stale)
        ->and($cache->manifest('default'))->toEqual($stale)
        ->and(Storage::disk('sitemap-cache')->directories('sitemapamic/default'))->toBe([$stale->directory]);
});

it('throws busy when the version keeps changing and there is nothing to serve', function (): void {
    $cache = app(SitemapCache::class);
    $this->sitemapamic->addDynamicRoutes(function () use ($cache): array {
        $cache->markStale('default');

        return [];
    });

    expect(fn () => $this->sitemapamic->document($this->site, 'sitemap.xml'))->toThrow(SitemapBusyException::class)
        ->and($cache->manifest('default'))->toBeNull()
        ->and(Storage::disk('sitemap-cache')->allFiles())->toBe([]);
});

it('still returns the document when the cache store throws', function (): void {
    Exceptions::fake();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache store down'));

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));

    expect($xml)->toContain('http://localhost/a');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'cache store down');
});

it('still returns the document when the disk cannot be written', function (): void {
    Exceptions::fake();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturnFalse();
    $disk->shouldReceive('deleteDirectory')->andReturnTrue();
    Storage::set('sitemap-cache', $disk);

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap.xml'));

    expect($xml)->toContain('http://localhost/sitemap-dynamic-1.xml')
        ->and(app(SitemapCache::class)->manifest('default'))->toBeNull();
    Exceptions::assertReportedCount(1);
});

it('keeps the previous files and serves without the cache when the manifest cannot be published', function (): void {
    Exceptions::fake();
    $previous = $this->sitemapamic->manifest($this->site);
    $this->pages = ['c'];
    $this->sitemapamic->markStale($this->site);
    refuseForeverCacheWrites();

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));

    expect($xml)->toContain('http://localhost/c')
        ->and(app(SitemapCache::class)->manifest('default'))->toEqual($previous)
        ->and(Storage::disk('sitemap-cache')->directories('sitemapamic/default'))->toBe([$previous->directory]);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Unable to publish the sitemap manifest for site default.');
});

it('serves a file missing from the disk without the cache and rebuilds on the next request', function (): void {
    Exceptions::fake();
    $manifest = $this->sitemapamic->manifest($this->site);
    Storage::disk('sitemap-cache')->delete($manifest->directory.'/sitemap-dynamic-1.xml');

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));
    readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));

    expect($xml)->toContain('http://localhost/a')
        ->and($this->builds)->toBe(3)
        ->and(Storage::disk('sitemap-cache')->exists(app(SitemapCache::class)->manifest('default')->directory.'/sitemap-dynamic-1.xml'))->toBeTrue();
});

it('touches neither the cache nor the disk when caching is disabled', function (): void {
    config(['sitemapamic.cache.enabled' => false]);
    Cache::spy();

    $xml = readSitemapDocument($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml'));
    $manifest = $this->sitemapamic->manifest($this->site);

    expect($xml)->toContain('http://localhost/a')
        ->and($manifest->file('sitemap-dynamic-1.xml')->count)->toBe(2)
        ->and(Storage::disk('sitemap-cache')->allFiles())->toBe([]);
    Cache::shouldNotHaveReceived('get');
    Cache::shouldNotHaveReceived('lock');
    Cache::shouldNotHaveReceived('forever');
});

it('returns the same manifest from manifest() as the files it serves', function (): void {
    $manifest = $this->sitemapamic->manifest('default');

    expect($manifest)->toBeInstanceOf(Manifest::class)
        ->and($manifest->site)->toBe('default')
        ->and(array_keys($manifest->files))->toBe(['sitemap-dynamic-1.xml'])
        ->and($this->sitemapamic->document($this->site, 'sitemap-dynamic-1.xml')->bytes)->toBe($manifest->files['sitemap-dynamic-1.xml']->bytes);
});

it('clears every file and the manifest immediately', function (): void {
    $manifest = $this->sitemapamic->manifest($this->site);

    $this->sitemapamic->clearCache('default');

    expect(app(SitemapCache::class)->manifest('default'))->toBeNull()
        ->and(Storage::disk('sitemap-cache')->exists($manifest->directory))->toBeFalse();
});

it('rejects an unknown site handle', function (string $method): void {
    expect(fn () => $this->sitemapamic->{$method}('missing'))->toThrow(InvalidArgumentException::class, 'Unknown sitemap site: missing');
})->with(['manifest', 'clearCache', 'markStale', 'url']);
