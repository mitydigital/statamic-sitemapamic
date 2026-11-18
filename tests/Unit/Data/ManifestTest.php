<?php

use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapFile;

function dataManifest(array $files = [], ?SitemapFile $index = null): Manifest
{
    $keyed = [];
    foreach ($files as $file) {
        $keyed[$file->filename] = $file;
    }

    return new Manifest(
        site: 'default',
        version: 'version-1',
        directory: 'sitemapamic/default/01JABCDEF',
        builtAt: 1_700_000_000,
        expiresAt: 1_700_003_600,
        configHash: 'config-hash',
        files: $keyed,
        index: $index,
    );
}

beforeEach(function (): void {
    $this->blog = new SitemapFile('sitemap-collection-blog-1.xml', 'collection-blog', 2, 400, 'blog-hash');
    $this->tags = new SitemapFile('sitemap-taxonomy-tags-1.xml', 'taxonomy-tags', 1, 250, 'tags-hash');
    $this->index = new SitemapFile('sitemap.xml', 'index', 2, 300, 'index-hash');
});

it('finds a child file by its filename', function (): void {
    $manifest = dataManifest([$this->blog, $this->tags], $this->index);

    expect($manifest->file('sitemap-taxonomy-tags-1.xml'))->toBe($this->tags)
        ->and($manifest->file('sitemap-collection-blog-1.xml'))->toBe($this->blog);
});

it('returns the index for sitemap.xml', function (): void {
    $manifest = dataManifest([$this->blog], $this->index);

    expect($manifest->file('sitemap.xml'))->toBe($this->index);
});

it('returns null for an unknown filename', function (): void {
    $manifest = dataManifest([$this->blog], $this->index);

    expect($manifest->file('sitemap-missing-1.xml'))->toBeNull();
});

it('returns null for sitemap.xml when there is no index', function (): void {
    expect(dataManifest()->file('sitemap.xml'))->toBeNull();
});

it('is empty only when it has no child files', function (): void {
    expect(dataManifest()->isEmpty())->toBeTrue()
        ->and(dataManifest([$this->blog], $this->index)->isEmpty())->toBeFalse();
});

it('survives an array round trip', function (?int $expiresAt, bool $withFiles): void {
    $manifest = new Manifest(
        site: 'regional',
        version: 'version-2',
        directory: 'sitemapamic/regional/01JXYZ',
        builtAt: 1_700_000_000,
        expiresAt: $expiresAt,
        configHash: 'config-hash',
        files: $withFiles ? [$this->blog->filename => $this->blog, $this->tags->filename => $this->tags] : [],
        index: $withFiles ? $this->index : null,
    );

    $restored = Manifest::fromArray($manifest->toArray());

    expect($restored)->toEqual($manifest)
        ->and(array_keys($restored->files))->toBe(array_keys($manifest->files))
        ->and($restored->toArray())->toBe($manifest->toArray());
})->with([
    'with files and an expiry' => [1_700_003_600, true],
    'empty and never expiring' => [null, false],
]);

it('stores only plain arrays so it can be cached', function (): void {
    $array = dataManifest([$this->blog], $this->index)->toArray();

    expect($array['files'])->toBe([$this->blog->toArray()])
        ->and($array['index'])->toBe($this->index->toArray())
        ->and(unserialize(serialize($array)))->toBe($array);
});
