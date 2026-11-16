<?php

namespace MityDigital\Sitemapamic\Data;

final readonly class Manifest
{
    /** @param array<string, SitemapFile> $files child files keyed by filename */
    public function __construct(
        public string $site,
        public string $version,
        public string $directory,
        public int $builtAt,
        public ?int $expiresAt,
        public string $configHash,
        public array $files,
        public ?SitemapFile $index,
    ) {}

    public static function fromArray(array $manifest): self
    {
        $files = [];
        foreach ($manifest['files'] as $file) {
            $files[$file['filename']] = SitemapFile::fromArray($file);
        }

        $index = $manifest['index'] === null ? null : SitemapFile::fromArray($manifest['index']);

        return new self(
            site: $manifest['site'],
            version: $manifest['version'],
            directory: $manifest['directory'],
            builtAt: $manifest['built_at'],
            expiresAt: $manifest['expires_at'],
            configHash: $manifest['config_hash'],
            files: $files,
            index: $index,
        );
    }

    // sitemap.xml is the index; every other name is a child file
    public function file(string $filename): ?SitemapFile
    {
        if ($filename === 'sitemap.xml') {
            return $this->index;
        }

        return $this->files[$filename] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'version' => $this->version,
            'directory' => $this->directory,
            'built_at' => $this->builtAt,
            'expires_at' => $this->expiresAt,
            'config_hash' => $this->configHash,
            'files' => array_values(array_map(fn (SitemapFile $file): array => $file->toArray(), $this->files)),
            'index' => $this->index?->toArray(),
        ];
    }
}
