<?php

namespace MityDigital\Sitemapamic\Data;

final class SitemapDocument
{
    /** @param resource|null $stream null when the client already holds this file */
    public function __construct(
        public $stream,
        public int $bytes,
        public string $etag,
    ) {}

    public static function unchanged(SitemapFile $file): self
    {
        return new self(null, $file->bytes, $file->etag());
    }

    public function isUnchanged(): bool
    {
        return $this->stream === null;
    }
}
