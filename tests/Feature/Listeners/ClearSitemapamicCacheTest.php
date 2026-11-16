<?php

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use MityDigital\Sitemapamic\Listeners\ClearSitemapamicCache;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;
use MityDigital\Sitemapamic\Support\Manifest\Writer;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use Statamic\Events;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

beforeEach(function (): void {
    config(['app.url' => 'http://localhost', 'statamic.system.multisite' => true, 'sitemapamic.collections' => ['articles'], 'sitemapamic.taxonomies' => [], 'sitemapamic.cache.enabled' => true, 'sitemapamic.cache.disk' => 'listener-cache', 'sitemapamic.cache.ttl' => null]);
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => '/'],
        'regional' => ['locale' => 'de_DE', 'url' => '/de/'],
        'unrelated' => ['locale' => 'fr_FR', 'url' => 'http://other.example'],
    ]);
    Storage::fake('listener-cache');
    File::ensureDirectoryExists($this->fixturePath.'/collections');
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default, regional]\nroute: '/articles/{slug}'\ntemplate: article\ntaxonomies: [tags]\n");
    $this->template('article');
    $this->template('tags/show');
    $this->template('articles/tags/show');
    File::ensureDirectoryExists($this->fixturePath.'/taxonomies');
    File::put($this->fixturePath.'/taxonomies/tags.yaml', "title: Tags\nsites: [default, regional, unrelated]\n");
    $this->buildAll = function (): void {
        foreach (Site::all() as $site) {
            app(Sitemapamic::class)->manifest($site);
        }
    };
    $this->freshness = function (array $handles = ['default', 'regional', 'unrelated']): array {
        $cache = app(SitemapCache::class);
        $result = [];
        foreach ($handles as $handle) {
            $manifest = $cache->manifest($handle);
            $result[$handle] = match (true) {
                $manifest === null => 'gone',
                $cache->isFresh($manifest) => 'fresh',
                default => 'stale',
            };
        }

        return $result;
    };
    $this->dispatch = function (object $event): void {
        $dispatcher = new Dispatcher(app());
        $dispatcher->subscribe(ClearSitemapamicCache::class);
        $dispatcher->dispatch($event);
    };
    $this->makeEntry = function (string $id, array $data = []) {
        $entry = Entry::make()->id($id)->collection('articles')->locale('default')->slug($id)->published(true)->data(array_replace(['title' => $id, 'updated_at' => 1700000000], $data));
        $entry->saveQuietly();

        return $entry;
    };
});

it('marks the entry collection sites stale for entry saves, deletions and schedule transitions', function (string $eventClass): void {
    $entry = ($this->makeEntry)('example');
    ($this->buildAll)();

    ($this->dispatch)(new $eventClass($entry));

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'fresh']);
})->with([Events\EntrySaved::class, Events\EntryDeleted::class, Events\EntryScheduleReached::class]);

it('marks every site stale for term, collection, taxonomy and tree events', function (string $eventClass): void {
    ($this->buildAll)();

    ($this->dispatch)(new $eventClass(null));

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'stale']);
})->with([
    Events\TermSaved::class, Events\TermDeleted::class, Events\LocalizedTermSaved::class, Events\LocalizedTermDeleted::class,
    Events\TermReferencesUpdated::class, Events\CollectionSaved::class, Events\CollectionDeleted::class,
    Events\TaxonomySaved::class, Events\TaxonomyDeleted::class, Events\CollectionTreeSaved::class, Events\CollectionTreeDeleted::class,
]);

it('leaves the cache untouched when a global set is saved', function (): void {
    ($this->buildAll)();

    ($this->dispatch)(new Events\GlobalSetSaved(null));

    expect(($this->freshness)())->toBe(['default' => 'fresh', 'regional' => 'fresh', 'unrelated' => 'fresh']);
});

it('marks every site stale for moved tree entries and site creation or changes', function (string $eventClass): void {
    ($this->buildAll)();
    $event = $eventClass === Events\CollectionTreeEntriesMovedOrRemoved::class ? new $eventClass([], []) : new $eventClass(Site::get('default'));

    ($this->dispatch)($event);

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'stale']);
})->with([Events\CollectionTreeEntriesMovedOrRemoved::class, Events\SiteCreated::class, Events\SiteSaved::class]);

it('clears a deleted site after it disappears from the site list and marks the rest stale', function (): void {
    ($this->buildAll)();
    $deleted = Site::get('unrelated');
    $deletedDirectory = app(SitemapCache::class)->manifest('unrelated')->directory;
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => '/'],
        'regional' => ['locale' => 'de_DE', 'url' => '/de/'],
    ]);

    ($this->dispatch)(new Events\SiteDeleted($deleted));

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'gone'])
        ->and(Storage::disk('listener-cache')->exists($deletedDirectory))->toBeFalse();
});

