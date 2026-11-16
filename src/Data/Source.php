<?php

namespace MityDigital\Sitemapamic\Data;

/** One collection, global taxonomy, contextual taxonomy or the site's dynamic routes. */
final readonly class Source
{
    public function __construct(
        public string $site,
        public ?string $collection = null,
        public ?string $taxonomy = null,
        public bool $dynamic = false,
    ) {}

    public function isCollection(): bool
    {
        return $this->collection !== null && $this->taxonomy === null;
    }

    public function isTaxonomy(): bool
    {
        return $this->collection === null && $this->taxonomy !== null;
    }

    public function isCollectionTaxonomy(): bool
    {
        return $this->collection !== null && $this->taxonomy !== null;
    }

    // used in filenames: sitemap-{name}-{n}.xml
    public function name(): string
    {
        if ($this->dynamic) {
            return 'dynamic';
        }

        if ($this->isCollectionTaxonomy()) {
            return 'collection-'.$this->collection.'-taxonomy-'.$this->taxonomy;
        }

        if ($this->isCollection()) {
            return 'collection-'.$this->collection;
        }

        return 'taxonomy-'.$this->taxonomy;
    }
}
