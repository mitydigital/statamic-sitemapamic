<?php

use MityDigital\Sitemapamic\Data\SitemapFile;

beforeEach(function (): void {
    $this->file = new SitemapFile('sitemap.xml', 'index', 1, 10, 'abc123');
});

it('quotes its hash as its ETag', function (): void {
    expect($this->file->etag())->toBe('"abc123"');
});

it('matches an If-None-Match ETag', function (array $etags, bool $matches): void {
    expect($this->file->matchesAnyEtag($etags))->toBe($matches);
})->with([
    'none' => [[], false],
    'strong' => [['"abc123"'], true],
    'weak' => [['W/"abc123"'], true],
    'one of several' => [['"other"', '"abc123"'], true],
    'any' => [['*'], true],
    'different' => [['"other"'], false],
    'unquoted' => [['abc123'], false],
]);

it('survives an array round trip', function (): void {
    expect(SitemapFile::fromArray($this->file->toArray()))->toEqual($this->file);
});
