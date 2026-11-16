<?php

namespace MityDigital\Sitemapamic\Models;

use MityDigital\Sitemapamic\Enums\ChangeFrequency;

class SitemapamicUrl
{
    public ?string $changefreq;

    /**
     * @param  list<array{hreflang: string, href: string}>  $alternates
     */
    public function __construct(
        public string $loc,
        public ?string $lastmod = null,
        ChangeFrequency|string|null $changefreq = null,
        public ?float $priority = null,
        public array $alternates = [],
    ) {
        $this->changefreq = $changefreq instanceof ChangeFrequency ? $changefreq->value : $changefreq;
    }
}
