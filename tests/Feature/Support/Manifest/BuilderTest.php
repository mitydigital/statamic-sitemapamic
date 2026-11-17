<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use MityDigital\Sitemapamic\Data\SitemapFile;
use MityDigital\Sitemapamic\Support\Content\SourceResolver;
use MityDigital\Sitemapamic\Support\Content\UrlIterator;
use MityDigital\Sitemapamic\Support\Manifest\Builder;
use MityDigital\Sitemapamic\Support\Manifest\Writer;
use MityDigital\Sitemapamic\Support\SiteViews;
use MityDigital\Sitemapamic\Support\Xml\Renderer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/** Collects what generate() hands to $store, keyed by filename. */
class GeneratedSitemapFiles
{
    /** @var array<string, string> */
    public array $contents = [];

    public function store(string $filename, $stream): void
    {
        $this->contents[$filename] = stream_get_contents($stream);
        fclose($stream);
    }
}

function generateSitemaps(string $site, GeneratedSitemapFiles $stored, Writer $writer = new Writer): array
{
    $builder = new Builder(app(SourceResolver::class), app(UrlIterator::class), app(Renderer::class), new SiteViews, $writer);

    return $builder->generate(Site::get($site), $stored->store(...));
}

/** @return list<string> */
function sitemapLocations(string $xml): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    return array_map(fn (DOMElement $loc): string => $loc->textContent, iterator_to_array($document->getElementsByTagName('loc')));
}

function reloadSitemapRoutes(): void
{
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->group(dirname(__DIR__, 4).'/routes/web.php');
    Route::getRoutes()->refreshNameLookups();
}

beforeEach(function (): void {
    config([
        'statamic.system.multisite' => true,
        'sitemapamic.collections' => ['articles'],
        'sitemapamic.taxonomies' => [],
    ]);
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => 'http://localhost/'],
        'regional' => ['locale' => 'de_DE', 'url' => 'https://regional.example/nested/'],
    ]);
    File::ensureDirectoryExists($this->fixturePath.'/collections/articles/default');
    File::ensureDirectoryExists($this->fixturePath.'/collections/articles/regional');
    File::ensureDirectoryExists($this->fixturePath.'/taxonomies/tags');
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional]\nroute: '/articles/{slug}'\ntemplate: article\ntaxonomies: [tags]\n");
    File::put($this->fixturePath.'/taxonomies/tags.yaml', "title: Tags\nsites: [default, regional]\n");
    $this->template('article');
    $this->template('tags/show');
    $this->template('articles/tags/show');
    $this->makeEntry = function (string $id, array $data = [], string $site = 'default') {
        $entry = Entry::make()->id($id)->collection('articles')->locale($site)->slug($id)->published(true)
            ->data(array_replace(['title' => $id, 'updated_at' => 1700000000], $data));
        $entry->saveQuietly();

        return $entry;
    };
});

it('generates one child file and an index for a small source', function (): void {
    ($this->makeEntry)('a');
    ($this->makeEntry)('b');
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored);

    expect($result['files'])->toEqual([
        new SitemapFile('sitemap-collection-articles-1.xml', 'collection-articles', 2, strlen($stored->contents['sitemap-collection-articles-1.xml'])),
    ])
        ->and($result['index'])->toEqual(new SitemapFile('sitemap.xml', 'index', 1, strlen($stored->contents['sitemap.xml'])))
        ->and(array_keys($stored->contents))->toBe(['sitemap-collection-articles-1.xml', 'sitemap.xml'])
        ->and(sitemapLocations($stored->contents['sitemap-collection-articles-1.xml']))->toBe([
            'http://localhost/articles/a',
            'http://localhost/articles/b',
        ]);
});

it('splits a source across numbered files by the writer URL limit', function (): void {
    foreach (['a', 'b', 'c'] as $id) {
        ($this->makeEntry)($id);
    }
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored, new Writer(maxUrls: 2));

    expect(array_map(fn (SitemapFile $file): int => $file->count, $result['files']))->toBe([2, 1])
        ->and(sitemapLocations($stored->contents['sitemap-collection-articles-1.xml']))->toBe([
            'http://localhost/articles/a',
            'http://localhost/articles/b',
        ])
        ->and(sitemapLocations($stored->contents['sitemap-collection-articles-2.xml']))->toBe([
            'http://localhost/articles/c',
        ])
        ->and($result['index']->count)->toBe(2);
});

it('stores no files and no index when nothing is eligible', function (): void {
    ($this->makeEntry)('hidden', ['meta_include_in_xml_sitemap' => false]);
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored);

    expect($result)->toBe(['files' => [], 'index' => null])
        ->and($stored->contents)->toBe([]);
});

