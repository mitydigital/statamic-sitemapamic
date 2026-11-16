<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Content\UrlIterator;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Tests\Fixtures\CustomUriEntry;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

beforeEach(function (): void {
    config(['statamic.system.multisite' => true]);
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
    $this->source = new Source('default', collection: 'articles');
    $this->makeEntry = function (string $entryId, array $data = [], string $site = 'default') {
        $entry = Entry::make()->id($entryId)->collection('articles')->locale($site)->slug($entryId)->data(array_replace(['title' => $entryId, 'updated_at' => 1700000000], $data))->published(true);
        $entry->saveQuietly();

        return $entry;
    };
});

it('lists entries in id order', function (): void {
    // slugs and titles sort differently from the ids, so neither the collection's sort nor the URLs can hide the order
    foreach (['z' => 'alpha', 'm' => 'zulu', 'a' => 'mike'] as $id => $slug) {
        ($this->makeEntry)($id, ['title' => $slug])->slug($slug)->saveQuietly();
    }

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect(array_map(fn (SitemapamicUrl $record): string => $record->loc, $records))->toBe([
        'http://localhost/articles/mike',
        'http://localhost/articles/zulu',
        'http://localhost/articles/alpha',
    ]);
});

it('applies include mapping without boolean coercion', function (mixed $include, bool $listed): void {
    ($this->makeEntry)('example', ['meta_include_in_xml_sitemap' => $include]);

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toHaveCount($listed ? 1 : 0);
})->with(['null' => [null, true], 'default' => ['default', true], 'boolean false' => [false, false], 'string false' => ['false', false], 'true' => [true, true], 'zero is not false' => [0, true]]);

it('omits drafts, redirects, protection and missing effective views', function (string $exclusion): void {
    $entry = ($this->makeEntry)('example');
    match ($exclusion) {
        'draft' => $entry->published(false),
        'redirect' => $entry->set('redirect', '/elsewhere'),
        'external' => $entry->set('redirect', 'https://elsewhere.example/'),
        '404' => $entry->set('redirect', '404'),
        'protected' => $entry->set('protect', 'password'),
        'template' => $entry->set('template', 'missing_view'),
    };
    $entry->saveQuietly();

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toBe([]);
})->with(['draft', 'redirect', 'external', '404', 'protected', 'template']);

it('preserves zero priority and omits invalid optional metadata', function (): void {
    ($this->makeEntry)('a', ['meta_priority' => 0, 'meta_change_frequency' => 'daily']);
    ($this->makeEntry)('b', ['meta_priority' => 2, 'meta_change_frequency' => 'occasionally']);
    $iterator = app(UrlIterator::class);
    $records = iterator_to_array($iterator->forSource($this->source));

    expect($records[0]->priority)->toBe(0.0)
        ->and($records[0]->changefreq)->toBe('daily')
        ->and($records[1]->priority)->toBeNull()
        ->and($records[1]->changefreq)->toBeNull();
});

it('falls back to the collection default, then the global default', function (): void {
    config([
        'sitemapamic.defaults' => ['changefreq' => 'monthly', 'priority' => '0.7'],
        'sitemapamic.collections' => ['*', 'articles' => ['changefreq' => 'yearly']],
    ]);
    ($this->makeEntry)('a');
    ($this->makeEntry)('b', ['meta_priority' => 0.2, 'meta_change_frequency' => 'daily']);
    ($this->makeEntry)('c', ['meta_priority' => 2, 'meta_change_frequency' => 'occasionally']);

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect(array_map(fn (SitemapamicUrl $record): array => [$record->changefreq, $record->priority], $records))->toBe([
        ['yearly', 0.7],
        ['daily', 0.2],
        ['yearly', 0.7],
    ]);
});

it('reads a change frequency stored as a one-item list', function (array $stored, ?string $expected): void {
    ($this->makeEntry)('a', ['meta_change_frequency' => $stored]);

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records[0]->changefreq)->toBe($expected);
})->with(['one item' => [['weekly'], 'weekly'], 'two items' => [['weekly', 'daily'], null], 'empty' => [[], null]]);

