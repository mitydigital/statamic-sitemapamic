<?php

namespace MityDigital\Sitemapamic\Support\Content;

use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Support\Collection as LaravelCollection;
use InvalidArgumentException;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Enums\ChangeFrequency;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Configuration;
use MityDigital\Sitemapamic\Support\SiteUrls;
use MityDigital\Sitemapamic\Support\SiteViews;
use Statamic\Contracts\Auth\Protect\Protectable;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Entries\EntryRepository as EntryRepositoryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\URL;
use Statamic\Sites\Site as StatamicSite;
use Statamic\Support\Str;
use Statamic\Taxonomies\LocalizedTerm;

class UrlIterator
{
    private const array HIDDEN_DATE_BEHAVIOURS = ['private', 'unlisted'];

    private const int CHUNK_SIZE = 1000;

    /** @var array<string, string> each mapping's field, validated the first time it is needed */
    private array $mappedFields = [];

    /** @var array<string, LaravelCollection<string, Closure>> each collection's computed callbacks */
    private array $computedCallbacks = [];

    /** @var array<string, bool> whether each collection is routable on each site */
    private array $routable = [];

    /** @var array<string, bool> whether each template's view exists for each site */
    private array $templateExists = [];

    /** @var array<string, string|null>|null the stored URIs of the current chunk's entries, on Eloquent only */
    private ?array $chunkUris = null;

    /** @var array{changefreq: ?string, priority: ?float} */
    private array $defaults = ['changefreq' => null, 'priority' => null];

    public function __construct(
        private DynamicRoutes $dynamicRoutes,
        private SiteViews $siteViews,
        private Configuration $configuration,
    ) {}

    /** @return Generator<int, SitemapamicUrl> */
    public function forSource(Source $source): Generator
    {
        // config, computed callbacks and views can change between sources, so each source works them out again
        $this->mappedFields = [];
        $this->computedCallbacks = [];
        $this->routable = [];
        $this->templateExists = [];
        $this->chunkUris = null;

        $site = Site::get($source->site);

        if ($source->dynamic) {
            return yield from $this->dynamicRoutes->urls($site);
        }

        if ($source->isCollection()) {
            $this->defaults = $this->configuration->defaults('collections', $source->collection);

            return yield from $this->entryUrls($source->collection, $site);
        }

        // contextual term pages use their taxonomy's defaults too
        $this->defaults = $this->configuration->defaults('taxonomies', $source->taxonomy);

        yield from $this->termUrls($source->taxonomy, $source->collection, $site);
    }

    /** @return Generator<int, SitemapamicUrl> */
    private function entryUrls(string $handle, StatamicSite $site): Generator
    {
        $collection = Collection::find($handle);
        if ($collection === null) {
            return;
        }

        // only a collection on more than one site can have localisations
        $isMultisite = $collection->sites()->count() > 1;

        foreach ($this->entryChunks($collection, $site) as $entries) {
            $localisations = $isMultisite ? $this->localisationsByRoot($collection, $entries) : [];
            $this->chunkUris = $this->storedUris($collection, [...$entries, ...array_merge([], ...array_map(array_values(...), array_values($localisations)))]);

            foreach ($entries as $entry) {
                if (! $this->isReachable($entry, $site) || ! $this->hasTemplate($entry)) {
                    continue;
                }

                $url = $this->url($entry, $site, $this->lastModified($entry));
                $url->alternates = $isMultisite
                    ? $this->entryAlternates($entry, $url->loc, $localisations[$entry->root()->id()] ?? [])
                    : [];

                yield $url;
            }

            // Blink is a per-request cache, so clearing it between chunks frees the translations Statamic keeps
            Blink::flush();
            // Statamic also remembers every URL it tidies for the rest of the request
            URL::clearUrlCache();
            $this->chunkUris = null;
        }
    }

    /**
     * The URIs the Eloquent driver stored for these entries, which it also routes by, so they needn't be rebuilt from the route.
     * Eloquent only: on the Stache, two plucks come back in index order rather than the order asked for, so the lists wouldn't line up.
     * Both plucks are keyless, because the driver builds every entry for a keyed pluck.
     *
     * @param  list<EntryContract>  $entries
     * @return array<string, string|null>|null null when the entries aren't stored by the Eloquent driver
     */
    private function storedUris(CollectionContract $collection, array $entries): ?array
    {
        if ($entries === [] || ! $this->isEloquent()) {
            return null;
        }

        $ids = array_values(array_unique(array_map(fn (EntryContract $entry): string => $entry->id(), $entries)));
        $query = fn () => $collection->queryEntries()->whereIn('id', $ids)->orderBy('id');

        return array_combine($query()->pluck('id')->all(), $query()->pluck('uri')->all());
    }

