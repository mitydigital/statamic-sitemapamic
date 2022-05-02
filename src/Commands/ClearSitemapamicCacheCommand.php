<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
    protected $signature = 'statamic:sitemap:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the Statamic Sitemap cache';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        Cache::forget(config('statamic.sitemapamic.cache'));

        $this->info('Snip snip and whoosh, it\'s all gone.');
    }
}
