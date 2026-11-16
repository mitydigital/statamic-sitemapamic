<?php

namespace MityDigital\Sitemapamic\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MityDigital\Sitemapamic\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $fixturePath;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->fixturePath = sys_get_temp_dir().'/sitemapamic-test-'.Str::uuid();
        $app['config']->set([
            'app.url' => 'http://localhost',
            'cache.default' => 'array',
            'cache.stores.array.serialize' => true,
            'statamic.stache.stores.collections.directory' => $this->fixturePath.'/collections',
            'statamic.stache.stores.entries.directory' => $this->fixturePath.'/collections',
            'statamic.stache.stores.taxonomies.directory' => $this->fixturePath.'/taxonomies',
            'statamic.stache.stores.terms.directory' => $this->fixturePath.'/taxonomies',
            'statamic.stache.stores.collection-trees.directory' => $this->fixturePath.'/trees',
            'view.paths' => [$this->fixturePath.'/views'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function template(string $name): void
    {
        $path = $this->fixturePath.'/views/'.$name.'.blade.php';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<main>Public page</main>');
    }
}
