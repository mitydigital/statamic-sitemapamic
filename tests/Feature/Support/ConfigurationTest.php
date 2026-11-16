<?php

use MityDigital\Sitemapamic\Support\Configuration;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

trait WithSitemapamicConfig
{
    protected array $sitemapamicOverrides = [];

    protected function defineEnvironment(mixed $app): void
    {
        $app['config']->set('sitemapamic', $this->sitemapamicOverrides);
    }
}

uses(WithSitemapamicConfig::class);

beforeEach(function (): void {
    // made out of handle order, so a wildcard has to sort them
    foreach (['pages', 'blog'] as $handle) {
        Collection::make($handle)->save();
    }
    foreach (['tags', 'categories'] as $handle) {
        Taxonomy::make($handle)->save();
    }
});

it('selects every collection and taxonomy with the package defaults', function (): void {
    $packageConfig = require dirname(__DIR__, 3).'/config/sitemapamic.php';

    expect(config('sitemapamic'))->toBe($packageConfig)
        ->and(app(Configuration::class)->collections())->toBe(['blog' => null, 'pages' => null])
        ->and(app(Configuration::class)->taxonomies())->toBe(['categories', 'tags'])
        ->and(app(Configuration::class)->isSingleFile())->toBeFalse()
        ->and(app(Configuration::class)->ttl())->toBeNull();
});

it('keeps empty host selections while inheriting the other package settings', function (): void {
    $this->sitemapamicOverrides = ['collections' => [], 'taxonomies' => null];
    $this->refreshApplication();
    $packageConfig = require dirname(__DIR__, 3).'/config/sitemapamic.php';

    expect(app(Configuration::class)->collections())->toBe([])
        ->and(app(Configuration::class)->taxonomies())->toBe([])
        ->and(config('sitemapamic.cache'))->toBe($packageConfig['cache'])
        ->and(config('sitemapamic.mappings'))->toBe($packageConfig['mappings']);
});

it('reads the collections selection', function (mixed $configured, array $expected): void {
    config(['sitemapamic.collections' => $configured]);

    expect((new Configuration)->collections())->toBe($expected);
})->with([
    'null selects nothing' => [null, []],
    'empty selects nothing' => [[], []],
    'wildcard selects every collection with every attached taxonomy' => [['*'], ['blog' => null, 'pages' => null]],
    'plain handle selects entries only' => [['pages'], ['pages' => []]],
    'contextual taxonomies alongside entries' => [['blog' => ['taxonomies' => ['tags']]], ['blog' => ['tags']]],
    'empty contextual taxonomies' => [['blog' => ['taxonomies' => []]], ['blog' => []]],
    'null contextual taxonomies' => [['blog' => ['taxonomies' => null]], ['blog' => []]],
    'omitted contextual taxonomies' => [['blog' => []], ['blog' => []]],
    'nested wildcard selects every attached taxonomy' => [['blog' => ['taxonomies' => ['*']]], ['blog' => null]],
    'nested wildcard wins in its list' => [['blog' => ['taxonomies' => ['tags', '*']]], ['blog' => null]],
    'wildcard with an excluded collection' => [['*', 'pages' => false], ['blog' => null]],
    'wildcard with every collection excluded' => [['*', 'pages' => false, 'blog' => false], []],
    'wildcard with contextual taxonomies narrowed' => [['*', 'blog' => ['taxonomies' => ['tags']]], ['blog' => ['tags'], 'pages' => null]],
    'wildcard with contextual taxonomies removed' => [['*', 'blog' => ['taxonomies' => []]], ['blog' => [], 'pages' => null]],
    'wildcard before an override still applies it' => [['blog' => ['taxonomies' => ['tags']], 'blog', '*'], ['blog' => ['tags'], 'pages' => null]],
    'wildcard ignores a plain handle' => [['*', 'blog'], ['blog' => null, 'pages' => null]],
    'wildcard keeps an unknown keyed handle' => [['*', 'missing' => []], ['blog' => null, 'pages' => null, 'missing' => null]],
    'wildcard keeps term pages for other options' => [['*', 'blog' => ['frequency' => 'yearly']], ['blog' => null, 'pages' => null]],
    'wildcard keeps term pages for empty options' => [['*', 'blog' => []], ['blog' => null, 'pages' => null]],
    'wildcard keeps term pages for non-array options' => [['*', 'blog' => true], ['blog' => null, 'pages' => null]],
    'exclusion wins over a later plain handle' => [['pages' => false, 'pages', 'blog'], ['blog' => []]],
    'exclusion wins over an earlier plain handle' => [['pages', 'blog', 'pages' => false], ['blog' => []]],
    'exclusion of an unselected handle changes nothing' => [['blog', 'pages' => false], ['blog' => []]],
    'only a false value excludes' => [['blog' => null, 'pages' => 0], ['blog' => [], 'pages' => []]],
    'keyed handle wins over a later plain handle' => [['blog' => ['taxonomies' => ['tags']], 'blog'], ['blog' => ['tags']]],
    'keyed handle wins over an earlier plain handle' => [['blog', 'blog' => ['taxonomies' => ['tags']]], ['blog' => ['tags']]],
    'nested wildcard survives a later plain handle' => [['blog' => ['taxonomies' => ['*']], 'blog'], ['blog' => null]],
    'repeated handles keep their first position' => [['pages', 'blog', 'pages'], ['pages' => [], 'blog' => []]],
    'repeated contextual taxonomies' => [['blog' => ['taxonomies' => ['tags', 'tags']]], ['blog' => ['tags']]],
    'unknown handles are kept' => [['missing'], ['missing' => []]],
    'unknown options are ignored' => [['blog' => ['unknown' => true]], ['blog' => []]],
    'non-array options select entries only' => [['blog' => 'tags'], ['blog' => []]],
    'non-array contextual taxonomies select none' => [['blog' => ['taxonomies' => 'tags']], ['blog' => []]],
    'non-string list members are ignored' => [[['handle' => 'pages'], null, 'blog'], ['blog' => []]],
]);

