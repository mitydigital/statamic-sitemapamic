<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\UrlIterator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Endpoint\URL;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Read a source as a sitemap request would.
 * A request starts with an empty Blink; within a test it would still hold entries from before they were changed.
 *
 * @return list<SitemapamicUrl>
 */
function eloquentSitemapUrls(Source $source): array
{
    Blink::flush();

    return iterator_to_array(app(UrlIterator::class)->forSource($source));
}

/** On Eloquent, updated_at is the row's own column, which saving sets to now. */
function setEloquentUpdatedAt(string $table, string $column, string $value, int $timestamp): void
{
    DB::table($table)->where($column, $value)->update(['updated_at' => Carbon::createFromTimestamp($timestamp)]);
}

/** The same sites and content as the Stache UrlIteratorTest, stored by the Eloquent driver. */
beforeEach(function (): void {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => 'http://localhost/'],
        'regional' => ['locale' => 'de_DE', 'url' => 'https://regional.example/nested/'],
    ]);
    // a translation only owns the fields its blueprint marks as localizable, so mark the ones the rules read
    Blueprint::setDirectory($this->fixturePath.'/blueprints');
    File::ensureDirectoryExists($this->fixturePath.'/blueprints/collections/articles');
    $localizable = fn (string $handle, string $type): string => "          - handle: $handle\n            field:\n              type: $type\n              localizable: true\n";
    File::put($this->fixturePath.'/blueprints/collections/articles/article.yaml', "title: Article\ntabs:\n  main:\n    sections:\n      - fields:\n"
        .$localizable('title', 'text').$localizable('meta_include_in_xml_sitemap', 'toggle').$localizable('protect', 'text').$localizable('template', 'template'));
    Taxonomy::make('tags')->sites(['default', 'regional'])->save();
    Collection::make('articles')->sites(['default', 'regional'])->routes('/articles/{slug}')->template('article')->taxonomies(['tags'])->save();
    $this->template('article');
    $this->template('tags/show');
    $this->template('articles/tags/show');
    $this->source = new Source('default', collection: 'articles');
    $this->makeEntry = function (string $entryId, array $data = [], string $site = 'default', ?EntryContract $origin = null, string $collection = 'articles'): EntryContract {
        $data = array_replace(['title' => $entryId, 'updated_at' => 1700000000], $data);
        $entry = Entry::make()->id($entryId)->collection($collection)->locale($site)->slug($entryId)->data($data)->published(true);
        if ($origin !== null) {
            $entry->origin($origin);
        }
        $entry->saveQuietly();
        if (is_numeric($data['updated_at'])) {
            setEloquentUpdatedAt('entries', 'id', $entryId, $data['updated_at']);
        }

        return $entry;
    };
    $this->makeTerm = function (string $slug, array $data = []): void {
        $term = Term::make($slug)->taxonomy('tags');
        $term->in('default')->data(array_replace(['title' => $slug, 'updated_at' => 1600000000], $data));
        $term->save();
        setEloquentUpdatedAt('taxonomy_terms', 'slug', $slug, $data['updated_at'] ?? 1600000000);
    };
});

it('produces reciprocal alternates using eligible real localisations', function (): void {
    $original = ($this->makeEntry)('original');
    ($this->makeEntry)('translation', [], 'regional', $original);
    $originalRecords = eloquentSitemapUrls($this->source);
    $translatedRecords = eloquentSitemapUrls(new Source('regional', collection: 'articles'));

    expect($originalRecords[0]->alternates)->toBe([
        ['hreflang' => 'en-AU', 'href' => 'http://localhost/articles/original'],
        ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/articles/translation'],
    ])->and($translatedRecords[0]->alternates)->toBe($originalRecords[0]->alternates);
});

it('omits a localisation that does not pass the same eligibility checks', function (string $exclusion): void {
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional', $original);
    match ($exclusion) {
        'draft' => $translation->published(false),
        'include' => $translation->set('meta_include_in_xml_sitemap', false),
        'template' => $translation->set('template', 'missing_translation_view'),
        'protection' => $translation->set('protect', 'password'),
    };
    $translation->saveQuietly();

    $records = eloquentSitemapUrls($this->source);

    expect($records[0]->alternates)->toBe([]);
})->with(['draft', 'include', 'template', 'protection']);

it('uses raw mapping values first and computes only missing mapped fields once', function (): void {
    ($this->makeEntry)('example', ['meta_include_in_xml_sitemap' => true, 'meta_priority' => 0]);
    // registered after saving: the driver evaluates computed values while saving
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

    $records = eloquentSitemapUrls($this->source);

    expect($records[0]->priority)->toBe(0.0)
        ->and($records[0]->changefreq)->toBe('daily')
        ->and($calls)->toBe(['frequency']);
});

