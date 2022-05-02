<?php

namespace MityDigital\Sitemapamic\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class ClearSitemapamicCache implements ShouldQueue
{
    /**
     * Simply clear the Sitemapamic cache
     */
    public function handle()
    {
        Cache::forget(config('statamic.sitemapamic.cache'));
    }
}
