<?php

namespace MityDigital\Sitemapamic\Support\Content;

use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Support\Configuration;
use Statamic\Contracts\Entries\Collection as StatamicCollection;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Sites\Site;

class SourceResolver
{
    public function __construct(private Configuration $configuration, private DynamicRoutes $dynamicRoutes) {}

    /**
     * Discover candidate sources only. Record templates and URLs are checked when
     * records are read, so empty sources and sources with template overrides remain.
     * Collections come first by handle, each followed by its contextual taxonomies,
     * then global taxonomies by handle, then the dynamic routes.
     *
     * @return list<Source>
     */
    public function resolve(Site $site): array
    {
        $sources = [];
        $selectedCollections = $this->configuration->collections();
        foreach ($this->sorted(array_keys($selectedCollections)) as $collectionHandle) {
            $collection = Collection::find($collectionHandle);
            if (! $this->collectionIsRoutable($collection, $site)) {
                continue;
            }

            $sources[] = new Source($site->handle(), collection: $collectionHandle);

            // a null selection, from '*' or a nested '*', is every attached taxonomy
            $attachedTaxonomies = $collection->taxonomies()->map->handle()->all();
            $taxonomyHandles = $selectedCollections[$collectionHandle] ?? $attachedTaxonomies;

            foreach ($this->sorted($taxonomyHandles) as $taxonomyHandle) {
                // a selected taxonomy must be attached to the collection and available on the site
                if (! in_array($taxonomyHandle, $attachedTaxonomies, true)) {
                    continue;
                }

                if ($this->taxonomySupportsSite($taxonomyHandle, $site)) {
                    $sources[] = new Source($site->handle(), collection: $collectionHandle, taxonomy: $taxonomyHandle);
                }
            }
        }

        foreach ($this->sorted($this->configuration->taxonomies()) as $taxonomyHandle) {
            if ($this->taxonomySupportsSite($taxonomyHandle, $site)) {
                $sources[] = new Source($site->handle(), taxonomy: $taxonomyHandle);
            }
        }

        return array_merge($sources, $this->dynamicRoutes->sources($site));
    }

    /** @return list<string> */
    private function sorted(array $handles): array
    {
        sort($handles, SORT_STRING);

        return $handles;
    }

    private function collectionIsRoutable(?StatamicCollection $collection, Site $site): bool
    {
        if ($collection === null) {
            return false;
        }

        if (! $collection->sites()->contains($site->handle())) {
            return false;
        }

        $entryRoute = $collection->route($site->handle());

        return is_string($entryRoute) && trim($entryRoute) !== '';
    }

    private function taxonomySupportsSite(string $taxonomyHandle, Site $site): bool
    {
        $taxonomy = Taxonomy::find($taxonomyHandle);
        if ($taxonomy === null) {
            return false;
        }

        return $taxonomy->sites()->contains($site->handle());
    }
}