it('gives term pages their taxonomy defaults, in and out of a collection', function (?string $collection): void {
    config([
        'sitemapamic.defaults' => ['changefreq' => 'monthly', 'priority' => 0.7],
        'sitemapamic.collections' => ['*', 'articles' => ['changefreq' => 'yearly', 'priority' => 0.9]],
        'sitemapamic.taxonomies' => ['*', 'tags' => ['changefreq' => 'weekly']],
    ]);
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('a', ['tags' => ['example']]);

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source('default', collection: $collection, taxonomy: 'tags')));

    expect($records)->toHaveCount(1)
        ->and($records[0]->changefreq)->toBe('weekly')
        ->and($records[0]->priority)->toBe(0.7);
})->with(['global term pages' => [null], 'contextual term pages' => ['articles']]);

it('leaves dynamic routes as they are given', function (): void {
    config(['sitemapamic.defaults' => ['changefreq' => 'monthly', 'priority' => 0.7]]);
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl($site->absoluteUrl().'page')]);
    $source = app(DynamicRoutes::class)->sources(Site::get('default'))[0];

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect($records[0]->changefreq)->toBeNull()
        ->and($records[0]->priority)->toBeNull();
});

it('reads the changefreq mapping from its v3 name when the new one is missing', function (array $mappings, string $expected): void {
    config(['sitemapamic.mappings' => array_replace(['include' => 'meta_include_in_xml_sitemap', 'priority' => 'meta_priority'], $mappings)]);
    ($this->makeEntry)('a', ['old_frequency' => 'daily', 'new_frequency' => 'hourly']);

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records[0]->changefreq)->toBe($expected);
})->with([
    'only the v3 name' => [['change_frequency' => 'old_frequency'], 'daily'],
    'both names' => [['change_frequency' => 'old_frequency', 'changefreq' => 'new_frequency'], 'hourly'],
]);

it('does not fabricate translations from configured sites', function (): void {
    ($this->makeEntry)('example');
    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records[0]->alternates)->toBe([]);
});

it('produces reciprocal alternates using eligible real localisations', function (): void {
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional')->origin($original);
    $translation->saveQuietly();
    $iterator = app(UrlIterator::class);
    $originalRecords = iterator_to_array($iterator->forSource($this->source));
    $translatedRecords = iterator_to_array($iterator->forSource(new Source('regional', collection: 'articles')));

    expect($originalRecords[0]->alternates)->toBe([
        ['hreflang' => 'en-AU', 'href' => 'http://localhost/articles/original'],
        ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/articles/translation'],
    ])->and($translatedRecords[0]->alternates)->toBe($originalRecords[0]->alternates);
});

it('lists only relevant term pages with the newest published related-entry date', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example')->set('updated_at', 1600000000);
    $term->save();
    ($this->makeEntry)('included', ['tags' => ['example'], 'updated_at' => 1700000000, 'meta_include_in_xml_sitemap' => false]);
    $draft = ($this->makeEntry)('draft', ['tags' => ['example'], 'updated_at' => 1800000000])->published(false);
    $draft->saveQuietly();
    $source = new Source('default', taxonomy: 'tags');
    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect($records)->toHaveCount(1)
        ->and($records[0]->loc)->toBe('http://localhost/tags/example')
        ->and(strtotime($records[0]->lastmod))->toBe(1700000000);
});

