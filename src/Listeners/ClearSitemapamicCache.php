<?php

namespace MityDigital\Sitemapamic\Listeners;

use Illuminate\Events\Dispatcher;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Events\CollectionDeleted;
use Statamic\Events\CollectionSaved;
use Statamic\Events\CollectionTreeDeleted;
use Statamic\Events\CollectionTreeEntriesMovedOrRemoved;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\LocalizedTermDeleted;
use Statamic\Events\LocalizedTermSaved;
use Statamic\Events\SiteCreated;
use Statamic\Events\SiteDeleted;
use Statamic\Events\SiteSaved;
use Statamic\Events\TaxonomyDeleted;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermReferencesUpdated;
use Statamic\Events\TermSaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class ClearSitemapamicCache
{
    private const string SCHEDULE_EVENT
        = 'MityDigital\\StatamicScheduledCacheInvalidator\\Events\\ScheduledCacheInvalidated';

    public function subscribe(Dispatcher $events): array
    {
        $subscriptions = [
            EntrySaved::class => 'entryChanged',
            EntryDeleted::class => 'entryChanged',
            EntryScheduleReached::class => 'entryChanged',
            CollectionTreeSaved::class => 'markAllSitesStale',
            CollectionTreeDeleted::class => 'markAllSitesStale',
            CollectionTreeEntriesMovedOrRemoved::class => 'markAllSitesStale',
            CollectionSaved::class => 'markAllSitesStale',
            CollectionDeleted::class => 'markAllSitesStale',
            TermSaved::class => 'markAllSitesStale',
            TermDeleted::class => 'markAllSitesStale',
            LocalizedTermSaved::class => 'markAllSitesStale',
            LocalizedTermDeleted::class => 'markAllSitesStale',
            TermReferencesUpdated::class => 'markAllSitesStale',
            TaxonomySaved::class => 'markAllSitesStale',
            TaxonomyDeleted::class => 'markAllSitesStale',
            SiteCreated::class => 'markAllSitesStale',
            SiteSaved::class => 'markAllSitesStale',
            SiteDeleted::class => 'siteDeleted',
        ];

        // the scheduled cache invalidator addon is optional
        if (class_exists(self::SCHEDULE_EVENT)) {
            $subscriptions[self::SCHEDULE_EVENT] = 'scheduledCollectionsChanged';
        }

        return $subscriptions;
    }

    public function entryChanged(EntrySaved|EntryDeleted|EntryScheduleReached $event): void
    {
        $collection = $event->entry->collection();
        if ($collection === null) {
            $this->markAllSitesStale();

            return;
        }

        // a mounted collection's URLs depend on this one, on any of its sites
        if ($this->isMountedByAnotherCollection($collection)) {
            $this->markAllSitesStale();

            return;
        }

        $this->markSitesStale($collection->sites());
    }

    public function markAllSitesStale(): void
    {
        Sitemapamic::markStale();
    }

    public function siteDeleted(SiteDeleted $event): void
    {
        Sitemapamic::clearCache($event->site);
        $this->markAllSitesStale();
    }

    public function scheduledCollectionsChanged(object $event): void
    {
        $handles = $event->collections ?? [];
        if (! is_array($handles) || $handles === []) {
            $this->markAllSitesStale();

            return;
        }

        $sites = [];
        foreach ($handles as $handle) {
            $collection = is_string($handle) ? Collection::find($handle) : null;
            if ($collection === null) {
                $this->markAllSitesStale();

                return;
            }

            array_push($sites, ...$collection->sites()->all());
        }

        $this->markSitesStale(array_unique($sites));
    }

    private function isMountedByAnotherCollection(CollectionContract $collection): bool
    {
        return Collection::all()->contains(
            fn ($candidate): bool => $candidate->mount()?->collectionHandle() === $collection->handle()
        );
    }

    private function markSitesStale(?iterable $handles): void
    {
        // an unknown or empty list of sites marks every site stale
        $sites = collect($handles ?? [])->map(fn (string $handle) => Site::get($handle));
        if ($sites->isEmpty() || $sites->contains(null)) {
            $this->markAllSitesStale();

            return;
        }

        foreach ($sites as $site) {
            Sitemapamic::markStale($site);
        }
    }
}
