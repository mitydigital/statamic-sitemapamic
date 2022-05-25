<?php

namespace MityDigital\Sitemapamic\Support;

class Sitemapamic
{
    protected $dynamicRoutes = [];

    public function addDynamicRoutes($routesClosure)
    {
        $this->dynamicRoutes = $routesClosure;
    }

    public function getDynamicRoutes()
    {
        return $this->dynamicRoutes;
    }
}
