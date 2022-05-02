<?php

namespace MityDigital\Sitemap;

use MityDigital\Sitemap\Listeners\ClearSitemapCache;
use MityDigital\Sitemap\Commands\ClearCacheCommand;
use Statamic\Events\EntrySaved;
use Statamic\Events\TermSaved;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'mitydigital/sitemap';

    protected $commands = [
        ClearCacheCommand::class
    ];

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $listen = [
        EntrySaved::class => [
            ClearSitemapCache::class,
        ],
        TermSaved::class  => [
            ClearSitemapCache::class,
        ],
    ];

    public function boot()
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../config/sitemap.php', 'statamic.sitemap');

        $this->publishes([
            __DIR__.'/../config/sitemap.php' => config_path('statamic/sitemap.php')
        ], 'config');
    }
}