it('reads the global taxonomies selection', function (mixed $configured, array $expected): void {
    config(['sitemapamic.taxonomies' => $configured]);

    expect((new Configuration)->taxonomies())->toBe($expected);
})->with([
    'null selects nothing' => [null, []],
    'empty selects nothing' => [[], []],
    'wildcard selects every taxonomy' => [['*'], ['categories', 'tags']],
    'wildcard with an excluded taxonomy' => [['*', 'tags' => false], ['categories']],
    'wildcard with every taxonomy excluded' => [['*', 'tags' => false, 'categories' => false], []],
    'exclusion wins over a plain handle' => [['tags', 'categories', 'tags' => false], ['categories']],
    'unknown exclusions are ignored' => [['*', 'missing' => false], ['categories', 'tags']],
    'explicit list selects only those' => [['tags'], ['tags']],
    'wildcard wins in its list' => [['categories', '*'], ['categories', 'tags']],
    'repeated handles keep their first position' => [['tags', 'categories', 'tags'], ['tags', 'categories']],
    'unknown handles are kept' => [['missing'], ['missing']],
    'non-string members are ignored' => [[null, false, 'tags'], ['tags']],
    'keyed handle with options selects it' => [['tags' => ['priority' => 0.3]], ['tags']],
    'keyed handle beside a plain one is listed once' => [['tags', 'tags' => ['priority' => 0.3]], ['tags']],
    'wildcard with keyed options' => [['*', 'tags' => ['priority' => 0.3]], ['categories', 'tags']],
]);

it('reads collections and global taxonomies independently', function (array $collections, array $taxonomies, array $expectedCollections, array $expectedTaxonomies): void {
    config(['sitemapamic.collections' => $collections, 'sitemapamic.taxonomies' => $taxonomies]);
    $configuration = new Configuration;

    expect($configuration->collections())->toBe($expectedCollections)
        ->and($configuration->taxonomies())->toBe($expectedTaxonomies);
})->with([
    'every collection without globals' => [['*'], [], ['blog' => null, 'pages' => null], []],
    'globals without collections' => [[], ['*'], [], ['categories', 'tags']],
]);

it('rejects a selection that is neither null nor an array', function (string $key, mixed $value): void {
    config(['sitemapamic.'.$key => $value]);

    expect(fn () => (new Configuration)->{$key}())->toThrow(InvalidArgumentException::class, $key.' must be an array or null.');
})->with([
    'scalar collections' => ['collections', 'pages'],
    'boolean collections' => ['collections', false],
    'scalar taxonomies' => ['taxonomies', 'tags'],
    'boolean taxonomies' => ['taxonomies', false],
]);

it('reads defaults for a source over the global defaults', function (?array $defaults, array $collections, array $taxonomies, string $selection, string $handle, array $expected): void {
    config(['sitemapamic.defaults' => $defaults, 'sitemapamic.collections' => $collections, 'sitemapamic.taxonomies' => $taxonomies]);

    expect((new Configuration)->defaults($selection, $handle))->toBe($expected);
})->with([
    'none' => [null, ['*'], [], 'collections', 'blog', ['changefreq' => null, 'priority' => null]],
    'global only' => [['changefreq' => 'monthly', 'priority' => '0.7'], ['*'], [], 'collections', 'blog', ['changefreq' => 'monthly', 'priority' => 0.7]],
    'source over global, key by key' => [['changefreq' => 'monthly', 'priority' => 0.7], ['*', 'blog' => ['changefreq' => 'yearly']], [], 'collections', 'blog', ['changefreq' => 'yearly', 'priority' => 0.7]],
    'source only' => [null, ['blog' => ['priority' => 0]], [], 'collections', 'blog', ['changefreq' => null, 'priority' => 0.0]],
    'integer priority' => [['priority' => 1], ['*'], [], 'collections', 'blog', ['changefreq' => null, 'priority' => 1.0]],
    'null source value keeps the global one' => [['priority' => 0.5], ['blog' => ['priority' => null]], [], 'collections', 'blog', ['changefreq' => null, 'priority' => 0.5]],
    'other sources do not apply' => [null, ['pages' => ['priority' => 0.2]], [], 'collections', 'blog', ['changefreq' => null, 'priority' => null]],
    'a plain handle has no options' => [['priority' => 0.5], ['blog'], [], 'collections', 'blog', ['changefreq' => null, 'priority' => 0.5]],
    'an excluded handle has no options' => [['priority' => 0.5], ['*', 'blog' => false], [], 'collections', 'blog', ['changefreq' => null, 'priority' => 0.5]],
    'taxonomy over global' => [['changefreq' => 'monthly'], [], ['*', 'tags' => ['changefreq' => 'weekly', 'priority' => 0.3]], 'taxonomies', 'tags', ['changefreq' => 'weekly', 'priority' => 0.3]],
    'a collection with the same handle does not apply to a taxonomy' => [null, ['tags' => ['priority' => 0.9]], ['tags'], 'taxonomies', 'tags', ['changefreq' => null, 'priority' => null]],
]);

