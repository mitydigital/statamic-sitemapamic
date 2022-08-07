<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Stache;

class ClearSitemapamicCacheCommand extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:sitemapamic:clear {sitemaps?* : Optional, the sitemap keys you want to flush. Only applies when \'mode\' is \'multiple\'. Can be multiple keys by space to clear specific sitemaps, or omit to clear everything.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the Sitemapamic cache';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $sitemaps = $this->argument('sitemaps');

        if (count($sitemaps) == 0)
        {
            // default cache clearing behaviour
            // clear it all
            if (Sitemapamic::clearCache()) {
                $this->info('Snip snip and whoosh, it\'s all gone.');
            }
            else {
                $this->error('Uh oh... Sitemapamic could not clear the entire cache.');
            }
        }
        else {
            // make a neat array
            $keys = [];
            foreach ($sitemaps as $key) {
                if (!in_array('"'.$key.'"', $keys)) {
                    $keys[] = '"'.$key.'"';
                }
            }

            // make it prettier
            // Arr::join came in Laravel 9 - so do it manually for L8 support
            if (count($keys) > 1) {
                $lastKey = array_pop($keys);
                $keys = implode(', ', $keys).' and '.$lastKey;
            } else {
                $keys = end($keys);
            }

            // clear specific sitemaps only
            if (Sitemapamic::clearCache($sitemaps)) {
                $this->info('Snip snip and whoosh, sitemaps for '.$keys.' are gone.');
            } else {
                $this->error('Sitemaps for '.$keys.' could not be cleared.');
            }
        }
    }
}
