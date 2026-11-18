<?php

use MityDigital\Sitemapamic\Data\SitemapFile;
use MityDigital\Sitemapamic\Support\Manifest\Writer;

/** Collects what the writer hands to $store, keyed by filename. */
class StoredSitemapFiles
{
    /** @var array<string, string> */
    public array $contents = [];

    public function store(string $filename, $stream): void
    {
        $this->contents[$filename] = stream_get_contents($stream);
        fclose($stream);
    }
}

function writeSitemapFiles(Writer $writer, iterable $fragments, StoredSitemapFiles $stored, string $source = 'collection-pages'): array
{
    return $writer->write($source, $fragments, '[', ']', $stored->store(...));
}

beforeEach(function (): void {
    $this->stored = new StoredSitemapFiles;
});

it('writes no file for no fragments', function (): void {
    $files = writeSitemapFiles(new Writer, [], $this->stored);

    expect($files)->toBe([])
        ->and($this->stored->contents)->toBe([]);
});

it('writes one fragment into one numbered file', function (): void {
    $files = writeSitemapFiles(new Writer, ['abc'], $this->stored);

    expect($files)->toEqual([new SitemapFile('sitemap-collection-pages-1.xml', 'collection-pages', 1, 5, hash('xxh128', '[abc]'))])
        ->and($this->stored->contents)->toBe(['sitemap-collection-pages-1.xml' => '[abc]']);
});

it('accepts exactly the URL limit without an empty trailing file', function (): void {
    $files = writeSitemapFiles(new Writer(maxUrls: 2), ['a', 'b'], $this->stored);

    expect($files)->toHaveCount(1)
        ->and($files[0]->count)->toBe(2)
        ->and($this->stored->contents)->toBe(['sitemap-collection-pages-1.xml' => '[ab]']);
});

it('starts the next file one URL over the limit', function (): void {
    $files = writeSitemapFiles(new Writer(maxUrls: 2), ['a', 'b', 'c'], $this->stored);

    expect(array_map(fn (SitemapFile $file): int => $file->count, $files))->toBe([2, 1])
        ->and($this->stored->contents)->toBe([
            'sitemap-collection-pages-1.xml' => '[ab]',
            'sitemap-collection-pages-2.xml' => '[c]',
        ]);
});

it('accepts exactly the byte limit and splits one byte over it', function (array $fragments, array $expected): void {
    writeSitemapFiles(new Writer(maxBytes: 5), $fragments, $this->stored);

    expect(array_values($this->stored->contents))->toBe($expected);
})->with([
    'exactly full' => [['ab', 'c'], ['[abc]']],
    'one byte over' => [['ab', 'c', 'd'], ['[abc]', '[d]']],
]);

it('reserves room for the closing markup before accepting a fragment', function (): void {
    $files = $this->stored;
    $written = (new Writer(maxBytes: 8))->write('collection-pages', ['a', 'b'], '<x>', '</x>', $files->store(...));

    expect(array_map(fn (SitemapFile $file): int => $file->bytes, $written))->toBe([8, 8])
        ->and(array_values($files->contents))->toBe(['<x>a</x>', '<x>b</x>']);
});

it('splits at whichever limit is reached first', function (int $maxUrls, int $maxBytes, array $fragments, array $counts): void {
    $files = writeSitemapFiles(new Writer($maxUrls, $maxBytes), $fragments, $this->stored);

    expect(array_map(fn (SitemapFile $file): int => $file->count, $files))->toBe($counts);
})->with([
    'count wins' => [2, 12, ['a', 'b', 'c'], [2, 1]],
    'bytes win' => [3, 5, ['aa', 'bb', 'cc'], [1, 1, 1]],
    'both reached together' => [2, 4, ['a', 'b', 'c'], [2, 1]],
]);

it('counts escaped and multibyte text in bytes, not characters', function (): void {
    $files = writeSitemapFiles(new Writer(maxBytes: 9), ['&amp;', 'é', '😀'], $this->stored);

    expect(array_map(fn (SitemapFile $file): int => $file->bytes, $files))->toBe([9, 6])
        ->and(array_values($this->stored->contents))->toBe(['[&amp;é]', '[😀]']);
});

it('records each file size as the bytes actually stored', function (): void {
    $files = writeSitemapFiles(new Writer(maxUrls: 2), ['é', 'abc', '😀'], $this->stored);

    foreach ($files as $file) {
        expect($file->bytes)->toBe(strlen($this->stored->contents[$file->filename]));
    }
});

it('throws naming the source when a fragment cannot fit in an empty file', function (array $fragments): void {
    expect(fn () => writeSitemapFiles(new Writer(maxBytes: 5), $fragments, $this->stored, 'collection-blog'))
        ->toThrow(LengthException::class, 'collection-blog');
})->with([
    'first fragment' => [['abcd']],
    'after a fragment that fits' => [['a', 'abcd']],
]);

it('closes the open file and stores nothing more when the fragments fail part-way', function (): void {
    $fragments = (function (): Generator {
        yield 'a';
        throw new RuntimeException('source failed');
    })();

    expect(fn () => writeSitemapFiles(new Writer, $fragments, $this->stored))->toThrow(RuntimeException::class, 'source failed')
        ->and($this->stored->contents)->toBe([]);
});

it('stores a full file as soon as the next fragment arrives', function (): void {
    $events = [];
    $fragments = (function () use (&$events): Generator {
        foreach (['a', 'b', 'c'] as $fragment) {
            $events[] = 'read '.$fragment;
            yield $fragment;
        }
    })();
    $store = function (string $filename, $stream) use (&$events): void {
        $events[] = 'stored '.$filename;
        fclose($stream);
    };

    (new Writer(maxUrls: 2))->write('dynamic', $fragments, '[', ']', $store);

    expect($events)->toBe([
        'read a', 'read b', 'read c',
        'stored sitemap-dynamic-1.xml',
        'stored sitemap-dynamic-2.xml',
    ]);
});

it('can be reused for another source', function (): void {
    $writer = new Writer(maxUrls: 1);

    $first = writeSitemapFiles($writer, ['a', 'b'], $this->stored, 'collection-blog');
    $second = writeSitemapFiles($writer, ['c'], $this->stored, 'taxonomy-tags');

    expect(array_map(fn (SitemapFile $file): string => $file->filename, [...$first, ...$second]))->toBe([
        'sitemap-collection-blog-1.xml',
        'sitemap-collection-blog-2.xml',
        'sitemap-taxonomy-tags-1.xml',
    ]);
});
