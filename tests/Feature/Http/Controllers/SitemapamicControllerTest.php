<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Tests\UsesSitemapSites;

uses(UsesSitemapSites::class);

/** @return list<string> */
function controllerSitemapLocations(string $xml): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    return array_map(fn (DOMElement $loc): string => $loc->textContent, iterator_to_array($document->getElementsByTagName('loc')));
}

beforeEach(function (): void {
    config(['sitemapamic.cache' => ['enabled' => true, 'key' => 'sitemapamic', 'ttl' => null, 'disk' => 'http-cache']]);
    Storage::fake('http-cache');
    app(DynamicRoutes::class)->add(fn ($site): array => [
        new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/first'),
        new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/second'),
    ]);
});

it('serves the index and a child file with the correct Content-Length', function (string $filename): void {
    $response = $this->get('http://example.test/'.$filename);
    $xml = $response->streamedContent();

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect($response->headers->get('Content-Length'))->toBe((string) strlen($xml))
        ->and((new DOMDocument)->loadXML($xml))->toBeTrue();
})->with(['index' => ['sitemap.xml'], 'child' => ['sitemap-dynamic-1.xml']]);

it('answers HEAD with headers and no body', function (string $filename): void {
    $length = strlen($this->get('http://example.test/'.$filename)->streamedContent());

    $response = $this->call('HEAD', 'http://example.test/'.$filename);

    $response->assertOk()->assertHeader('Content-Length', (string) $length);
    expect($response->getContent())->toBe('');
})->with(['index' => ['sitemap.xml'], 'child' => ['sitemap-dynamic-1.xml']]);

it('serves every URL from sitemap.xml itself in single mode', function (): void {
    config(['sitemapamic.mode' => 'single']);

    $response = $this->get('http://example.test/sitemap.xml');
    $xml = $response->streamedContent();

    $response->assertOk()->assertHeader('Content-Length', (string) strlen($xml));
    expect(simplexml_load_string($xml)->getName())->toBe('urlset')
        ->and(controllerSitemapLocations($xml))->toBe(['http://example.test/first', 'http://example.test/second']);
    $this->get('http://example.test/sitemap-dynamic-1.xml')->assertNotFound();
    $this->get('http://example.test/sitemap-all-1.xml')->assertNotFound();
});

it('returns 404 for an unknown child file', function (): void {
    $this->get('http://example.test/sitemap-missing-1.xml')->assertNotFound();
});

it('returns 404 for a site with nothing to list', function (): void {
    app()->forgetInstance(DynamicRoutes::class);
    app()->forgetInstance(Sitemapamic::class);

    $this->get('http://example.test/sitemap.xml')->assertNotFound();
});

it('does not rebuild for a second request', function (): void {
    $builds = 0;
    app(DynamicRoutes::class)->add(function () use (&$builds): array {
        $builds++;

        return [];
    });

    $this->get('http://example.test/sitemap.xml')->assertOk()->streamedContent();
    $this->get('http://example.test/sitemap-dynamic-1.xml')->assertOk()->streamedContent();

    expect($builds)->toBe(1);
});

it('serves the stale file while another request holds the build lock', function (): void {
    $before = $this->get('http://example.test/sitemap-dynamic-1.xml')->streamedContent();
    app(Sitemapamic::class)->markStale('default');
    app(DynamicRoutes::class)->add(fn (): array => [new SitemapamicUrl('http://example.test/new')]);
    $lock = app(SitemapCache::class)->lock('default');
    $lock->get();

    $after = $this->get('http://example.test/sitemap-dynamic-1.xml')->assertOk()->streamedContent();

    expect($after)->toBe($before);
    $lock->release();
});

it('returns 503 with Retry-After when there is no manifest and the lock is held', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = app(SitemapCache::class)->lock('default');
    $lock->get();

    $this->get('http://example.test/sitemap.xml')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '10')
        ->assertContent('');
    $lock->release();
});

it('still returns 200 when the cache store throws', function (): void {
    Exceptions::fake();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache store down'));

    $xml = $this->get('http://example.test/sitemap-dynamic-1.xml')->assertOk()->streamedContent();

    expect($xml)->toContain('http://example.test/first');
    Exceptions::assertReportedCount(1);
});

