<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Stache;

class ListSitemapamicCacheKeysCommand extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:sitemapamic:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all of the Sitemapamic cache keys';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->table(['Key'], collect(Sitemapamic::getCacheKeys())->map(fn($key) => ['key' => $key]));
    }
}
