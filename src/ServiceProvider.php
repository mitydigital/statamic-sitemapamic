<?php

namespace MityDigital\Sitemapamic;

use MityDigital\Sitemapamic\Listeners\ClearSitemapamicCache;
use MityDigital\Sitemapamic\Commands\ClearSitemapamicCacheCommand;
use Statamic\Events\EntrySaved;
use Statamic\Events\TermSaved;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'mitydigital/sitemapamic';

    protected $commands = [
        ClearSitemapamicCacheCommand::class
    ];

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $listen = [
        EntrySaved::class => [
            ClearSitemapamicCache::class,
        ],
        TermSaved::class  => [
            ClearSitemapamicCache::class,
        ],
    ];

    public function boot()
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../config/sitemapamic.php', 'statamic.sitemapamic');

        $this->publishes([
            __DIR__.'/../config/sitemapamic.php' => config_path('statamic/sitemapamic.php')
        ], 'config');
    }
}