it('checks dated listability against one evaluation instant with exact boundaries', function (string $behavior, int $offset, bool $listed): void {
    $this->freezeTime();
    Blueprint::setDirectory($this->fixturePath.'/blueprints');
    File::ensureDirectoryExists($this->fixturePath.'/blueprints/collections/articles');
    File::put($this->fixturePath.'/blueprints/collections/articles/article.yaml', "title: Article\ntabs:\n  main:\n    sections:\n      - fields:\n          - handle: date\n            field:\n              type: date\n              time_enabled: true\n              time_seconds_enabled: true\n");
    $collection = Collection::find('articles')->dated(true)->futureDateBehavior($behavior)->pastDateBehavior($behavior);
    $collection->saveQuietly();
    $entry = ($this->makeEntry)('dated')->date(now()->addSeconds($offset));
    $entry->saveQuietly();
    $source = $this->source;

    expect(iterator_to_array(app(UrlIterator::class)->forSource($source)))->toHaveCount($listed ? 1 : 0);
})->with([
    'public future' => ['public', 1, true],
    'public past' => ['public', -1, true],
    'private future' => ['private', 1, false],
    'unlisted future' => ['unlisted', 1, false],
    'private past' => ['private', -1, false],
    'unlisted past' => ['unlisted', -1, false],
    'private exact timestamp' => ['private', 0, false],
    'unlisted exact timestamp' => ['unlisted', 0, false],
]);

it('uses raw mapping values first and computes only missing mapped fields once', function (): void {
    $calls = [];
    Collection::computed('articles', [
        'meta_include_in_xml_sitemap' => function () use (&$calls): bool {
            $calls[] = 'include';

            return true;
        },
        'meta_priority' => function () use (&$calls): float {
            $calls[] = 'priority';

            return 0.7;
        },
        'meta_change_frequency' => function () use (&$calls): string {
            $calls[] = 'frequency';

            return 'daily';
        },
        'unrelated' => fn () => throw new RuntimeException('must not compute'),
    ]);
    ($this->makeEntry)('example', ['meta_include_in_xml_sitemap' => true, 'meta_priority' => 0]);

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records[0]->priority)->toBe(0.0)
        ->and($records[0]->changefreq)->toBe('daily')
        ->and($calls)->toBe(['frequency']);
});

it('omits a localisation that does not pass the same eligibility checks', function (string $exclusion): void {
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional')->origin($original);
    match ($exclusion) {
        'draft' => $translation->published(false),
        'include' => $translation->set('meta_include_in_xml_sitemap', false),
        'template' => $translation->set('template', 'missing_translation_view'),
        'protection' => $translation->set('protect', 'password'),
    };
    $translation->saveQuietly();

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records[0]->alternates)->toBe([]);
})->with(['draft', 'include', 'template', 'protection']);

it('fails explicitly on invalid dynamic URLs and restores site and view state', function (string $url): void {
    app(Sitemapamic::class)->addDynamicRoutes(fn (): array => [new SitemapamicUrl($url)]);
    $source = app(DynamicRoutes::class)->sources(Site::get('default'))[0];
    $originalSite = Site::current();
    $originalFinder = view()->getFinder();

    expect(fn () => iterator_to_array(app(UrlIterator::class)->forSource($source)))->toThrow(InvalidArgumentException::class)
        ->and(Site::current())->toBe($originalSite)
        ->and(view()->getFinder())->toBe($originalFinder);
})->with(['relative' => ['/path'], 'empty' => [''], 'bad scheme' => ['ftp://localhost/path'], 'control characters' => ["http://localhost/\0"]]);

it('lists every structured entry at its tree URI', function (): void {
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional]\nroute: '{parent_uri}/{slug}'\ntemplate: article\ntaxonomies: [tags]\nstructure:\n  max_depth: 3\nsort_by: title\nsort_dir: desc\n");
    File::ensureDirectoryExists($this->fixturePath.'/trees/default');
    File::ensureDirectoryExists($this->fixturePath.'/trees/regional');
    File::put($this->fixturePath.'/trees/default/articles.yaml', "tree:\n  - entry: z\n    children:\n      - entry: a\n  - entry: b\n");
    File::put($this->fixturePath.'/trees/regional/articles.yaml', "tree:\n  - entry: regional-b\n  - entry: regional-a\n");
    foreach (['default' => ['a', 'b', 'z', 'outside'], 'regional' => ['regional-a', 'regional-b']] as $site => $ids) {
        foreach ($ids as $id) {
            File::put($this->fixturePath.'/collections/articles/'.$site.'/'.$id.'.md', "---\nid: $id\ntitle: $id\n---\n");
        }
    }
    $iterator = app(UrlIterator::class);
    $defaultRecords = iterator_to_array($iterator->forSource($this->source));
    $regionalRecords = iterator_to_array($iterator->forSource(new Source('regional', collection: 'articles')));

    expect(array_map(fn (SitemapamicUrl $record): string => $record->loc, $defaultRecords))->toEqualCanonicalizing([
        'http://localhost/z',
        'http://localhost/z/a',
        'http://localhost/b',
        'http://localhost/outside',
    ])->and(array_map(fn (SitemapamicUrl $record): string => $record->loc, $regionalRecords))->toEqualCanonicalizing([
        'https://regional.example/nested/regional-a',
        'https://regional.example/nested/regional-b',
    ]);
});

