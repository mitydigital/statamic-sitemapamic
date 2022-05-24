<?php

namespace MityDigital\Sitemapamic\Models;

use Carbon\Carbon;

class SitemapamicUrl
{
    public function __construct(
        public string $loc,
        public string $lastmod,
        public null|string $changefreq = null,
        public null|string $priority = null,
    ){}
}