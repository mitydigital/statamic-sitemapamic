<?php

namespace MityDigital\Sitemapamic\Data;

final readonly class SitemapFile
{
    /** The algorithm used for each file's content hash, which is also its ETag. */
    public const string HASH_ALGORITHM = 'xxh128';

    public function __construct(
        public string $filename,
        public string $source,
        public int $count,
        public int $bytes,
        public string $hash,
    ) {}

    public static function fromArray(array $file): self
    {
        return new self($file['filename'], $file['source'], $file['count'], $file['bytes'], $file['hash']);
    }

    public function etag(): string
    {
        return '"'.$this->hash.'"';
    }

    /**
     * Whether a client already holds this file, going by the ETags it sent in If-None-Match.
     *
     * @param  list<string>  $etags
     */
    public function matchesAnyEtag(array $etags): bool
    {
        foreach ($etags as $etag) {
            // If-None-Match compares weakly, so a W/ prefix still matches
            if ($etag === '*' || preg_replace('#^W/#', '', $etag) === $this->etag()) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'source' => $this->source,
            'count' => $this->count,
            'bytes' => $this->bytes,
            'hash' => $this->hash,
        ];
    }
}