it('uses raw term inclusion rather than fabricating entry-style inheritance', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('meta_include_in_xml_sitemap', false)->set('updated_at', 1600000000);
    $term->save();
    ($this->makeEntry)('related', ['tags' => ['example']], 'regional');
    $source = new Source('regional', taxonomy: 'tags');

    expect(iterator_to_array(app(UrlIterator::class)->forSource($source)))->toHaveCount(1);
});

it('keeps global and contextual term relevance and dates separate in both iteration orders', function (): void {
    File::put($this->fixturePath.'/collections/other.yaml', "title: Other\nsites: [default, regional]\nroute: '/other/{slug}'\ntemplate: article\ntaxonomies: [tags]\n");
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('updated_at', 1600000000);
    $term->save();
    ($this->makeEntry)('article', ['tags' => ['example'], 'updated_at' => 1700000000]);
    $otherEntry = Entry::make()->id('other')->collection('other')->locale('default')->slug('other')->published(true)->data(['title' => 'Other', 'tags' => ['example'], 'updated_at' => 1800000000]);
    $otherEntry->saveQuietly();
    $global = new Source('default', taxonomy: 'tags');
    $contextual = new Source('default', collection: 'articles', taxonomy: 'tags');
    $iterator = app(UrlIterator::class);
    $firstContext = iterator_to_array($iterator->forSource($contextual));
    $globalRecords = iterator_to_array($iterator->forSource($global));
    $secondContext = iterator_to_array($iterator->forSource($contextual));

    expect(strtotime($globalRecords[0]->lastmod))->toBe(1800000000)
        ->and(strtotime($firstContext[0]->lastmod))->toBe(1700000000)
        ->and($secondContext)->toEqual($firstContext)
        ->and($globalRecords[0]->loc)->toBe('http://localhost/tags/example')
        ->and($firstContext[0]->loc)->toBe('http://localhost/articles/tags/example');
});

it('omits terms whose only related entries are unpublished or on another site', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('regional-related', ['tags' => ['example']], 'regional');
    $draft = ($this->makeEntry)('draft-related', ['tags' => ['example']])->published(false);
    $draft->saveQuietly();
    $source = new Source('default', taxonomy: 'tags');

    expect(iterator_to_array(app(UrlIterator::class)->forSource($source)))->toBe([]);
});

it('lists a term whose only related entry is published but redirected or protected', function (string $exclusion): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    ($this->makeEntry)('related', ['tags' => ['example'], $exclusion => $exclusion === 'redirect' ? '/elsewhere' : 'password']);
    $source = new Source('default', taxonomy: 'tags');

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect($records)->toHaveCount(1)
        ->and($records[0]->loc)->toBe('http://localhost/tags/example');
})->with(['redirect', 'protect']);

