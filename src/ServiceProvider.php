<?php

namespace MityDigital\Sitemapamic;

use MityDigital\Sitemapamic\Commands\ClearCacheCommand;
use MityDigital\Sitemapamic\Commands\ListSitemapsCommand;
use MityDigital\Sitemapamic\Dictionaries\ChangeFrequencies;
use MityDigital\Sitemapamic\Fieldtypes\ChangeFrequencyFieldtype;
use MityDigital\Sitemapamic\Listeners\ClearSitemapamicCache;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'sitemapamic';

    protected $commands = [
        ClearCacheCommand::class,
        ListSitemapsCommand::class,
    ];

    protected $dictionaries = [
        ChangeFrequencies::class,
    ];

    protected $fieldtypes = [
        ChangeFrequencyFieldtype::class,
    ];

    protected $subscribe = [
        ClearSitemapamicCache::class,
    ];

    public function register(): void
    {
        // the service is rebuilt for each request; boot-time dynamic routes must outlive requests
        $this->app->scoped(Sitemapamic::class);
        $this->app->singleton(DynamicRoutes::class);
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/sitemapamic'),
        ], 'sitemapamic-views');
    }
}
