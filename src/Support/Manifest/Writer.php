<?php

namespace MityDigital\Sitemapamic\Support\Manifest;

use Closure;
use HashContext;
use LengthException;
use MityDigital\Sitemapamic\Data\SitemapFile;

class Writer
{
    /** @var resource|null */
    private $stream = null;

    private string $filename = '';

    private int $count = 0;

    private int $bytes = 0;

    private ?HashContext $hash = null;

    public function __construct(private int $maxUrls = 50_000, private int $maxBytes = 52_428_800) {}

    /**
     * Write fragments into numbered files, handing each finished file to $store.
     * $store owns the stream it is given and is responsible for closing it.
     *
     * @param  iterable<string>  $fragments
     * @param  Closure(string, resource): void  $store
     * @return list<SitemapFile>
     */
    public function write(string $source, iterable $fragments, string $start, string $end, Closure $store): array
    {
        $files = [];
        $number = 0;

        try {
            foreach ($fragments as $fragment) {
                // a fragment must fit in a file on its own
                if (strlen($start) + strlen($fragment) + strlen($end) > $this->maxBytes) {
                    throw new LengthException('A sitemap URL from source '.$source.' is too large for a sitemap file.');
                }

                // finish the open file when this fragment would take it past a limit
                if ($this->stream !== null && ! $this->fits($fragment, $end)) {
                    $files[] = $this->finish($source, $end, $store);
                }

                if ($this->stream === null) {
                    $number++;
                    $this->open('sitemap-'.$source.'-'.$number.'.xml', $start);
                }

                $this->append($fragment);
                $this->count++;
            }

            if ($this->stream !== null) {
                $files[] = $this->finish($source, $end, $store);
            }
        } finally {
            // if something failed part-way, close the file nobody received
            if ($this->stream !== null) {
                fclose($this->stream);
                $this->stream = null;
            }
        }

        return $files;
    }

    private function fits(string $fragment, string $end): bool
    {
        if ($this->count >= $this->maxUrls) {
            return false;
        }

        return $this->bytes + strlen($fragment) + strlen($end) <= $this->maxBytes;
    }

    private function open(string $filename, string $start): void
    {
        $this->stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        $this->filename = $filename;
        $this->count = 0;
        $this->bytes = 0;
        $this->hash = hash_init(SitemapFile::HASH_ALGORITHM);
        $this->append($start);
    }

    /** Write to the open file, keeping its byte length and content hash in step. */
    private function append(string $contents): void
    {
        fwrite($this->stream, $contents);
        hash_update($this->hash, $contents);
        $this->bytes += strlen($contents);
    }

    private function finish(string $source, string $end, Closure $store): SitemapFile
    {
        $this->append($end);
        rewind($this->stream);

        // the stream now belongs to $store
        $stream = $this->stream;
        $this->stream = null;
        $store($this->filename, $stream);

        return new SitemapFile($this->filename, $source, $this->count, $this->bytes, hash_final($this->hash));
    }
}