it('omits a term whose only published related entry is hidden by its date behaviour', function (string $behavior, int $offset, bool $contextual): void {
    $this->freezeTime();
    Blueprint::setDirectory($this->fixturePath.'/blueprints');
    File::ensureDirectoryExists($this->fixturePath.'/blueprints/collections/articles');
    File::put($this->fixturePath.'/blueprints/collections/articles/article.yaml', "title: Article\ntabs:\n  main:\n    sections:\n      - fields:\n          - handle: date\n            field:\n              type: date\n              time_enabled: true\n              time_seconds_enabled: true\n");
    $collection = Collection::find('articles')->dated(true)->futureDateBehavior($behavior)->pastDateBehavior($behavior);
    $collection->saveQuietly();
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    $entry = ($this->makeEntry)('related', ['tags' => ['example']])->date(now()->addSeconds($offset));
    $entry->saveQuietly();
    $source = new Source('default', collection: $contextual ? 'articles' : null, taxonomy: 'tags');

    expect(iterator_to_array(app(UrlIterator::class)->forSource($source)))->toBe([]);
})->with([
    'unlisted future' => ['unlisted', 1],
    'unlisted past' => ['unlisted', -1],
    'unlisted exact timestamp' => ['unlisted', 0],
    'private future' => ['private', 1],
    'private past' => ['private', -1],
])->with(['global' => [false], 'contextual' => [true]]);

it('ignores an unlisted related entry when choosing the term date', function (): void {
    $this->freezeTime();
    Blueprint::setDirectory($this->fixturePath.'/blueprints');
    File::ensureDirectoryExists($this->fixturePath.'/blueprints/collections/articles');
    File::put($this->fixturePath.'/blueprints/collections/articles/article.yaml', "title: Article\ntabs:\n  main:\n    sections:\n      - fields:\n          - handle: date\n            field:\n              type: date\n              time_enabled: true\n              time_seconds_enabled: true\n");
    $collection = Collection::find('articles')->dated(true)->futureDateBehavior('unlisted')->pastDateBehavior('public');
    $collection->saveQuietly();
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example')->set('updated_at', 1500000000);
    $term->save();
    $listed = ($this->makeEntry)('listed', ['tags' => ['example'], 'updated_at' => 1600000000])->date(now()->subDay());
    $listed->saveQuietly();
    $unlisted = ($this->makeEntry)('unlisted', ['tags' => ['example'], 'updated_at' => 1700000000])->date(now()->addDay());
    $unlisted->saveQuietly();
    $source = new Source('default', taxonomy: 'tags');

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect($records)->toHaveCount(1)
        ->and(strtotime($records[0]->lastmod))->toBe(1600000000);
});

it('drops invalid dynamic metadata and keeps lastmod and alternates as supplied', function (): void {
    $alternate = ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/example'];
    $url = new SitemapamicUrl('http://localhost/example', 'tomorrow', 'bad', INF, [$alternate, $alternate]);
    app(Sitemapamic::class)->addDynamicRoutes(fn (): array => [$url]);
    $source = app(DynamicRoutes::class)->sources(Site::get('default'))[0];
    $iterator = app(UrlIterator::class);
    $records = iterator_to_array($iterator->forSource($source));

    expect($records[0]->alternates)->toBe([$alternate, $alternate])
        ->and($records[0]->lastmod)->toBe('tomorrow')
        ->and($records[0]->priority)->toBeNull()
        ->and($records[0]->changefreq)->toBeNull()
        ->and($url->priority)->toBe(INF)
        ->and($url->changefreq)->toBe('bad');
});

it('restores site context when a dynamic callback throws', function (): void {
    app(Sitemapamic::class)->addDynamicRoutes(fn () => throw new RuntimeException('callback failed'));
    $source = app(DynamicRoutes::class)->sources(Site::get('regional'))[0];
    $originalSite = Site::current();
    $originalFinder = view()->getFinder();

    expect(fn () => iterator_to_array(app(UrlIterator::class)->forSource($source)))->toThrow(RuntimeException::class, 'callback failed')
        ->and(Site::current())->toBe($originalSite)
        ->and(view()->getFinder())->toBe($originalFinder);
});