it('rejects malformed defaults', function (array $config, string $selection, string $message): void {
    config(array_replace(['sitemapamic.defaults' => null, 'sitemapamic.collections' => ['*'], 'sitemapamic.taxonomies' => ['*']], $config));

    expect(fn () => (new Configuration)->defaults($selection, 'blog'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'defaults not an array' => [['sitemapamic.defaults' => 'monthly'], 'collections', 'defaults must be an array or null.'],
    'unknown changefreq' => [['sitemapamic.defaults' => ['changefreq' => 'sometimes']], 'collections', 'defaults.changefreq must be one of always, hourly, daily, weekly, monthly, yearly, never.'],
    'changefreq in the wrong case' => [['sitemapamic.defaults' => ['changefreq' => 'Monthly']], 'collections', 'defaults.changefreq must be one of'],
    'priority above one' => [['sitemapamic.defaults' => ['priority' => 2]], 'collections', 'defaults.priority must be a number from 0 to 1.'],
    'negative priority' => [['sitemapamic.defaults' => ['priority' => -0.1]], 'collections', 'defaults.priority must be a number from 0 to 1.'],
    'word priority' => [['sitemapamic.defaults' => ['priority' => 'high']], 'collections', 'defaults.priority must be a number from 0 to 1.'],
    'boolean priority' => [['sitemapamic.defaults' => ['priority' => true]], 'collections', 'defaults.priority must be a number from 0 to 1.'],
    'infinite priority' => [['sitemapamic.defaults' => ['priority' => INF]], 'collections', 'defaults.priority must be a number from 0 to 1.'],
    'collection priority' => [['sitemapamic.collections' => ['*', 'blog' => ['priority' => 2]]], 'collections', 'collections.blog.priority must be a number from 0 to 1.'],
    'taxonomy changefreq' => [['sitemapamic.taxonomies' => ['blog' => ['changefreq' => 'often']]], 'taxonomies', 'taxonomies.blog.changefreq must be one of'],
]);

it('reads the mode', function (?string $mode, bool $isSingleFile): void {
    config(['sitemapamic.mode' => $mode]);

    expect((new Configuration)->isSingleFile())->toBe($isSingleFile);
})->with(['automatic' => ['automatic', false], 'single' => ['single', true], 'missing from an older published config' => [null, false]]);

it('rejects an unknown mode', function (mixed $mode): void {
    config(['sitemapamic.mode' => $mode]);

    expect(fn () => (new Configuration)->isSingleFile())->toThrow(InvalidArgumentException::class, "mode must be 'automatic' or 'single'.");
})->with(['unknown word' => ['multiple'], 'wrong case' => ['Single'], 'boolean' => [true]]);

it('accepts null or a positive integer TTL', function (?int $ttl): void {
    config(['sitemapamic.cache.ttl' => $ttl]);

    expect((new Configuration)->ttl())->toBe($ttl);
})->with(['indefinite' => [null], 'one second' => [1], 'one hour' => [3600]]);

it('reads a TTL from an environment variable', function (string $value, ?int $expected): void {
    config(['sitemapamic.cache.ttl' => $value]);

    expect((new Configuration)->ttl())->toBe($expected);
})->with(['seconds' => ['60', 60], 'empty' => ['', null]]);

it('rejects a malformed TTL even with caching disabled', function (mixed $ttl): void {
    config(['sitemapamic.cache.enabled' => false, 'sitemapamic.cache.ttl' => $ttl]);

    expect(fn () => (new Configuration)->ttl())->toThrow(InvalidArgumentException::class, 'cache.ttl');
})->with(['zero' => [0], 'negative' => [-1], 'true' => [true], 'false' => [false], 'zero string' => ['0'], 'negative string' => ['-1'], 'decimal string' => ['60.5'], 'padded string' => [' 60'], 'word' => ['never'], 'float' => [60.0], 'array' => [[]]]);