it('marks every site stale when another collection is mounted on the entry', function (): void {
    $entry = ($this->makeEntry)('mount');
    $mounted = Collection::make('mounted')->sites(['unrelated'])->routes('/mounted/{slug}')->mount($entry->id());
    $mounted->saveQuietly();
    ($this->buildAll)();

    ($this->dispatch)(new Events\EntrySaved($entry));

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'stale']);
});

it('updates real cached file counts after entry creation, deletion and republication', function (): void {
    app()->instance(Writer::class, new Writer(maxUrls: 2));
    $first = ($this->makeEntry)('a');
    ($this->makeEntry)('b');
    $service = app(Sitemapamic::class);
    $counts = fn (): array => array_map(fn ($file): int => $file->count, array_values($service->manifest('default')->files));
    expect($counts())->toBe([2]);
    $third = Entry::make()->id('c')->collection('articles')->locale('default')->slug('c')->published(true)->data(['title' => 'c']);

    $third->save();
    expect($counts())->toBe([2, 1]);

    $third->delete();
    expect($counts())->toBe([2]);

    $first->published(false)->save();
    expect($counts())->toBe([1]);

    $first->published(true)->save();
    expect($counts())->toBe([2]);
});

it('picks up new entries and collections found by a wildcard', function (): void {
    config(['sitemapamic.collections' => ['*']]);
    $service = app(Sitemapamic::class);
    expect($service->manifest('default')->files)->toBe([]);

    $entry = Entry::make()->id('new')->collection('articles')->locale('default')->slug('new')->published(true)->data(['title' => 'New']);
    $entry->save();
    expect(array_keys($service->manifest('default')->files))->toBe(['sitemap-collection-articles-1.xml']);

    $collection = Collection::make('new_collection')->sites(['default'])->routes('/new/{slug}')->template('article');
    $collection->saveQuietly();
    Entry::make()->id('in-new')->collection('new_collection')->locale('default')->slug('in-new')->published(true)->data(['title' => 'In new'])->saveQuietly();
    $collection->save();

    expect(array_keys($service->manifest('default')->files))->toContain('sitemap-collection-new_collection-1.xml');
});

it('keeps a null TTL manifest through time travel until the schedule event is dispatched', function (): void {
    $this->freezeTime();
    Collection::find('articles')->dated(true)->futureDateBehavior('private')->saveQuietly();
    $entry = Entry::make()->id('future')->collection('articles')->locale('default')->slug('future')->published(true)->date(now()->addDay())->data(['title' => 'Future']);
    $entry->saveQuietly();
    expect(app(Sitemapamic::class)->manifest('default')->files)->toBe([]);

    $this->travel(2)->days();
    expect(app(Sitemapamic::class)->manifest('default')->files)->toBe([]);

    Event::dispatch(new Events\EntryScheduleReached($entry));

    expect(app(Sitemapamic::class)->manifest('default')->files)->toHaveCount(1);
});

it('guards the optional package and marks its known collection sites stale when available', function (): void {
    $eventClass = 'MityDigital\\StatamicScheduledCacheInvalidator\\Events\\ScheduledCacheInvalidated';
    $listener = new ClearSitemapamicCache;
    if (! class_exists($eventClass)) {
        expect($listener->subscribe(new Dispatcher(app())))->not->toHaveKey($eventClass);
        require __DIR__.'/../../ScheduledCacheInvalidated.php';
    }
    expect($listener->subscribe(new Dispatcher(app())))->toHaveKey($eventClass);
    ($this->buildAll)();

    ($this->dispatch)(new $eventClass(['articles']));

    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'fresh']);

    ($this->buildAll)();
    ($this->makeEntry)('quiet');
    expect(app(Sitemapamic::class)->manifest('default')->files)->toBe([]);
    ($this->dispatch)(new $eventClass(['articles']));
    expect(app(Sitemapamic::class)->manifest('default')->files)->toHaveCount(1);

    ($this->buildAll)();
    ($this->dispatch)(new $eventClass(['deleted_collection']));
    expect(($this->freshness)())->toBe(['default' => 'stale', 'regional' => 'stale', 'unrelated' => 'stale']);
});

it('recomputes both global and contextual taxonomy relevance after relationship edits', function (): void {
    config(['sitemapamic.collections' => ['articles' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $term = Term::make('example')->taxonomy('tags');
    $term->in('default')->set('title', 'Example');
    $term->save();
    $entry = ($this->makeEntry)('related', ['tags' => ['example']]);
    $service = app(Sitemapamic::class);
    expect($service->manifest('default')->files)->toHaveCount(3);

    $entry->set('tags', [])->save();

    expect(array_keys($service->manifest('default')->files))->toBe(['sitemap-collection-articles-1.xml']);
});
