<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Tests\EloquentTestCase;
use MityDigital\Sitemapamic\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(EloquentTestCase::class)->in('Eloquent');

/** Make the default array cache store refuse forever() writes, keeping everything it already holds. */
function refuseForeverCacheWrites(): void
{
    $repository = Cache::store();
    $repository->setStore(new class($repository->getStore()) extends ArrayStore
    {
        public function __construct(ArrayStore $store)
        {
            parent::__construct($store->serializesValues, $store->serializableClasses);
            $this->storage = $store->storage;
            $this->locks = $store->locks;
        }

        public function forever($key, $value): bool
        {
            return false;
        }
    });
}