it('rejects a dynamic alternate whose href is not absolute', function (): void {
    app(Sitemapamic::class)->addDynamicRoutes(fn (): array => [new SitemapamicUrl('http://localhost/example', alternates: [['hreflang' => 'de', 'href' => '/relative']])]);
    $source = app(DynamicRoutes::class)->sources(Site::get('default'))[0];

    expect(fn () => iterator_to_array(app(UrlIterator::class)->forSource($source)))->toThrow(InvalidArgumentException::class, '/relative');
});

it('accepts dynamic URLs with non-ASCII paths and query strings', function (): void {
    $url = new SitemapamicUrl('https://example.test/café?a=1&b=2', alternates: [['hreflang' => 'de', 'href' => 'https://regional.example/straße']]);
    app(Sitemapamic::class)->addDynamicRoutes(fn (): array => [$url]);
    $source = app(DynamicRoutes::class)->sources(Site::get('default'))[0];

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect($records[0]->loc)->toBe('https://example.test/café?a=1&b=2');
});

it('profiles complete entry and term traversal including relation work', function (int $entryCount): void {
    for ($position = 0; $position < $entryCount; $position++) {
        $id = 'profile-'.str_pad((string) $position, 5, '0', STR_PAD_LEFT);
        File::put($this->fixturePath.'/collections/articles/default/'.$id.'.md', "---\nid: $id\ntitle: $id\ntags: [archive]\nupdated_at: 1700000000\n---\n");
    }
    $term = Term::make('archive')->taxonomy('tags');
    $term->in('default')->set('title', 'Archive')->set('updated_at', 1600000000);
    $term->save();
    config(['statamic.system.fake_sql_queries' => true]);
    $queries = [];
    Event::listen(QueryExecuted::class, function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $iterator = app(UrlIterator::class);
    $measurements = ['entries' => $entryCount];
    foreach (['cold', 'warm'] as $temperature) {
        foreach (['entry' => $this->source, 'term' => new Source('default', taxonomy: 'tags')] as $kind => $source) {
            $queries = [];
            $start = hrtime(true);
            $recordCount = 0;
            foreach ($iterator->forSource($source) as $record) {
                $recordCount++;
            }
            $measurements[$temperature.'_'.$kind] = ['milliseconds' => round((hrtime(true) - $start) / 1e6, 2), 'queries' => count($queries)];
            expect($recordCount)->toBe($kind === 'entry' ? $entryCount : 1);
        }
    }
    $measurements['peak_bytes'] = memory_get_peak_usage(true);
    if (getenv('SITEMAPAMIC_BENCHMARK') === '1') {
        fwrite(STDOUT, json_encode($measurements)."\n");
    }
})->with([50, 200]);

it('omits term pages with no usable effective template', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('template', 'missing_term_view');
    $term->save();
    ($this->makeEntry)('related', ['tags' => ['example']]);
    $source = new Source('default', taxonomy: 'tags');

    expect(iterator_to_array(app(UrlIterator::class)->forSource($source)))->toBe([]);
});

it('does not substitute inherited mapping values for the raw-first policy', function (): void {
    $original = ($this->makeEntry)('original', ['meta_include_in_xml_sitemap' => false, 'meta_priority' => 0.9]);
    $translation = ($this->makeEntry)('translation', [], 'regional')->origin($original);
    $translation->saveQuietly();

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source('regional', collection: 'articles')));

    expect($records)->toHaveCount(1)
        ->and($records[0]->priority)->toBeNull()
        ->and($records[0]->alternates)->toBe([]);
});

it('uses the file date when updated_at is missing or invalid', function (mixed $updatedAt): void {
    $entry = ($this->makeEntry)('example', ['updated_at' => $updatedAt]);
    touch($entry->path(), 1600000000);

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect(strtotime($records[0]->lastmod))->toBe(1600000000);
})->with(['missing' => [null], 'invalid' => ['not-a-timestamp']]);

it('uses the term file date when a term updated_at is invalid', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example')->set('updated_at', 'not-a-timestamp');
    $term->save();
    touch($term->path(), 1800000000);
    ($this->makeEntry)('related', ['tags' => ['example'], 'updated_at' => 1700000000]);
    $source = new Source('default', taxonomy: 'tags');

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect(strtotime($records[0]->lastmod))->toBe(1800000000);
});

