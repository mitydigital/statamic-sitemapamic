<?php

namespace MityDigital\Sitemapamic\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Commands\ClearSitemapamicCacheCommand;
use Statamic\Events\EntrySaved;

class ScheduledCacheInvalidated implements ShouldQueue
{
    public function handle(\MityDigital\StatamicScheduledCacheInvalidator\Events\ScheduledCacheInvalidated $event) {
        Artisan::call(ClearSitemapamicCacheCommand::class);
    }
}