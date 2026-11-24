<?php

namespace MityDigital\Sitemapamic\Tests\Fixtures;

use Statamic\Entries\Entry;
use WeakReference;

/** An entry class that remembers each entry that was loaded, to count how many are still in memory. */
class TrackedEntry extends Entry
{
    /** @var list<WeakReference<self>> */
    public static array $loaded = [];

    // Statamic sets this as it loads an entry
    public function published($published = null)
    {
        self::$loaded[] = WeakReference::create($this);

        return parent::published(...func_get_args());
    }

    public static function countInMemory(): int
    {
        return count(array_filter(self::$loaded, fn (WeakReference $entry): bool => $entry->get() !== null));
    }
}
