<?php

use Illuminate\Support\Facades\File;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Content\SourceResolver;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(function (): void {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'Main', 'locale' => 'en_AU', 'url' => '/'],
        'regional' => ['name' => 'Regional', 'locale' => 'de_DE', 'url' => '/nested/region/'],
    ]);
    File::ensureDirectoryExists($this->fixturePath.'/collections');
    File::ensureDirectoryExists($this->fixturePath.'/taxonomies');
    File::put($this->fixturePath.'/collections/blog.yaml', "title: Blog\nsites: [default, regional]\nroute: '/articles/{slug}'\ntaxonomies: [tags]\ntemplate: missing\n");
    File::put($this->fixturePath.'/collections/hidden.yaml', "title: Hidden\nsites: [default]\n");
    File::put($this->fixturePath.'/collections/local.yaml', "title: Local\nsites: [default]\nroute: '/local/{slug}'\n");
    File::put($this->fixturePath.'/taxonomies/tags.yaml', "title: Tags\nsites: [default, regional]\n");
    File::put($this->fixturePath.'/taxonomies/categories.yaml', "title: Categories\nsites: [default]\n");
});

it('discovers collection, contextual and global sources independently', function (array $collections, array $taxonomies, array $expectedSources): void {
    config(['sitemapamic.collections' => $collections, 'sitemapamic.taxonomies' => $taxonomies]);

    $sources = app(SourceResolver::class)->resolve(Site::get('regional'));
    $sourceNames = array_map(fn (Source $source): array => [$source->collection, $source->taxonomy], $sources);

    expect($sourceNames)->toBe($expectedSources);
    foreach ($sources as $source) {
        expect($source->site)->toBe('regional');
    }
})->with([
    'none' => [[], [], []],
    'plain collection only' => [['blog'], [], [['blog', null]]],
    'context only alongside entries' => [['blog' => ['taxonomies' => ['tags']]], [], [['blog', null], ['blog', 'tags']]],
    'nested wildcard' => [['blog' => ['taxonomies' => ['*']]], [], [['blog', null], ['blog', 'tags']]],
    'collection wildcard without globals' => [['*'], [], [['blog', null], ['blog', 'tags']]],
    'global wildcard without collections' => [[], ['*'], [[null, 'tags']]],
    'explicit global only' => [[], ['tags'], [[null, 'tags']]],
    'all independent sources' => [['*'], ['*'], [['blog', null], ['blog', 'tags'], [null, 'tags']]],
    'missing handles beside valid ones' => [['missing', 'blog'], ['missing', 'tags'], [['blog', null], [null, 'tags']]],
    'only missing handles' => [['missing'], ['missing'], []],
    'collection wildcard with an exclusion' => [['*', 'blog' => false], ['*'], [[null, 'tags']]],
    'collection wildcard with contextual taxonomies removed' => [['*', 'blog' => ['taxonomies' => []]], [], [['blog', null]]],
    'collection wildcard keeps term pages for other options' => [['*', 'blog' => ['frequency' => 'yearly']], [], [['blog', null], ['blog', 'tags']]],
    'global wildcard with an exclusion' => [['*'], ['*', 'tags' => false], [['blog', null], ['blog', 'tags']]],
]);

it('skips missing, site-disabled, unroutable and unattached selections', function (): void {
    config([
        'sitemapamic.collections' => ['missing', 'local', 'hidden', 'blog' => ['taxonomies' => ['categories']]],
        'sitemapamic.taxonomies' => ['missing', 'categories'],
    ]);
    $resolver = app(SourceResolver::class);

    expect($resolver->resolve(Site::get('regional')))->toEqual([
        new Source('regional', collection: 'blog'),
    ]);

    config(['sitemapamic.collections' => ['hidden'], 'sitemapamic.taxonomies' => []]);
    expect($resolver->resolve(Site::get('default')))->toBe([]);
});

it('retains an empty source even when its default template is missing', function (): void {
    config(['sitemapamic.collections' => ['blog'], 'sitemapamic.taxonomies' => []]);

    expect(app(SourceResolver::class)->resolve(Site::get('default')))->toEqual([
        new Source('default', collection: 'blog'),
    ]);
});

it('does not discard sources with record template overrides or site-only views', function (): void {
    $this->template('regional/entry_override');
    $this->template('regional/term_override');
    $entry = Entry::make()->collection('blog')->locale('regional')->template('entry_override');
    $term = Term::make('example')->taxonomy('tags')->in('regional')->template('term_override');
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $viewFinder = view()->getFinder();
    $originalPaths = $viewFinder->getPaths();
    $originalHints = $viewFinder->getHints();
    $originalSite = Site::current();

    $sources = app(SourceResolver::class)->resolve(Site::get('regional'));

    expect($sources)->toHaveCount(3)
        ->and($entry->template())->toBe('entry_override')
        ->and($term->template())->toBe('term_override')
        ->and($viewFinder->getPaths())->toBe($originalPaths)
        ->and($viewFinder->getHints())->toBe($originalHints)
        ->and(Site::current())->toBe($originalSite);
});

