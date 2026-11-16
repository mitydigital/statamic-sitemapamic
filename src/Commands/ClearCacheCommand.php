<?php

namespace MityDigital\Sitemapamic\Commands;

use Illuminate\Console\Command;
use MityDigital\Sitemapamic\Facades\Sitemapamic;
use Statamic\Facades\Site;
use Statamic\Sites\Site as StatamicSite;
use Throwable;

class ClearCacheCommand extends Command
{
    protected $signature = 'sitemapamic:clear {--site= : The Statamic site handle, or omit to clear every site}';

    protected $description = 'Clear the Sitemapamic cache';

    public function handle(): int
    {
        $handle = $this->option('site');
        $sites = Site::all()->values()->all();

        if ($handle !== null) {
            $site = Site::get($handle);
            if ($site === null) {
                $this->error('❌ Uh oh... there is no site with the handle "'.$handle.'".');

                return self::FAILURE;
            }

            $sites = [$site];
        }

        $failed = [];
        foreach ($sites as $site) {
            // one site failing shouldn't stop the others being cleared
            try {
                Sitemapamic::clearCache($site);
                $this->line('✅ Cleared the sitemap cache for '.$this->describe($site));
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $site;
                $this->error('❌ Could not clear the sitemap cache for '.$this->describe($site).': '.$exception->getMessage());
            }
        }

        $this->newLine();

        if ($failed !== []) {
            $this->error('Uh oh... Sitemapamic could not clear the cache for '.$this->siteList($failed).'.');

            return self::FAILURE;
        }

        $this->info($handle === null
            ? 'Snip snip and whoosh, all of your sitemaps are gone.'
            : 'Snip snip and whoosh, the sitemap for '.$this->siteList($sites).' is gone.');

        return self::SUCCESS;
    }

    private function describe(StatamicSite $site): string
    {
        return $site->name() === $site->handle()
            ? $site->handle()
            : $site->name().' ('.$site->handle().')';
    }

    /** @param list<StatamicSite> $sites */
    private function siteList(array $sites): string
    {
        $handles = array_map(fn (StatamicSite $site): string => '"'.$site->handle().'"', $sites);

        return collect($handles)->join(', ', ' and ');
    }
}
