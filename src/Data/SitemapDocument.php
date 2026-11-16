<?php

namespace MityDigital\Sitemapamic\Data;

final class SitemapDocument
{
    /** @param resource $stream */
    public function __construct(
        public $stream,
        public int $bytes,
    ) {}
}