it('leaves shared taxonomy context unchanged in either discovery order', function (): void {
    $taxonomy = Taxonomy::find('tags');
    $originalContext = $taxonomy->collection();
    $resolver = app(SourceResolver::class);
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $combined = $resolver->resolve(Site::get('default'));
    config(['sitemapamic.collections' => []]);
    $globals = $resolver->resolve(Site::get('default'));
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => ['tags']]]]);

    expect($resolver->resolve(Site::get('default')))->toEqual($combined)
        ->and($globals)->toEqual([new Source('default', taxonomy: 'tags')])
        ->and($taxonomy->collection())->toBe($originalContext);
});

it('characterises native unmounted contextual prefixes and term templates', function (): void {
    $collection = Collection::find('blog');
    $globalTerm = Term::make('example')->taxonomy('tags')->in('regional');
    $contextualTerm = clone $globalTerm;
    $contextualTerm->collection($collection);

    expect($collection->uri('regional'))->toBeNull()
        ->and($globalTerm->route())->toBe('/tags/{slug}')
        ->and($contextualTerm->route())->toBe('blog/tags/{slug}')
        ->and($globalTerm->template())->toBe('tags.show')
        ->and($contextualTerm->template())->toBe('blog.tags.show');
});

it('uses native mounted and root-mounted taxonomy routes without excluding their sources', function (string $mountRoute, string $expectedPrefix): void {
    File::put($this->fixturePath.'/collections/pages.yaml', "title: Pages\nsites: [default, regional]\nroute: '$mountRoute'\n");
    $mount = Entry::make()->id('mount-page')->collection('pages')->locale('regional')->slug('landing')->published(true);
    $mount->saveQuietly();
    $collection = Collection::find('blog')->mount($mount->id());
    $term = Term::make('example')->taxonomy('tags')->in('regional')->collection($collection);
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => []]);

    expect($collection->uri('regional'))->toBe($expectedPrefix)
        ->and($term->route())->toBe($expectedPrefix.'/tags/{slug}')
        ->and(app(SourceResolver::class)->resolve(Site::get('regional')))->toHaveCount(2);
})->with([
    'localised nested mount' => ['/regional-pages/{slug}', '/regional-pages/landing'],
    'root mount' => ['/', '/'],
]);

it('retains native contextual and global candidates with overlapping prefixes', function (): void {
    File::put($this->fixturePath.'/collections/tags.yaml', "title: Tags prefix\nsites: [default, regional]\nroute: '/other/{slug}'\ntaxonomies: [tags]\n");
    config(['sitemapamic.collections' => ['tags' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);
    $collection = Collection::find('tags');
    $term = Term::make('example')->taxonomy('tags')->in('regional');
    $contextualTerm = clone $term;
    $contextualTerm->collection($collection);

    expect($term->route())->toBe('/tags/{slug}')
        ->and($contextualTerm->route())->toBe('tags/tags/{slug}')
        ->and(app(SourceResolver::class)->resolve(Site::get('regional')))->toHaveCount(3);
});

it('retains taxonomy sources for missing or custom default term templates', function (?string $termTemplate): void {
    if ($termTemplate !== null) {
        File::append($this->fixturePath.'/taxonomies/tags.yaml', 'term_template: '.$termTemplate."\n");
    }
    $term = Term::make('example')->taxonomy('tags')->in('regional')->collection(Collection::find('blog'));
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => ['tags']]], 'sitemapamic.taxonomies' => ['tags']]);

    expect($term->template())->toBe($termTemplate ?? 'blog.tags.show')
        ->and(app(SourceResolver::class)->resolve(Site::get('regional')))->toHaveCount(3);
})->with(['native default' => [null], 'custom template' => ['custom.term'], 'missing default' => ['missing.term']]);

it('applies the same contextual site gate to explicit and automatic taxonomy selections', function (array $taxonomySelection): void {
    File::put($this->fixturePath.'/collections/blog.yaml', "title: Blog\nsites: [default, regional]\nroute: '/articles/{slug}'\ntaxonomies: [categories]\n");
    config(['sitemapamic.collections' => ['blog' => ['taxonomies' => $taxonomySelection]], 'sitemapamic.taxonomies' => []]);
    $resolver = app(SourceResolver::class);

    expect($resolver->resolve(Site::get('regional')))->toEqual([
        new Source('regional', collection: 'blog'),
    ]);
})->with(['explicit' => [['categories']], 'automatic' => [['*']]]);

it('orders collections by handle, each followed by its contextual taxonomies, then global taxonomies, then dynamic routes', function (): void {
    File::put($this->fixturePath.'/collections/articles.yaml', "title: Articles\nsites: [default]\nroute: '/articles/{slug}'\ntaxonomies: [tags, categories]\n");
    File::put($this->fixturePath.'/collections/blog.yaml', "title: Blog\nsites: [default, regional]\nroute: '/blog/{slug}'\ntaxonomies: [tags]\n");
    config([
        'sitemapamic.collections' => ['blog' => ['taxonomies' => ['*']], 'articles' => ['taxonomies' => ['tags', 'categories']]],
        'sitemapamic.taxonomies' => ['tags', 'categories'],
    ]);
    app(DynamicRoutes::class)->add(fn (): array => []);

    $names = array_map(fn (Source $source): string => $source->name(), app(SourceResolver::class)->resolve(Site::get('default')));

    expect($names)->toBe([
        'collection-articles',
        'collection-articles-taxonomy-categories',
        'collection-articles-taxonomy-tags',
        'collection-blog',
        'collection-blog-taxonomy-tags',
        'taxonomy-categories',
        'taxonomy-tags',
        'dynamic',
    ]);
});
