<?php

namespace MityDigital\Sitemapamic\Support\Xml;

use Illuminate\Contracts\View\Factory;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;

class Renderer
{
    public function __construct(private Factory $views) {}

    public function urlsetStart(): string
    {
        return $this->views->make('sitemapamic::urlset.start')->render();
    }

    public function url(SitemapamicUrl $url): string
    {
        return $this->views->make('sitemapamic::urlset.url', ['url' => $url])->render();
    }

    public function urlsetEnd(): string
    {
        return $this->views->make('sitemapamic::urlset.end')->render();
    }

    public function indexStart(): string
    {
        return $this->views->make('sitemapamic::index.start')->render();
    }

    public function sitemap(string $location): string
    {
        return $this->views->make('sitemapamic::index.sitemap', ['location' => $location])->render();
    }

    public function indexEnd(): string
    {
        return $this->views->make('sitemapamic::index.end')->render();
    }
}
