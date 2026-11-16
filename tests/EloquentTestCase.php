<?php

namespace MityDigital\Sitemapamic\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider as EloquentServiceProvider;

/** Runs a test with collections, entries, taxonomies and terms stored by the Eloquent driver. */
abstract class EloquentTestCase extends TestCase
{
    use RefreshDatabase;

    /** The content stores this addon reads, moved from the Stache to the database. */
    private const array ELOQUENT_STORES = ['collections', 'collection_trees', 'entries', 'taxonomies', 'terms'];

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        // the driver binds its repositories when it registers, so its config must be in place first
        $app['config']->set('statamic.eloquent-driver', require $this->driverPath('config/eloquent-driver.php'));
        foreach (self::ELOQUENT_STORES as $store) {
            $app['config']->set('statamic.eloquent-driver.'.$store.'.driver', 'eloquent');
        }

        // string entry ids, as the driver's installer sets up; the default model casts ids to integers
        $app['config']->set('statamic.eloquent-driver.entries.model', UuidEntryModel::class);
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), EloquentServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set([
            'database.default' => 'testing',
            'statamic.stache.watcher' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom($this->driverPath('database/migrations'));
        $this->loadMigrationsFrom($this->driverPath('database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php'));
    }

    private function driverPath(string $path): string
    {
        return dirname(__DIR__).'/vendor/statamic/eloquent-driver/'.$path;
    }
}
