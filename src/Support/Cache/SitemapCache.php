<?php

namespace MityDigital\Sitemapamic\Support\Cache;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapDocument;
use RuntimeException;

class SitemapCache
{
    /** How long a build's lock lasts when PHP has no time limit. */
    private const int UNLIMITED_LOCK_SECONDS = 600;

    private const int MIN_LOCK_SECONDS = 60;

    /** How long a build's lock outlasts PHP's time limit. */
    private const int LOCK_MARGIN_SECONDS = 30;

    public function enabled(): bool
    {
        return (bool) config('sitemapamic.cache.enabled');
    }

    public function manifest(string $site): ?Manifest
    {
        $manifest = Cache::get($this->key($site, 'manifest'));
        if ($manifest === null) {
            return null;
        }

        return Manifest::fromArray($manifest);
    }

    public function isFresh(Manifest $manifest): bool
    {
        // a content change gives the site a new version
        if ($manifest->version !== $this->version($manifest->site)) {
            return false;
        }

        // an expired manifest is treated like a stale one
        if ($manifest->expiresAt !== null && $manifest->expiresAt <= now()->getTimestamp()) {
            return false;
        }

        return $manifest->configHash === $this->configHash();
    }

    public function configHash(): string
    {
        return md5(serialize(config('sitemapamic')));
    }

    public function version(string $site): string
    {
        return Cache::rememberForever($this->key($site, 'version'), fn (): string => Str::random(32));
    }

    public function lock(string $site): Lock
    {
        return Cache::lock($this->key($site, 'build'), $this->lockSeconds());
    }

    /** A build that PHP stops can't release its lock, so the lock expires soon after PHP's time limit. */
    private function lockSeconds(): int
    {
        $timeLimit = (int) ini_get('max_execution_time');
        if ($timeLimit === 0) {
            return self::UNLIMITED_LOCK_SECONDS;
        }

        return max(self::MIN_LOCK_SECONDS, $timeLimit + self::LOCK_MARGIN_SECONDS);
    }

    public function newDirectory(string $site): string
    {
        return $this->siteDirectory($site).'/'.Str::lower((string) Str::ulid());
    }

    /** @param resource $stream */
    public function store(string $directory, string $filename, $stream): void
    {
        if (! $this->disk()->writeStream($directory.'/'.$filename, $stream)) {
            throw new RuntimeException('Unable to write '.$filename.' to '.$directory.'.');
        }
    }

    public function publish(Manifest $manifest): void
    {
        Cache::forever($this->key($manifest->site, 'manifest'), $manifest->toArray());

        // the published directory is now the only one needed
        foreach ($this->disk()->directories($this->siteDirectory($manifest->site)) as $directory) {
            if ($directory !== $manifest->directory) {
                $this->deleteDirectory($directory);
            }
        }
    }

    public function open(Manifest $manifest, string $filename): ?SitemapDocument
    {
        $file = $manifest->file($filename);
        if ($file === null) {
            return null;
        }

        // if the file has gone, forget this manifest so the next request rebuilds
        $stream = $this->readStream($manifest->directory.'/'.$filename);
        if ($stream === null) {
            $this->forgetIfCurrent($manifest);

            throw new RuntimeException('Cached sitemap file '.$filename.' is missing for site '.$manifest->site.'.');
        }

        return new SitemapDocument($stream, $file->bytes);
    }

    public function markStale(string $site): void
    {
        Cache::forever($this->key($site, 'version'), Str::random(32));
    }

    public function clear(string $site): void
    {
        $this->markStale($site);
        Cache::forget($this->key($site, 'manifest'));
        $this->deleteDirectory($this->siteDirectory($site));
    }

    public function deleteDirectory(string $directory): void
    {
        $this->disk()->deleteDirectory($directory);
    }

    /** @return resource|null null when the file cannot be read, whether the disk returns null or throws */
    private function readStream(string $path)
    {
        try {
            return $this->disk()->readStream($path);
        } catch (UnableToReadFile) {
            return null;
        }
    }

    private function forgetIfCurrent(Manifest $manifest): void
    {
        // a newer build may have replaced this manifest already, so leave that one alone
        if ($this->manifest($manifest->site)?->directory === $manifest->directory) {
            Cache::forget($this->key($manifest->site, 'manifest'));
        }
    }

    private function key(string $site, string $name): string
    {
        return config('sitemapamic.cache.key').':'.$site.':'.$name;
    }

    private function siteDirectory(string $site): string
    {
        return config('sitemapamic.cache.key').'/'.$site;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('sitemapamic.cache.disk'));
    }
}