it('checks alternate inclusion once without computing alternate optional metadata', function (): void {
    $original = ($this->makeEntry)('original');
    ($this->makeEntry)('translation', [], 'regional', $original);
    // registered after saving: the driver evaluates computed values while saving
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

    $records = eloquentSitemapUrls($this->source);

    // the driver may rebuild the original while loading the translation, evaluating its computed values again,
    // so check the addon's own calls come first and the translation's optional metadata is never computed
    expect($records)->toHaveCount(1)
        ->and($records[0]->alternates)->toHaveCount(2)
        ->and(array_slice($calls, 0, 4))->toBe(['include:default', 'frequency:default', 'priority:default', 'include:regional'])
        ->and(array_values(array_filter($calls, fn (string $call): bool => str_ends_with($call, ':regional'))))->toBe(['include:regional']);
});

it('lists only relevant term pages with the newest published related-entry date', function (): void {
    ($this->makeTerm)('example', ['title' => 'Example']);
    ($this->makeEntry)('included', ['tags' => ['example'], 'updated_at' => 1700000000, 'meta_include_in_xml_sitemap' => false]);
    $draft = ($this->makeEntry)('draft', ['tags' => ['example'], 'updated_at' => 1800000000])->published(false);
    $draft->saveQuietly();
    setEloquentUpdatedAt('entries', 'id', 'draft', 1800000000);

    $records = eloquentSitemapUrls(new Source('default', taxonomy: 'tags'));

    expect($records)->toHaveCount(1)
        ->and($records[0]->loc)->toBe('http://localhost/tags/example')
        ->and(strtotime($records[0]->lastmod))->toBe(1700000000);
});

it('keeps global and contextual term relevance and dates separate in both iteration orders', function (): void {
    Collection::make('other')->sites(['default', 'regional'])->routes('/other/{slug}')->template('article')->taxonomies(['tags'])->save();
    ($this->makeTerm)('example');
    ($this->makeEntry)('article', ['tags' => ['example'], 'updated_at' => 1700000000]);
    ($this->makeEntry)('other', ['tags' => ['example'], 'updated_at' => 1800000000], collection: 'other');
    $global = new Source('default', taxonomy: 'tags');
    $contextual = new Source('default', collection: 'articles', taxonomy: 'tags');
    $firstContext = eloquentSitemapUrls($contextual);
    $globalRecords = eloquentSitemapUrls($global);
    $secondContext = eloquentSitemapUrls($contextual);

    expect(strtotime($globalRecords[0]->lastmod))->toBe(1800000000)
        ->and(strtotime($firstContext[0]->lastmod))->toBe(1700000000)
        ->and($secondContext)->toEqual($firstContext)
        ->and($globalRecords[0]->loc)->toBe('http://localhost/tags/example')
        ->and($firstContext[0]->loc)->toBe('http://localhost/articles/tags/example');
});

it('lists alternates for translations more than one level deep', function (): void {
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => 'http://localhost/'],
        'regional' => ['locale' => 'de_DE', 'url' => 'https://regional.example/nested/'],
        'archive' => ['locale' => 'fr_FR', 'url' => 'https://archive.example/'],
    ]);
    Collection::find('articles')->sites(['default', 'regional', 'archive'])->save();
    $original = ($this->makeEntry)('original');
    $translation = ($this->makeEntry)('translation', [], 'regional', $original);
    ($this->makeEntry)('grandchild', [], 'archive', $translation);
    $expected = [
        ['hreflang' => 'en-AU', 'href' => 'http://localhost/articles/original'],
        ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/articles/translation'],
        ['hreflang' => 'fr-FR', 'href' => 'https://archive.example/articles/grandchild'],
    ];

    foreach (['default', 'regional', 'archive'] as $site) {
        $records = eloquentSitemapUrls(new Source($site, collection: 'articles'));

        expect($records)->toHaveCount(1)
            ->and($records[0]->alternates)->toBe($expected);
    }
});

it('picks the related entry with the newest updated_at for a term date', function (): void {
    ($this->makeTerm)('example', ['title' => 'Example']);
    ($this->makeEntry)('older', ['tags' => ['example'], 'updated_at' => 1700000000]);
    ($this->makeEntry)('newer', ['tags' => ['example'], 'updated_at' => 1750000000]);
    ($this->makeEntry)('middle', ['tags' => ['example'], 'updated_at' => 1720000000]);

    $records = eloquentSitemapUrls(new Source('default', taxonomy: 'tags'));

    expect($records)->toHaveCount(1)
        ->and(strtotime($records[0]->lastmod))->toBe(1750000000);
});

it('reads fields mapped to columns', function (): void {
    config(['statamic.eloquent-driver.entries.map_data_to_columns' => true]);
    Schema::table('entries', fn (SchemaBlueprint $table) => $table->string('meta_priority')->nullable());
    ($this->makeEntry)('example', ['meta_priority' => 0.8]);

    $records = eloquentSitemapUrls($this->source);

    expect(DB::table('entries')->where('id', 'example')->value('meta_priority'))->toBe('0.8')
        ->and($records[0]->priority)->toBe(0.8);
});