it('merges every source into one sitemap.xml in single mode', function (): void {
    config(['sitemapamic.collections' => ['articles' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('a', ['tags' => ['example']]);
    ($this->makeEntry)('b', ['tags' => ['example']]);
    $automatic = new GeneratedSitemapFiles;
    $automaticResult = generateSitemaps('default', $automatic);
    $expectedLocations = array_merge(...array_map(
        fn (SitemapFile $file): array => sitemapLocations($automatic->contents[$file->filename]),
        $automaticResult['files'],
    ));
    config(['sitemapamic.mode' => 'single']);
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored);

    $file = new SitemapFile('sitemap.xml', 'all', count($expectedLocations), strlen($stored->contents['sitemap.xml']));
    expect(count($automaticResult['files']))->toBe(3)
        ->and($result)->toEqual(['files' => [$file], 'index' => $file])
        ->and(array_keys($stored->contents))->toBe(['sitemap.xml'])
        ->and(simplexml_load_string($stored->contents['sitemap.xml'])->getName())->toBe('urlset')
        ->and(sitemapLocations($stored->contents['sitemap.xml']))->toBe($expectedLocations);
});

it('splits the merged file into numbered files with an index in single mode', function (): void {
    config(['sitemapamic.mode' => 'single']);
    foreach (['a', 'b', 'c'] as $id) {
        ($this->makeEntry)($id);
    }
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored, new Writer(maxUrls: 2));

    expect($result['files'])->toEqual([
        new SitemapFile('sitemap-all-1.xml', 'all', 2, strlen($stored->contents['sitemap-all-1.xml'])),
        new SitemapFile('sitemap-all-2.xml', 'all', 1, strlen($stored->contents['sitemap-all-2.xml'])),
    ])
        ->and($result['index'])->toEqual(new SitemapFile('sitemap.xml', 'index', 2, strlen($stored->contents['sitemap.xml'])))
        ->and(array_keys($stored->contents))->toBe(['sitemap-all-1.xml', 'sitemap-all-2.xml', 'sitemap.xml'])
        ->and(sitemapLocations($stored->contents['sitemap.xml']))->toBe([
            'http://localhost/sitemap-all-1.xml',
            'http://localhost/sitemap-all-2.xml',
        ])
        ->and(sitemapLocations($stored->contents['sitemap-all-2.xml']))->toBe(['http://localhost/articles/c']);
});

it('stores every numbered file and lists them in the index when single mode needs more than two files', function (): void {
    config(['sitemapamic.mode' => 'single']);
    foreach (['a', 'b', 'c', 'd'] as $id) {
        ($this->makeEntry)($id);
    }
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored, new Writer(maxUrls: 1));

    $filenames = ['sitemap-all-1.xml', 'sitemap-all-2.xml', 'sitemap-all-3.xml', 'sitemap-all-4.xml'];
    expect(array_map(fn (SitemapFile $file): string => $file->filename, $result['files']))->toBe($filenames)
        ->and($result['index'])->toEqual(new SitemapFile('sitemap.xml', 'index', 4, strlen($stored->contents['sitemap.xml'])))
        ->and(array_keys($stored->contents))->toBe([...$filenames, 'sitemap.xml'])
        ->and(sitemapLocations($stored->contents['sitemap.xml']))->toBe(array_map(fn (string $filename): string => 'http://localhost/'.$filename, $filenames))
        ->and(array_merge(...array_map(fn (string $filename): array => sitemapLocations($stored->contents[$filename]), $filenames)))->toBe([
            'http://localhost/articles/a',
            'http://localhost/articles/b',
            'http://localhost/articles/c',
            'http://localhost/articles/d',
        ]);
});

it('stores no files and no index in single mode when nothing is eligible', function (): void {
    config(['sitemapamic.mode' => 'single']);
    ($this->makeEntry)('hidden', ['meta_include_in_xml_sitemap' => false]);
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored);

    expect($result)->toBe(['files' => [], 'index' => null])
        ->and($stored->contents)->toBe([]);
});

