<?php

namespace MityDigital\Sitemapamic\Data;

final readonly class SitemapFile
{
    public function __construct(
        public string $filename,
        public string $source,
        public int $count,
        public int $bytes,
    ) {}

    public static function fromArray(array $file): self
    {
        return new self($file['filename'], $file['source'], $file['count'], $file['bytes']);
    }

    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'source' => $this->source,
            'count' => $this->count,
            'bytes' => $this->bytes,
        ];
    }
}
