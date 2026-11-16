<?php

use MityDigital\Sitemapamic\Data\Source;

it('names and classifies each kind of source', function (Source $source, string $name, string $kind): void {
    $kinds = array_keys(array_filter([
        'collection' => $source->isCollection(),
        'taxonomy' => $source->isTaxonomy(),
        'collection taxonomy' => $source->isCollectionTaxonomy(),
        'dynamic' => $source->dynamic,
    ]));

    expect($source->name())->toBe($name)
        ->and($kinds)->toBe([$kind]);
})->with([
    'collection' => [new Source('default', collection: 'blog'), 'collection-blog', 'collection'],
    'contextual taxonomy' => [new Source('default', collection: 'blog', taxonomy: 'tags'), 'collection-blog-taxonomy-tags', 'collection taxonomy'],
    'global taxonomy' => [new Source('default', taxonomy: 'tags'), 'taxonomy-tags', 'taxonomy'],
    'dynamic routes' => [new Source('default', dynamic: true), 'dynamic', 'dynamic'],
]);
