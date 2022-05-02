<?php

namespace MityDigital\Sitemap\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class ClearSitemapCache implements ShouldQueue
{
    /**
     * Simply clear the Statamic XML Sitemap cache
     */
    public function handle()
    {
        Cache::forget(config('statamic.sitemap.cache'));
    }
}
