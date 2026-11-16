<?php

namespace MityDigital\Sitemapamic\Tests;

use Statamic\Facades\Site;

trait UsesSitemapSites
{
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set([
            'app.url' => 'http://example.test',
            'statamic.system.multisite' => true,
            'statamic.editions.pro' => true,
            'sitemapamic.collections' => [],
            'sitemapamic.taxonomies' => [],
            'sitemapamic.cache.enabled' => false,
            'session.driver' => 'array',
        ]);
        Site::setSites([
            'default' => ['locale' => 'en_AU', 'url' => '/'],
            'editorial_eu' => ['locale' => 'de_DE', 'url' => '/nested/region/'],
            'nested_parent' => ['locale' => 'en_GB', 'url' => '/nested/'],
            'archive' => ['locale' => 'fr_FR', 'url' => 'https://island.example.test/branch/'],
            'preview' => ['locale' => 'en_US', 'url' => 'http://preview.example.test:8088/deep/'],
        ]);
    }
}
