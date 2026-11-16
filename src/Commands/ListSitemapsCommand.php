<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use Statamic\Facades\Site;

class ListSitemapsCommand extends Command
{
    protected $signature = 'sitemapamic:list {--site= : The Statamic site handle}';

    protected $description = 'List published Sitemapamic child endpoints';

    public function handle(): int
    {
        $handle = $this->option('site');
        $sites = Site::all();

        if ($handle !== null) {
            $site = Site::get($handle);
            if ($site === null) {
                $this->error('Unknown sitemap site: '.$handle);

                return self::FAILURE;
            }

            $sites = [$site];
        }

        $rows = [];
        foreach ($sites as $site) {
            foreach (Sitemapamic::manifest($site)->files as $file) {
                $rows[] = [
                    $site->handle(),
                    $file->source,
                    $file->filename,
                    $file->count,
                    $file->bytes,
                    Sitemapamic::url($site, $file->filename),
                ];
            }
        }

        $this->table(['Site', 'Source', 'Filename', 'URLs', 'Bytes', 'URL'], $rows);

        return self::SUCCESS;
    }
}