it("ignores a translation's own data outside __localized_fields", function (): void {
    $original = ($this->makeEntry)('original');
    ($this->makeEntry)('translation', [], 'regional', $original);
    $data = json_decode(DB::table('entries')->where('id', 'translation')->value('data'), true);
    DB::table('entries')->where('id', 'translation')->update(['data' => json_encode([...$data, 'protect' => 'logged_in', '__localized_fields' => []])]);

    $records = eloquentSitemapUrls(new Source('regional', collection: 'articles'));

    expect($records)->toHaveCount(1)
        ->and($records[0]->loc)->toBe('https://regional.example/nested/articles/translation');
});

it('loads translations once per chunk', function (): void {
    for ($position = 0; $position < 30; $position++) {
        $original = ($this->makeEntry)('original-'.$position);
        ($this->makeEntry)('translation-'.$position, [], 'regional', $original);
    }
    Blink::flush();
    $entryQueries = 0;
    DB::listen(function ($query) use (&$entryQueries): void {
        if (str_contains($query->sql, '"entries"')) {
            $entryQueries++;
        }
    });

    $records = iterator_to_array(app(UrlIterator::class)->forSource($this->source));

    // allows the driver's one origin lookup per translation, but not in() per entry
    expect($records)->toHaveCount(30)
        ->and($records[0]->alternates)->toHaveCount(2)
        ->and($entryQueries)->toBeLessThanOrEqual(30 + 10);
});

it('uses the stored uri on eloquent', function (): void {
    $original = ($this->makeEntry)('original');
    ($this->makeEntry)('translation', [], 'regional', $original);
    // the driver routes by these columns, so the sitemap must list the same URLs
    DB::table('entries')->where('id', 'original')->update(['uri' => '/stored/original']);
    DB::table('entries')->where('id', 'translation')->update(['uri' => '/stored/translation']);

    $records = eloquentSitemapUrls($this->source);

    expect($records[0]->loc)->toBe('http://localhost/stored/original')
        ->and($records[0]->alternates)->toBe([
            ['hreflang' => 'en-AU', 'href' => 'http://localhost/stored/original'],
            ['hreflang' => 'de-DE', 'href' => 'https://regional.example/nested/stored/translation'],
        ]);
});

it('matches a term by the exact stored tag value, as the driver does', function (): void {
    ($this->makeTerm)('foo-bar', ['title' => 'Foo Bar']);
    ($this->makeEntry)('example', ['tags' => ['Foo Bar']]);

    expect(eloquentSitemapUrls(new Source('default', taxonomy: 'tags')))->toBe([]);
});

it('lists a term from a translated entry that inherits its tags', function (): void {
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->in('regional')->set('title', 'Beispiel');
    $term->save();
    $original = ($this->makeEntry)('original', ['tags' => ['example']]);
    ($this->makeEntry)('translation', [], 'regional', $original);

    expect(eloquentSitemapUrls(new Source('regional', taxonomy: 'tags')))->toHaveCount(1);
});

/** Insert entries as the driver stores them, without saving each one. */
function insertEloquentEntries(int $count): void
{
    $rows = [];
    for ($position = 0; $position < $count; $position++) {
        $id = 'entry-'.str_pad((string) $position, 5, '0', STR_PAD_LEFT);
        $rows[] = [
            'id' => $id, 'site' => 'default', 'published' => true, 'slug' => $id, 'uri' => '/articles/'.$id,
            'collection' => 'articles', 'data' => json_encode(['title' => $id]),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('entries')->insert($chunk);
    }
}

it('lists every entry once, in id order, across pages', function (int $count): void {
    insertEloquentEntries($count);

    $locations = array_map(fn (SitemapamicUrl $url): string => $url->loc, eloquentSitemapUrls($this->source));

    expect($locations)->toHaveCount($count)
        ->and(array_unique($locations))->toHaveCount($count)
        ->and($locations[0])->toBe('http://localhost/articles/entry-00000')
        ->and($locations[$count - 1])->toBe('http://localhost/articles/entry-'.str_pad((string) ($count - 1), 5, '0', STR_PAD_LEFT));
})->with([999, 1000, 1001, 2000]);

it('never loads every entry id in one query', function (): void {
    insertEloquentEntries(1500);
    $unlimited = [];
    DB::listen(function ($query) use (&$unlimited): void {
        // every query for entries is either limited to a page or to the ids of one
        if (str_contains($query->sql, 'from "entries"') && ! str_contains($query->sql, 'limit') && ! str_contains($query->sql, ' in (')) {
            $unlimited[] = $query->sql;
        }
    });

    expect(eloquentSitemapUrls($this->source))->toHaveCount(1500)
        ->and($unlimited)->toBe([]);
});

it('does not keep every URL in memory', function (): void {
    insertEloquentEntries(1500);

    eloquentSitemapUrls($this->source);

    $cache = new ReflectionProperty(URL::class, 'externalAppUrlsCache');
    expect(count($cache->getValue() ?? []))->toBeLessThan(1000);
});
