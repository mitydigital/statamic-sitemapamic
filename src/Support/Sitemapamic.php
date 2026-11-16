<?php

namespace MityDigital\Sitemapamic\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use InvalidArgumentException;
use MityDigital\Sitemapamic\Data\Manifest;
use MityDigital\Sitemapamic\Data\SitemapDocument;
use MityDigital\Sitemapamic\Data\SitemapFile;
use MityDigital\Sitemapamic\Exceptions\SitemapBusyException;
use MityDigital\Sitemapamic\Support\Cache\SitemapCache;
use MityDigital\Sitemapamic\Support\Content\DynamicRoutes;
use MityDigital\Sitemapamic\Support\Manifest\Builder;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use stdClass;
use Throwable;

class Sitemapamic
{
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private DynamicRoutes $dynamicRoutes,
        private Builder $builder,
        private SitemapCache $cache,
        private Configuration $configuration,
    ) {}

    public function addDynamicRoutes(Closure $routes, array|string|null $sites = null): void
    {
        $this->dynamicRoutes->add($routes, $sites);
    }

    public function url(Site|string|null $site = null, string $filename = 'sitemap.xml'): string
    {
        return SiteUrls::sitemapUrl($this->resolveSite($site), $filename);
    }

    public function manifest(Site|string|null $site = null): Manifest
    {
        $site = $this->resolveSite($site);
        if (! $this->cache->enabled()) {
            return $this->buildWithoutStoring($site);
        }

        return $this->currentManifest($site);
    }

    public function clearCache(Site|string|null $site = null): void
    {
        foreach ($this->siteHandles($site) as $handle) {
            $this->cache->clear($handle);
        }
    }

    public function markStale(Site|string|null $site = null): void
    {
        foreach ($this->siteHandles($site) as $handle) {
            $this->cache->markStale($handle);
        }
    }

    public function document(Site $site, string $filename): ?SitemapDocument
    {
        if (! $this->cache->enabled()) {
            return $this->uncachedDocument($site, $filename);
        }

        try {
            return $this->cache->open($this->currentManifest($site), $filename);
        } catch (SitemapBusyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // the cache failed, so build this one response without it
            report($exception);

            return $this->uncachedDocument($site, $filename);
        }
    }

    private function currentManifest(Site $site): Manifest
    {
        $manifest = $this->cache->manifest($site->handle());
        if ($manifest !== null && $this->cache->isFresh($manifest)) {
            return $manifest;
        }

        $lock = $this->cache->lock($site->handle());

        // with something to serve, don't wait for another request's build
        if ($manifest !== null && ! $lock->get()) {
            return $manifest;
        }

        // if there's no manifest, wait for the lock
        if ($manifest === null) {
            $this->waitForLock($lock, $site);
        }

        try {
            // another request may have just published
            $latest = $this->cache->manifest($site->handle());
            if ($latest !== null && $this->cache->isFresh($latest)) {
                return $latest;
            }

            return $this->rebuild($site, $latest);
        } finally {
            $lock->release();
        }
    }

    private function waitForLock(Lock $lock, Site $site): void
    {
        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new SitemapBusyException('The sitemap for site '.$site->handle().' is being built.');
        }
    }

    private function rebuild(Site $site, ?Manifest $stale): Manifest
    {
        // publish only if no content changed while building, and try twice
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $version = $this->cache->version($site->handle());
            $manifest = $this->buildAndStore($site, $version);

            if ($this->cache->version($site->handle()) === $version) {
                $this->cache->publish($manifest);

                return $manifest;
            }

            $this->cache->deleteDirectory($manifest->directory);
        }

        if ($stale !== null) {
            return $stale;
        }

        throw new SitemapBusyException('The content for site '.$site->handle().' kept changing during the build.');
    }

    private function buildAndStore(Site $site, string $version): Manifest
    {
        $directory = $this->cache->newDirectory($site->handle());

        try {
            $store = fn (string $filename, $stream) => $this->storeFile($directory, $filename, $stream);
            $files = $this->builder->generate($site, $store);
        } catch (Throwable $exception) {
            $this->cache->deleteDirectory($directory);

            throw $exception;
        }

        return $this->newManifest($site, $version, $directory, $files);
    }

    /** @param resource $stream */
    private function storeFile(string $directory, string $filename, $stream): void
    {
        try {
            $this->cache->store($directory, $filename, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function uncachedDocument(Site $site, string $filename): ?SitemapDocument
    {
        $kept = new stdClass;
        $kept->stream = null;

        try {
            $keep = fn (string $name, $stream) => $this->keepOnly($kept, $filename, $name, $stream);
            $files = $this->builder->generate($site, $keep);
        } catch (Throwable $exception) {
            if ($kept->stream !== null) {
                fclose($kept->stream);
            }

            throw $exception;
        }

        $file = $this->newManifest($site, '', '', $files)->file($filename);
        if ($file === null) {
            return null;
        }

        return new SitemapDocument($kept->stream, $file->bytes);
    }

    /** @param resource $stream */
    private function keepOnly(stdClass $kept, string $wanted, string $filename, $stream): void
    {
        if ($filename === $wanted) {
            $kept->stream = $stream;

            return;
        }

        fclose($stream);
    }

    private function buildWithoutStoring(Site $site): Manifest
    {
        $files = $this->builder->generate($site, fn (string $filename, $stream) => fclose($stream));

        return $this->newManifest($site, '', '', $files);
    }

    /** @param array{files: list<SitemapFile>, index: ?SitemapFile} $files */
    private function newManifest(Site $site, string $version, string $directory, array $files): Manifest
    {
        $builtAt = now()->getTimestamp();
        $ttl = $this->configuration->ttl();

        return new Manifest(
            site: $site->handle(),
            version: $version,
            directory: $directory,
            builtAt: $builtAt,
            expiresAt: $ttl === null ? null : $builtAt + $ttl,
            configHash: $this->cache->configHash(),
            files: collect($files['files'])->keyBy('filename')->all(),
            index: $files['index'],
        );
    }

    /**
     * Null is every site. A Site object is used as given, so a deleted site can still be cleared.
     *
     * @return list<string>
     */
    private function siteHandles(Site|string|null $site): array
    {
        if ($site === null) {
            return Sites::all()->map->handle()->values()->all();
        }

        return [$this->resolveSite($site)->handle()];
    }

    private function resolveSite(Site|string|null $site): Site
    {
        if ($site instanceof Site) {
            return $site;
        }

        if ($site === null) {
            return Sites::current();
        }

        $resolved = Sites::get($site);
        if ($resolved === null) {
            throw new InvalidArgumentException('Unknown sitemap site: '.$site);
        }

        return $resolved;
    }
}
