<?php

namespace MityDigital\Sitemapamic;

use MityDigital\Sitemapamic\Listeners\ClearSitemapamicCache;
use MityDigital\Sitemapamic\Commands\ClearSitemapamicCacheCommand;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\TermDeleted;
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
        EntryDeleted::class => [
            ClearSitemapamicCache::class,
        ],
        EntrySaved::class => [
            ClearSitemapamicCache::class,
        ],
        TermDeleted::class  => [
            ClearSitemapamicCache::class,
        ],
        TermSaved::class  => [
            ClearSitemapamicCache::class,
        ],
    ];

    protected $updateScripts = [
        // v2.0.1
        \MityDigital\Sitemapamic\UpdateScripts\v2_0_1\MoveConfigFile::class
    ];

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/mitydigital/sitemapamic'),
        ], 'sitemapamic-views');
    }
}