    /** Whether entries are stored by the Eloquent driver, checked by name so the addon doesn't depend on it. */
    private function isEloquent(): bool
    {
        return is_a(app(EntryRepositoryContract::class), 'Statamic\Eloquent\Entries\EntryRepository');
    }

    /**
     * Every localisation of the chunk's entries, keyed by root id and then site,
     * loaded a level at a time as Statamic's descendants() does, but for the whole chunk at once.
     *
     * @param  list<EntryContract>  $entries
     * @return array<string, array<string, EntryContract>>
     */
    private function localisationsByRoot(CollectionContract $collection, array $entries): array
    {
        $roots = [];
        foreach ($entries as $entry) {
            $root = $entry->root();
            $roots[$root->id()] = $root;
        }

        $byRoot = [];
        $seen = array_fill_keys(array_keys($roots), true);
        $origins = array_keys($roots);
        while ($origins !== []) {
            $children = $collection->queryEntries()->whereIn('origin', $origins)->get()
                // guard against cyclic or duplicate origin data, as descendants() does
                ->reject(fn (EntryContract $child): bool => isset($seen[$child->id()]));

            $origins = [];
            foreach ($children as $child) {
                $seen[$child->id()] = true;
                $origins[] = $child->id();
                $byRoot[$child->root()->id()][$child->locale()] = $child;
            }
        }

        // a root is its own localisation for its site, as in()
        foreach ($roots as $rootId => $root) {
            $byRoot[$rootId][$root->locale()] = $root;
        }

        return $byRoot;
    }

    /**
     * The collection's entries on this site in id order, a chunk at a time.
     *
     * @return Generator<int, list<EntryContract>>
     */
    private function entryChunks(CollectionContract $collection, StatamicSite $site): Generator
    {
        return $this->isEloquent() ? $this->entryPages($collection, $site) : $this->entryIdChunks($collection, $site);
    }

    /**
     * Each page starts after the last id of the one before, so the database never has to return every id at once
     * or skip over the entries already read.
     *
     * @return Generator<int, list<EntryContract>>
     */
    private function entryPages(CollectionContract $collection, StatamicSite $site): Generator
    {
        $lastId = null;

        do {
            $query = $collection->queryEntries()->where('site', $site->handle())->orderBy('id')->limit(self::CHUNK_SIZE);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $entries = array_values($query->get()->all());
            if ($entries === []) {
                return;
            }

            $lastId = end($entries)->id();
            $isLastPage = count($entries) < self::CHUNK_SIZE;

            yield $entries;
        } while (! $isLastPage);
    }