it('serves without the cache or disk when caching is disabled', function (): void {
    config(['sitemapamic.cache.enabled' => false]);

    $xml = $this->get('http://example.test/sitemap-dynamic-1.xml')->assertOk()->streamedContent();

    expect($xml)->toContain('http://example.test/second')
        ->and(Storage::disk('http-cache')->allFiles())->toBe([])
        ->and(app(SitemapCache::class)->manifest('default'))->toBeNull();
});

it('resolves every URL listed in the index', function (string $site, string $base): void {
    $index = $this->get($base.'/sitemap.xml')->assertOk()->streamedContent();
    $children = controllerSitemapLocations($index);

    expect($children)->toBe([$base.'/sitemap-dynamic-1.xml']);
    foreach ($children as $child) {
        $xml = $this->get($child)->assertOk()->streamedContent();

        expect(controllerSitemapLocations($xml))->toBe([$base.'/first', $base.'/second']);
    }
})->with([
    'root' => ['default', 'http://example.test'],
    'nested prefix' => ['editorial_eu', 'http://example.test/nested/region'],
    'shorter nested prefix' => ['nested_parent', 'http://example.test/nested'],
    'separate domain' => ['archive', 'https://island.example.test/branch'],
    'separate port' => ['preview', 'http://preview.example.test:8088/deep'],
]);

it('serves each site its own sitemap from the shared host', function (): void {
    $nested = $this->get('http://example.test/nested/sitemap-dynamic-1.xml')->streamedContent();
    $region = $this->get('http://example.test/nested/region/sitemap-dynamic-1.xml')->streamedContent();

    expect(controllerSitemapLocations($nested))->toBe(['http://example.test/nested/first', 'http://example.test/nested/second'])
        ->and(controllerSitemapLocations($region))->toBe(['http://example.test/nested/region/first', 'http://example.test/nested/region/second']);
});

it('serves each site its own sitemap at its own URL', function (string $base): void {
    $xml = $this->get($base.'/sitemap.xml')->assertOk()->streamedContent();

    expect(controllerSitemapLocations($xml))->toBe([$base.'/sitemap-dynamic-1.xml']);
    $this->get($base.'/sitemap-dynamic-1.xml')->assertOk();
})->with([
    'default' => ['http://example.test'],
    'nested' => ['http://example.test/nested'],
    'nested deeper' => ['http://example.test/nested/region'],
    'other domain' => ['https://island.example.test/branch'],
    'other port' => ['http://preview.example.test:8088/deep'],
]);

it('serves a relative site on a host other than app.url, with canonical locations', function (string $path, string $canonical): void {
    $xml = $this->get('http://www.example.test'.$path.'/sitemap.xml')->assertOk()->streamedContent();

    expect(controllerSitemapLocations($xml))->toBe([$canonical.'/sitemap-dynamic-1.xml']);
})->with([
    'root' => ['', 'http://example.test'],
    'prefixed' => ['/nested/region', 'http://example.test/nested/region'],
]);

it('does not serve a prefix that belongs to a site on another host', function (string $url): void {
    $this->get($url)->assertNotFound();
})->with([
    'another domain prefix' => ['http://example.test/branch/sitemap.xml'],
    'another port prefix' => ['http://example.test/deep/sitemap-dynamic-1.xml'],
    'prefix not on this host' => ['https://island.example.test/deep/sitemap.xml'],
]);

it('serves a request whose host comes only from an overridden getHttpHost, as the static site generator makes', function (): void {
    $request = (new class extends Request
    {
        public function getHttpHost(): string
        {
            return 'example.test';
        }
    })::create('/nested/region/sitemap.xml');

    expect($request->getHost())->toBe('localhost');

    $response = app(Kernel::class)->handle($request);
    ob_start();
    $response->sendContent();
    $xml = ob_get_clean();

    expect($response->getStatusCode())->toBe(200)
        ->and(controllerSitemapLocations($xml))->toBe(['http://example.test/nested/region/sitemap-dynamic-1.xml']);
});