it('characterises repository-loaded term publication separately from entry publication', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example')->set('published', false);
    $term->save();
    ($this->makeEntry)('related', ['tags' => ['example']]);
    $source = new Source('default', taxonomy: 'tags');

    $records = iterator_to_array(app(UrlIterator::class)->forSource($source));

    expect(Term::find('tags::example')->in('default')->status())->toBe('published')
        ->and($records)->toHaveCount(1);
});

it('passes a canonical site to dynamic callbacks without changing shared sites or using the deprecated URL setter', function (): void {
    Site::setSites(['default' => ['locale' => 'en_AU', 'url' => '/']]);
    $site = Site::get('default');
    $original = $site->rawConfig();
    app(DynamicRoutes::class)->add(fn ($site): array => [new SitemapamicUrl($site->absoluteUrl().'/page')]);
    $source = app(DynamicRoutes::class)->sources($site)[0];
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }

        return false;
    });

    try {
        $records = iterator_to_array(app(UrlIterator::class)->forSource($source));
    } finally {
        restore_error_handler();
    }

    expect($records[0]->loc)->toBe('http://localhost/page')
        ->and(Site::get('default'))->toBe($site)
        ->and($site->rawConfig())->toBe($original);
});

it('checks alternate inclusion once without computing alternate optional metadata', function (): void {
    $calls = [];
    Collection::computed('articles', [
        'meta_include_in_xml_sitemap' => function ($entry) use (&$calls): bool {
            $calls[] = 'include:'.$entry->locale();

            return true;
        },
        'meta_priority' => function ($entry) use (&$calls): float {
            $calls[] = 'priority:'.$entry->locale();

            return 0.5;
        },
        'meta_change_frequency' => function ($entry) use (&$calls): string {
            $calls[] = 'frequency:'.$entry->locale();

            return 'daily';
        },
    ]);
    $original = ($this->makeEntry)('original');
    ($this->makeEntry)('translation', [], 'regional')->origin($original)->saveQuietly();
    $calls = [];

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records)->toHaveCount(1)
        ->and($records[0]->alternates)->toHaveCount(2)
        ->and($calls)->toBe(['include:default', 'frequency:default', 'priority:default', 'include:regional']);
});

it('rejects unpublished entries before computing their sitemap inclusion', function (): void {
    Collection::computed('articles', ['meta_include_in_xml_sitemap' => fn () => throw new RuntimeException('must not compute inclusion')]);
    ($this->makeEntry)('draft')->published(false)->saveQuietly();

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toBe([]);
});

it('lists alternates for translations more than one level deep', function (): void {
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => 'http://localhost/'],
        'regional' => ['locale' => 'de_DE', 'url' => 'https://regional.example/nested/'],
        'archive' => ['locale' => 'fr_FR', 'url' => 'https://archive.example/'],
    ]);
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional, archive]\nroute: '/articles/{slug}'\ntemplate: article\ntaxonomies: [tags]\n");
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional')->origin($original);
    $translation->saveQuietly();
    ($this->makeEntry)('grandchild', [], 'archive')->origin($translation)->saveQuietly();
    $expected = [
        ['hreflang' => 'en-AU', 'href' => 'http://localhost/articles/original'],
        ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/articles/translation'],
        ['hreflang' => 'fr-FR', 'href' => 'https://archive.example/articles/grandchild'],
    ];
    $iterator = app(UrlIterator::class);

    foreach (['default', 'regional', 'archive'] as $site) {
        $records = iterator_to_array($iterator->forSource(new Source($site, collection: 'articles')));

        expect($records)->toHaveCount(1)
            ->and($records[0]->alternates)->toBe($expected);
    }
});

