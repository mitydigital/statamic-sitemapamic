<?php

namespace MityDigital\Sitemapamic;

use MityDigital\Sitemapamic\Commands\ClearCacheCommand;
use MityDigital\Sitemapamic\Commands\ListSitemapsCommand;
use MityDigital\Sitemapamic\Dictionaries\ChangeFrequencies;
use MityDigital\Sitemapamic\Fieldtypes\ChangeFrequencyFieldtype;
use MityDigital\Sitemapamic\Listeners\ClearSitemapamicCache;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Sitemapamic;
use MityDigital\Sitemapamic\Support\StaticSite\StaticSiteUrls;
use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticSite\Generator;

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

        // the static site generator only writes the URLs it is given; adding them on resolve keeps it out of ordinary requests
        if (class_exists(Generator::class)) {
            $this->app->afterResolving(Generator::class, function (Generator $generator): void {
                $generator->addUrls(fn (): array => $this->app->make(StaticSiteUrls::class)->urls());
            });
        }
    }
}
