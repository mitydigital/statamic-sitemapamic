<?php

namespace MityDigital\Sitemapamic\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Facades\Sitemapamic;

class ClearSitemapamicCache implements ShouldQueue
{
    /**
     * Simply clear the entire Sitemapamic cache
     */
    public function handle()
    {
        Sitemapamic::clearCache();
    }
}
