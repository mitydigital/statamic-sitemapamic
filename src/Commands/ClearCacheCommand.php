<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use MityDigital\Sitemapamic\Facades\Sitemapamic;

class ClearCacheCommand extends Command
{
    protected $signature = 'sitemapamic:clear {--site= : The Statamic site handle}';

    protected $description = 'Clear the Sitemapamic cache';

    public function handle(): int
    {
        try {
            Sitemapamic::clearCache($this->option('site'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Sitemapamic cache cleared.');

        return self::SUCCESS;
    }
}
