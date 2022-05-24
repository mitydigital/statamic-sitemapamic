<?php

namespace MityDigital\Sitemapamic\Support;

class Sitemapamic
{
    protected $dynamicRoutes = [];

    public function addDynamicRoutes(array $routes)
    {
        $this->dynamicRoutes = array_merge($this->dynamicRoutes, $routes);
    }

    public function getDynamicRoutes()
    {
        return $this->dynamicRoutes;
    }
}
