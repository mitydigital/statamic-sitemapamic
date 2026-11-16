<?php

use Illuminate\Support\Facades\File;
use Illuminate\View\ViewException;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Xml\Renderer;

trait UsesIsolatedXmlViews
{
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);
        $app->setBasePath($this->fixturePath);
        $app['config']->set('view.paths', [$app->resourcePath('views')]);
        File::ensureDirectoryExists($this->fixturePath.'/resources/views/vendor/sitemapamic');
        File::ensureDirectoryExists($app->storagePath('framework/cache'));
        File::ensureDirectoryExists($app->storagePath('framework/views'));
        $app['config']->set('view.compiled', $app->storagePath('framework/views'));
    }
}

uses(UsesIsolatedXmlViews::class);

it('renders a valid UTF-8 URL set with escaped text and XHTML alternates', function (): void {
    $renderer = app(Renderer::class);
    $url = new SitemapamicUrl('https://example.test/café?a=1&b="quoted"', '2026-11-14T10:00:00+10:30', 'daily', 0, [
        ['hreflang' => 'en-AU', 'href' => 'https://example.test/café?a=1&b="quoted"'],
        ['hreflang' => 'de-DE', 'href' => 'https://regional.test/übersicht?a=1&b=2'],
    ]);
    $row = $renderer->url($url);
    $document = new DOMDocument;

    expect($document->loadXML($renderer->urlsetStart().$row.$renderer->urlsetEnd()))->toBeTrue()
        ->and($document->encoding)->toBe('UTF-8')
        ->and($document->documentElement->namespaceURI)->toBe('http://www.sitemaps.org/schemas/sitemap/0.9')
        ->and($document->getElementsByTagName('loc')->item(0)->textContent)->toBe($url->loc)
        ->and($document->getElementsByTagName('priority')->item(0)->textContent)->toBe('0')
        ->and($document->getElementsByTagName('changefreq')->item(0)->textContent)->toBe('daily');
    $alternates = $document->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link');
    expect($alternates)->toHaveCount(2)
        ->and($alternates->item(0)->getAttribute('href'))->toBe($url->loc)
        ->and($alternates->item(1)->getAttribute('hreflang'))->toBe('de-DE')
        ->and($row)->toContain('&amp;', '&quot;');
});