    /**
     * On the Stache the ids are sorted once; paging would filter and sort the whole collection again for each chunk.
     *
     * @return Generator<int, list<EntryContract>>
     */
    private function entryIdChunks(CollectionContract $collection, StatamicSite $site): Generator
    {
        $ids = $collection->queryEntries()->where('site', $site->handle())->orderBy('id')->pluck('id')->all();

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            // whereIn doesn't keep the order it was given, so put the entries back in id order
            $entries = $collection->queryEntries()->whereIn('id', $chunk)->get()->keyBy(fn (EntryContract $entry): string => $entry->id());

            yield array_values(array_filter(array_map(fn (string $id): ?EntryContract => $entries->get($id), $chunk)));
        }
    }

    /** @return Generator<int, SitemapamicUrl> */
    private function termUrls(string $taxonomy, ?string $collectionHandle, StatamicSite $site): Generator
    {
        $query = Term::query()->where('taxonomy', $taxonomy)->where('site', $site->handle());
        if ($collectionHandle !== null) {
            $query->where('collection', $collectionHandle);
        }

        $collection = $collectionHandle === null ? null : Collection::find($collectionHandle);

        // a contextual term only counts its own collection's entries
        $collections = $collection === null ? Taxonomy::find($taxonomy)?->collections()->all() ?? [] : [$collection];
        $newestEntryDates = $this->newestEntryDates($taxonomy, $collections, $site);

        foreach ($query->orderBy('id')->lazy(self::CHUNK_SIZE) as $term) {
            $url = $this->termUrl((clone $term)->collection($collection), $site, $newestEntryDates);
            if ($url !== null) {
                yield $url;
            }
        }
    }

    /** Every entry rule except the template, which depends on the site's views. */
    private function isReachable(EntryContract $entry, StatamicSite $site): bool
    {
        $collection = $entry->collection();
        $isOnSite = $entry->locale() === $site->handle() && $this->isRoutable($collection, $site);
        if (! $isOnSite || ! $entry->published() || ! $this->isListedDate($entry, $collection)) {
            return false;
        }

        // the include mapping may be computed, so it is checked after the cheaper rules
        return ! $this->isExcluded($entry) && $this->isPublic($entry);
    }

    private function isRoutable(CollectionContract $collection, StatamicSite $site): bool
    {
        return $this->routable[$collection->handle().'|'.$site->handle()]
            ??= $collection->sites()->contains($site->handle()) && (bool) $collection->route($site->handle());
    }

    private function isListedDate(EntryContract $entry, CollectionContract $collection): bool
    {
        if (! $collection->dated()) {
            return true;
        }

        if ($entry->date()->gt(now())) {
            return ! in_array($collection->futureDateBehavior(), self::HIDDEN_DATE_BEHAVIOURS, true);
        }

        return ! in_array($collection->pastDateBehavior(), self::HIDDEN_DATE_BEHAVIOURS, true);
    }

    /**
     * @param  array<string, EntryContract>  $localisations  the entry's root and all its localisations, by site
     * @return list<array{hreflang: string, href: string}>
     */
    private function entryAlternates(EntryContract $entry, string $loc, array $localisations): array
    {
        $alternates = [];
        $collectionSites = $entry->collection()->sites();
        foreach (Site::all() as $alternateSite) {
            // a site outside the collection can never hold a listed localisation
            if (! $collectionSites->contains($alternateSite->handle())) {
                continue;
            }

            $localisation = $alternateSite->handle() === $entry->locale() ? $entry : ($localisations[$alternateSite->handle()] ?? null);
            if ($localisation === null) {
                continue;
            }

            // another localisation needs the same rules, with its template checked in its own site's views
            $isThisEntry = $alternateSite->handle() === $entry->locale();
            $isListed = $isThisEntry || ($this->isReachable($localisation, $alternateSite)
                && $this->siteViews->use($alternateSite, fn (): bool => $this->hasTemplate($localisation)));
            if (! $isListed) {
                continue;
            }

            $href = $isThisEntry ? $loc : SiteUrls::contentUrl($alternateSite, $this->recordUri($localisation));
            $hreflang = str_replace('_', '-', $alternateSite->locale());
            $alternates[$hreflang.'|'.$href] = ['hreflang' => $hreflang, 'href' => $href];
        }

        // an entry with no other listed localisation has no alternates
        return count($alternates) < 2 ? [] : array_values($alternates);
    }

    /** @param  array<string, CarbonInterface>  $newestEntryDates */
    private function termUrl(LocalizedTerm $term, StatamicSite $site, array $newestEntryDates): ?SitemapamicUrl
    {
        // a contextual term needs its collection to be routable on this site and to use this taxonomy
        $collection = $term->collection();
        $inContext = $collection === null || $this->isRoutableContext($collection, $term, $site);
        if (! $inContext || ! $term->taxonomy()->sites()->contains($site->handle())) {
            return null;
        }

        if ($this->isExcluded($term) || ! $this->isPublic($term) || ! $this->hasTemplate($term)) {
            return null;
        }

        // only list a term that has a published entry on this site
        $newestEntryDate = $newestEntryDates[$term->slug()] ?? null;
        if ($newestEntryDate === null) {
            return null;
        }

        // the term was last modified when it or its newest published entry changed
        return $this->url($term, $site, $this->lastModified($term)->max($newestEntryDate));
    }

    private function isRoutableContext(CollectionContract $collection, LocalizedTerm $term, StatamicSite $site): bool
    {
        $usesTaxonomy = $collection->taxonomies()->contains(
            fn ($taxonomy): bool => $taxonomy->handle() === $term->taxonomyHandle()
        );

        return $usesTaxonomy && $this->isRoutable($collection, $site);
    }

    /**
     * Each term slug's newest listed, published entry date on this site, found in one pass over the collections' entries
     * instead of one query per term. Entries are matched to terms the way each driver's whereTaxonomy() matches them.
     *
     * @param  iterable<CollectionContract>  $collections
     * @return array<string, CarbonInterface>
     */
    private function newestEntryDates(string $taxonomy, iterable $collections, StatamicSite $site): array
    {
        // the Stache matches a term by the slug of each stored value, the Eloquent driver by the stored value itself
        $matchesSlug = ! $this->isEloquent();

        $newest = [];
        foreach ($collections as $collection) {
            foreach ($this->entryChunks($collection, $site) as $entries) {
                foreach ($entries as $entry) {
                    if (! $entry->published() || ! $this->isListedDate($entry, $collection)) {
                        continue;
                    }

                    $lastModified = $this->lastModified($entry);
                    foreach ((array) $entry->value($taxonomy) as $value) {
                        $slug = $matchesSlug ? Str::slug((string) $value) : (string) $value;
                        if (! isset($newest[$slug]) || $lastModified->gt($newest[$slug])) {
                            $newest[$slug] = $lastModified;
                        }
                    }
                }

                Blink::flush();
            }
        }

        return $newest;
    }

    private function isExcluded(EntryContract|LocalizedTerm $record): bool
    {
        return in_array($this->mappedValue($record, 'include'), [false, 'false'], true);
    }

    private function isPublic(EntryContract|LocalizedTerm $record): bool
    {
        $isRedirectOrProtected = $record->value('redirect') || $this->protectionScheme($record);

        return ! $isRedirectOrProtected && $this->recordUri($record);
    }

    /** The stored URI for an entry in the current chunk on Eloquent, otherwise the record's own. */
    private function recordUri(EntryContract|LocalizedTerm $record): ?string
    {
        if ($record instanceof EntryContract && $this->chunkUris !== null && array_key_exists($record->id(), $this->chunkUris)) {
            return $this->chunkUris[$record->id()];
        }

        return $record->uri();
    }

    /** What Statamic's Protection::scheme() returns, without resolving a Protection for every record. */
    private function protectionScheme(EntryContract|LocalizedTerm $record): mixed
    {
        $scheme = $record instanceof Protectable ? $record->getProtectionScheme() : null;

        return $scheme ?: config('statamic.protect.default');
    }

    private function hasTemplate(EntryContract|LocalizedTerm $record): bool
    {
        $template = $record->template();
        if (! $template) {
            return false;
        }

        // the site matters, because alternates check templates in their own site's views
        return $this->templateExists[Site::current()->handle().'|'.$template] ??= view()->exists($template);
    }

    private function url(
        EntryContract|LocalizedTerm $record,
        StatamicSite $site,
        CarbonInterface $lastModified,
    ): SitemapamicUrl {
        $frequency = $this->mappedValue($record, 'changefreq');

        // a dictionary field that allows several values stores a list, even when it holds one
        if (is_array($frequency) && count($frequency) === 1) {
            $frequency = reset($frequency);
        }
        $priority = $this->mappedValue($record, 'priority');
        $isValidPriority = is_numeric($priority) && is_finite((float) $priority) && $priority >= 0 && $priority <= 1;

        return new SitemapamicUrl(
            loc: SiteUrls::contentUrl($site, $this->recordUri($record)),
            lastmod: $lastModified->toAtomString(),
            changefreq: ChangeFrequency::fromValue($frequency)?->value ?? $this->defaults['changefreq'],
            priority: $isValidPriority ? (float) $priority : $this->defaults['priority'],
        );
    }

    private function mappedValue(EntryContract|LocalizedTerm $record, string $mapping): mixed
    {
        $field = $this->mappedField($mapping);

        // entries fall back to a computed value, terms use the raw value only
        $value = $record->get($field);
        if ($value !== null || ! $record instanceof EntryContract) {
            return $value;
        }

        // getComputed() copies the entry, so only call it when the collection computes this field
        return $this->computedCallbacks($record->collection()->handle())->has($field) ? $record->getComputed($field) : null;
    }

    private function mappedField(string $mapping): string
    {
        if (isset($this->mappedFields[$mapping])) {
            return $this->mappedFields[$mapping];
        }

        $field = config('sitemapamic.mappings.'.$mapping);

        // v3 configs name the changefreq mapping change_frequency
        if ($field === null && $mapping === 'changefreq') {
            $field = config('sitemapamic.mappings.change_frequency');
        }
        if (! is_string($field) || $field === '') {
            throw new InvalidArgumentException('sitemapamic.mappings.'.$mapping.' must name a field.');
        }

        return $this->mappedFields[$mapping] = $field;
    }

    /** @return LaravelCollection<string, Closure> */
    private function computedCallbacks(string $collectionHandle): LaravelCollection
    {
        return $this->computedCallbacks[$collectionHandle] ??= Collection::getComputedCallbacks($collectionHandle);
    }

    private function lastModified(EntryContract|LocalizedTerm $record): CarbonInterface
    {
        // without a valid updated_at, use the date the file was modified
        if (! is_numeric($record->get('updated_at'))) {
            return ($record instanceof LocalizedTerm ? $record->term() : $record)->fileLastModified();
        }

        return $record->lastModified();
    }
}
