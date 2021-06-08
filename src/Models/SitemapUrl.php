<?php

namespace MityDigital\StatamicXmlSitemap\Models;

class SitemapUrl
{
    public $loc;
    public $lastmod;
    public $changefreq;
    public $priority;

    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }
}