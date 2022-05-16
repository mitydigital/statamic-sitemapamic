<?php

namespace MityDigital\Sitemapamic\UpdateScripts\v2_0_1;

use Illuminate\Support\Facades\Artisan;
use Statamic\UpdateScripts\UpdateScript;

class MoveConfigFile extends UpdateScript
{
    public function shouldUpdate($newVersion, $oldVersion)
    {
        return $this->isUpdatingTo('2.0.1');
    }

    public function update()
    {
        // check if the config is cached
        if ($configurationIsCached = app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }

        // clear Sitemapamic cache
        Artisan::call('statamic:sitemapamic:clear');

        // if the config file exists within the 'config/statamic' path, move it just to 'config'
        if (file_exists(config_path('statamic/sitemapamic.php'))) {
            if (file_exists(config_path('sitemapamic.php'))) {
                // cannot copy
                $this->console()->alert('The Sitemapamic config file could not be moved to `config/sitemapamic.php` - it already exists!');
                $this->console()->alert('You will need to manually make sure your `config/sitemapamic.php` file is correctly configured.');
            } else {
                // move the config file
                rename(config_path('statamic/sitemapamic.php'), config_path('sitemapamic.php'));

                // output
                $this->console()->info('Sitemapamic config file has been moved to `config/sitemapamic.php`!');
            }
        }

        // re-cache config if it was cached
        if ($configurationIsCached) {
            Artisan::call('config:cache');
        }
    }
}