it('lists every child file URL in the index', function (): void {
    config(['sitemapamic.collections' => ['articles' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('a', ['tags' => ['example']]);
    ($this->makeEntry)('b', ['tags' => ['example']]);
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps('default', $stored, new Writer(maxUrls: 1));

    $expected = array_map(fn (SitemapFile $file): string => 'http://localhost/'.$file->filename, $result['files']);
    expect(sitemapLocations($stored->contents['sitemap.xml']))->toBe($expected)
        ->and($expected)->toBe([
            'http://localhost/sitemap-collection-articles-1.xml',
            'http://localhost/sitemap-collection-articles-2.xml',
            'http://localhost/sitemap-collection-articles-taxonomy-tags-1.xml',
            'http://localhost/sitemap-taxonomy-tags-1.xml',
        ]);
});

it('builds absolute URLs for nested-prefix and separate-domain sites', function (string $site, string $base): void {
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => 'http://localhost/'],
        'nested' => ['locale' => 'en_GB', 'url' => '/nested/'],
        'regional' => ['locale' => 'de_DE', 'url' => 'https://regional.example/nested/'],
    ]);
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, nested, regional]\nroute: '/articles/{slug}'\ntemplate: article\n");
    File::ensureDirectoryExists($this->fixturePath.'/collections/articles/'.$site);
    reloadSitemapRoutes();
    ($this->makeEntry)('page', [], $site);
    $stored = new GeneratedSitemapFiles;

    $result = generateSitemaps($site, $stored);

    expect(array_keys($stored->contents))->toBe(['sitemap-collection-articles-1.xml', 'sitemap.xml'])
        ->and(sitemapLocations($stored->contents['sitemap.xml']))->toBe([$base.'/sitemap-collection-articles-1.xml'])
        ->and(sitemapLocations($stored->contents['sitemap-collection-articles-1.xml']))->toBe([$base.'/articles/page'])
        ->and($result['index']->count)->toBe(1);
})->with([
    'nested prefix' => ['nested', 'http://localhost/nested'],
    'separate domain' => ['regional', 'https://regional.example/nested'],
]);

it('throws naming both sources when two sources produce the same filename', function (): void {
    File::put($this->fixturePath.'/collections/articles-taxonomy-tags.yaml', "title: Clash\nsites: [default]\nroute: '/clash/{slug}'\ntemplate: article\n");
    config(['sitemapamic.collections' => ['articles' => ['taxonomies' => ['tags']], 'articles-taxonomy-tags']]);
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('a', ['tags' => ['example']]);
    $clash = Entry::make()->id('clash')->collection('articles-taxonomy-tags')->locale('default')->slug('clash')->published(true)->data(['title' => 'Clash']);
    $clash->saveQuietly();

    expect(fn () => generateSitemaps('default', new GeneratedSitemapFiles))
        ->toThrow(RuntimeException::class, 'Sources collection articles with taxonomy tags and collection articles-taxonomy-tags both produce sitemap-collection-articles-taxonomy-tags-1.xml.');
});

it('restores the current site and view finder after generating', function (): void {
    ($this->makeEntry)('a', [], 'regional');
    $originalSite = Site::current();
    $originalFinder = view()->getFinder();
    reloadSitemapRoutes();

    generateSitemaps('regional', new GeneratedSitemapFiles);

    expect(Site::current())->toBe($originalSite)
        ->and(view()->getFinder())->toBe($originalFinder);
});

it('uses a record view override from the site view folder when the collection default is missing', function (): void {
    reloadSitemapRoutes();
    Collection::find('articles')->template('missing')->saveQuietly();
    $this->template('regional/record_override');
    ($this->makeEntry)('example', ['template' => 'record_override'], 'regional');
    $stored = new GeneratedSitemapFiles;

    generateSitemaps('regional', $stored);

    expect(sitemapLocations($stored->contents['sitemap-collection-articles-1.xml']))->toBe(['https://regional.example/nested/articles/example']);
});

it('uses a term template override from the site view folder when the taxonomy default is absent', function (): void {
    reloadSitemapRoutes();
    config(['sitemapamic.collections' => [], 'sitemapamic.taxonomies' => ['tags']]);
    File::delete($this->fixturePath.'/views/tags/show.blade.php');
    $this->template('regional/custom_term');
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->in('regional')->set('template', 'custom_term')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('related', ['tags' => ['example']], 'regional');
    $stored = new GeneratedSitemapFiles;

    generateSitemaps('regional', $stored);

    expect(sitemapLocations($stored->contents['sitemap-taxonomy-tags-1.xml']))->toBe(['https://regional.example/nested/tags/example']);
});

it('checks an alternate template in its own site view folder', function (string $template, bool $listed): void {
    $this->template('regional/regional_only');
    $this->template('default/default_only');
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional')->origin($original);
    $translation->set('template', $template)->saveQuietly();
    $stored = new GeneratedSitemapFiles;

    generateSitemaps('default', $stored);

    $document = new DOMDocument;
    $document->loadXML($stored->contents['sitemap-collection-articles-1.xml']);
    $alternates = $document->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link');
    expect($alternates)->toHaveCount($listed ? 2 : 0);
})->with([
    'template in the alternate site folder' => ['regional_only', true],
    'template only in the site being built' => ['default_only', false],
]);