it('uses the blueprint template when the template is @blueprint', function (): void {
    Blueprint::setDirectory($this->fixturePath.'/blueprints');
    File::ensureDirectoryExists($this->fixturePath.'/blueprints/collections/articles');
    File::put($this->fixturePath.'/blueprints/collections/articles/article.yaml', "title: Article\n");
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional]\nroute: '/articles/{slug}'\ntemplate: '@blueprint'\n");
    ($this->makeEntry)('example', ['blueprint' => 'article']);
    $this->template('articles/article');

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toHaveCount(1);

    File::delete($this->fixturePath.'/views/articles/article.blade.php');
    app('view')->getFinder()->flush();

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toBe([]);
});

it('picks the related entry with the newest updated_at for a term date', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example')->set('updated_at', 1600000000);
    $term->save();
    ($this->makeEntry)('older', ['tags' => ['example'], 'updated_at' => 1700000000]);
    ($this->makeEntry)('newer', ['tags' => ['example'], 'updated_at' => 1750000000]);
    $undated = ($this->makeEntry)('undated', ['tags' => ['example'], 'updated_at' => null]);
    touch($undated->path(), 1800000000);

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source('default', taxonomy: 'tags')));

    expect($records)->toHaveCount(1)
        ->and(strtotime($records[0]->lastmod))->toBe(1800000000);
});

it('lists a term from a translated entry that inherits its tags', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->in('regional')->set('title', 'Beispiel');
    $term->save();
    $original = ($this->makeEntry)('original', ['tags' => ['example']]);
    ($this->makeEntry)('translation', [], 'regional')->origin($original)->saveQuietly();

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source('regional', taxonomy: 'tags')));

    expect($records)->toHaveCount(1);
});

it('respects a custom entry class', function (): void {
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional]\nroute: '/articles/{slug}'\ntemplate: article\nentry_class: ".CustomUriEntry::class."\n");
    ($this->makeEntry)('example');

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect(array_map(fn (SitemapamicUrl $record): string => $record->loc, $records))->toBe(['http://localhost/articles/example/custom']);
});

it('fails explicitly when a mapping names no field, but only once a record needs it', function (): void {
    config(['sitemapamic.mappings.priority' => '']);

    expect(iterator_to_array(app(UrlIterator::class)->forSource($this->source)))->toBe([]);

    ($this->makeEntry)('example');

    expect(fn () => iterator_to_array(app(UrlIterator::class)->forSource($this->source)))
        ->toThrow(InvalidArgumentException::class, 'sitemapamic.mappings.priority must name a field.');
});

it('keeps stache URIs correct when pluck order differs from id order', function (): void {
    // slugs sort in the reverse of their ids, so pairing two plucks would give entries each other's URLs
    $expected = [];
    for ($position = 1; $position <= 12; $position++) {
        $number = str_pad((string) $position, 2, '0', STR_PAD_LEFT);
        $reversed = str_pad((string) (13 - $position), 2, '0', STR_PAD_LEFT);
        $original = ($this->makeEntry)('e'.$number);
        $original->slug('original-'.$reversed)->saveQuietly();
        ($this->makeEntry)('r'.$number, [], 'regional')->slug('translation-'.$reversed)->origin($original)->saveQuietly();
        $expected['http://localhost/articles/original-'.$reversed] = [
            ['hreflang' => 'en-AU', 'href' => 'http://localhost/articles/original-'.$reversed],
            ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/articles/translation-'.$reversed],
        ];
    }

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    expect($records)->toHaveCount(12);
    foreach ($records as $record) {
        expect($record->alternates)->toBe($expected[$record->loc]);
    }
});

it('matches a term by the slug of the stored tag value', function (): void {
    $term = Term::make('foo-bar')->taxonomy('tags');
    $term->in('default')->set('title', 'Foo Bar');
    $term->save();
    ($this->makeEntry)('example', ['tags' => ['Foo Bar']]);

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source('default', taxonomy: 'tags')));

    expect(array_map(fn (SitemapamicUrl $record): string => $record->loc, $records))->toBe(['http://localhost/tags/foo-bar']);
});