it('escapes XML text and attributes without double-decoding input', function (): void {
    $text = "https://example.test/?q=A & B < C > D \"quoted\" 'single' &amp; café";
    $renderer = app(Renderer::class);
    $row = $renderer->url(new SitemapamicUrl($text, alternates: [['hreflang' => 'en', 'href' => $text]]));
    $document = new DOMDocument;

    expect($row)->toContain('A &amp; B &lt; C &gt; D &quot;quoted&quot; &#039;single&#039; &amp;amp; café')
        ->and($document->loadXML($renderer->urlsetStart().$row.$renderer->urlsetEnd()))->toBeTrue()
        ->and($document->getElementsByTagName('loc')->item(0)->textContent)->toBe($text)
        ->and($document->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link')->item(0)->getAttribute('href'))->toBe($text);
});

it('renders a valid index with no lastmod', function (): void {
    $renderer = app(Renderer::class);
    $location = 'https://example.test/sitemap-collection-pages-1.xml?a=1&b=2';
    $row = $renderer->sitemap($location);
    $document = new DOMDocument;

    expect($document->loadXML($renderer->indexStart().$row.$renderer->indexEnd()))->toBeTrue()
        ->and($document->documentElement->localName)->toBe('sitemapindex')
        ->and($document->getElementsByTagName('loc')->item(0)->textContent)->toBe($location)
        ->and($row)->not->toContain('lastmod');
});

it('renders the default envelopes exactly', function (): void {
    $renderer = app(Renderer::class);

    expect($renderer->urlsetStart())->toBe('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n")
        ->and($renderer->urlsetEnd())->toBe("</urlset>\n")
        ->and($renderer->indexStart())->toBe('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n")
        ->and($renderer->indexEnd())->toBe("</sitemapindex>\n");
});

it('uses a published urlset start view', function (): void {
    File::ensureDirectoryExists($this->fixturePath.'/resources/views/vendor/sitemapamic/urlset');
    File::put(
        $this->fixturePath.'/resources/views/vendor/sitemapamic/urlset/start.blade.php',
        "{!! '<'.'?xml version=\"1.0\" encoding=\"UTF-8\"?>' !!}\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"urn:custom-image\">\n",
    );
    view()->getFinder()->flush();
    $renderer = app(Renderer::class);

    $xml = $renderer->urlsetStart().$renderer->url(new SitemapamicUrl('http://localhost/a')).$renderer->urlsetEnd();

    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="urn:custom-image">')
        ->and((new DOMDocument)->loadXML($xml))->toBeTrue();
});

it('omits absent or disabled optional elements without losing zero priority', function (): void {
    $renderer = app(Renderer::class);
    $minimal = $renderer->url(new SitemapamicUrl('https://example.test/page', changefreq: false, priority: false));
    $zero = $renderer->url(new SitemapamicUrl('https://example.test/page', priority: 0));

    expect($minimal)->not->toContain('<lastmod>', '<changefreq>', '<priority>', '<xhtml:link')
        ->and($zero)->toContain('<priority>0</priority>');
});

it('measures multibyte and alternate markup in rendered bytes', function (): void {
    $renderer = app(Renderer::class);
    $plain = $renderer->url(new SitemapamicUrl('https://example.test/a'));
    $multibyte = $renderer->url(new SitemapamicUrl('https://example.test/é'));
    $alternate = $renderer->url(new SitemapamicUrl('https://example.test/a', alternates: [
        ['hreflang' => 'en', 'href' => 'https://example.test/a'],
        ['hreflang' => 'de', 'href' => 'https://regional.test/a'],
    ]));

    expect(strlen($multibyte) - strlen($plain))->toBe(1)
        ->and(strlen($multibyte))->toBeGreaterThan(mb_strlen($multibyte))
        ->and(strlen($alternate))->toBeGreaterThan(strlen($plain))
        ->and($alternate)->toBe($renderer->url(new SitemapamicUrl('https://example.test/a', alternates: [
            ['hreflang' => 'en', 'href' => 'https://example.test/a'],
            ['hreflang' => 'de', 'href' => 'https://regional.test/a'],
        ])));
});

it('publishes every view to the application view override directory', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'sitemapamic-views', '--force' => true])->assertExitCode(0);

    foreach (['urlset/start', 'urlset/url', 'urlset/end', 'index/start', 'index/sitemap', 'index/end'] as $view) {
        $this->assertFileExists($this->fixturePath.'/resources/views/vendor/sitemapamic/'.$view.'.blade.php');
    }
    expect(File::allFiles($this->fixturePath.'/resources/views/vendor/sitemapamic'))->toHaveCount(6)
        ->and(app(Renderer::class)->url(new SitemapamicUrl('https://example.test/a')))->toContain('<loc>https://example.test/a</loc>');
});

it('propagates exceptions from custom views', function (): void {
    File::ensureDirectoryExists($this->fixturePath.'/resources/views/vendor/sitemapamic/urlset');
    File::put($this->fixturePath.'/resources/views/vendor/sitemapamic/urlset/url.blade.php', '@php throw new RuntimeException("view failed"); @endphp');

    expect(fn () => app(Renderer::class)->url(new SitemapamicUrl('https://example.test/a')))->toThrow(ViewException::class, 'view failed');
});

it('trusts literal custom XML without parsing or repairing it', function (): void {
    File::ensureDirectoryExists($this->fixturePath.'/resources/views/vendor/sitemapamic/urlset');
    File::put($this->fixturePath.'/resources/views/vendor/sitemapamic/urlset/url.blade.php', '<url><unclosed>');

    expect(app(Renderer::class)->url(new SitemapamicUrl('https://example.test/a')))->toBe('<url><unclosed>');
});
