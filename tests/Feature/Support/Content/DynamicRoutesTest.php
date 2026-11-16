<?php

use Illuminate\Support\ServiceProvider;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Content\SourceResolver;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use Statamic\Facades\Site;

beforeEach(function (): void {
    config(['statamic.system.multisite' => true, 'sitemapamic.collections' => [], 'sitemapamic.taxonomies' => []]);
    Site::setSites([
        'default' => ['locale' => 'en_AU', 'url' => '/'],
        'regional' => ['locale' => 'de_DE', 'url' => '/region/'],
    ]);
});

it('selects dynamic callbacks only for their intended sites', function (array|string|null $selectedSites, array $expectedSites): void {
    $routes = app(DynamicRoutes::class);
    $routes->add(fn (): array => [], $selectedSites);
    $actualSites = [];
    foreach (Site::all() as $site) {
        if ($routes->sources($site) !== []) {
            $actualSites[] = $site->handle();
        }
    }

    expect($actualSites)->toBe($expectedSites);
})->with([
    'all by default' => [null, ['default', 'regional']],
    'all wildcard' => [['*'], ['default', 'regional']],
    'wildcard dominates' => [['unknown', '*'], ['default', 'regional']],
    'none' => [[], []],
    'one handle' => ['default', ['default']],
    'selected handles' => [['regional'], ['regional']],
    'unknown does not become all' => [['unknown'], []],
]);

it('preserves registration and yield order without consuming the whole callback', function (): void {
    $visited = [];
    $routes = app(DynamicRoutes::class);
    $routes->add(function ($site) use (&$visited): Generator {
        foreach (['first', 'second', 'third'] as $slug) {
            $visited[] = $slug;
            yield new SitemapamicUrl('https://example.com/'.$site->handle().'/'.$slug);
        }
    });
    $routes->add(fn (): array => [new SitemapamicUrl('https://example.com/last')]);
    $sources = $routes->sources(Site::get('default'));

    expect($sources)->toEqual([new Source('default', dynamic: true)])
        ->and($visited)->toBe([]);
    $urls = $routes->urls(Site::get('default'));

    expect($urls->current()->loc)->toBe('https://example.com/default/first')
        ->and($visited)->toBe(['first']);

    $locations = array_map(fn (SitemapamicUrl $url): string => $url->loc, iterator_to_array($urls));
    expect($locations)->toBe([
        'https://example.com/default/first',
        'https://example.com/default/second',
        'https://example.com/default/third',
        'https://example.com/last',
    ]);
});

it('does not invoke an unmatched callback', function (): void {
    $routes = app(DynamicRoutes::class);
    $routes->add(fn () => throw new RuntimeException('must not run'), 'regional');

    expect(iterator_to_array($routes->urls(Site::get('default'))))->toBe([])
        ->and($routes->sources(Site::get('default')))->toBe([]);
});

it('rejects callback output with an identifiable registration', function (Closure $callback): void {
    $routes = app(DynamicRoutes::class);
    $routes->add($callback);

    expect(fn () => iterator_to_array($routes->urls(Site::get('default'))))
        ->toThrow(InvalidArgumentException::class, 'registration 0');
})->with([
    'noniterable' => [fn () => 'invalid'],
    'wrong URL value' => [fn () => ['invalid']],
]);

it('preserves boot registrations across scopes', function (): void {
    $resolver = app(SourceResolver::class);
    $routes = app(DynamicRoutes::class);
    app(Sitemapamic::class)->addDynamicRoutes(fn (): array => [], 'default');

    expect(app(DynamicRoutes::class))->toBe($routes)
        ->and($resolver->resolve(Site::get('default')))->toEqual([
            new Source('default', dynamic: true),
        ]);

    $this->app->forgetScopedInstances();

    expect(app(DynamicRoutes::class))->toBe($routes)
        ->and(app(DynamicRoutes::class)->sources(Site::get('default')))->toHaveCount(1);
});

it('keeps service-provider registrations usable after resetting request-scoped services', function (): void {
    config(['sitemapamic.cache.enabled' => false]);
    $provider = new class(app()) extends ServiceProvider
    {
        public function boot(): void
        {
            MityDigital\Sitemapamic\Facades\Sitemapamic::addDynamicRoutes(fn ($site): array => [new SitemapamicUrl(rtrim($site->absoluteUrl(), '/').'/boot-page')]);
        }
    };
    app()->register($provider);
    $firstResolver = app(SourceResolver::class);
    $first = app(Sitemapamic::class)->manifest('default');
    app()->forgetScopedInstances();
    $second = app(Sitemapamic::class)->manifest('default');

    expect($first->files)->toHaveCount(1)
        ->and($second->files)->toEqual($first->files)
        ->and(app(SourceResolver::class))->not->toBe($firstResolver)
        ->and(app(DynamicRoutes::class)->sources(Site::get('default')))->toHaveCount(1);
});
