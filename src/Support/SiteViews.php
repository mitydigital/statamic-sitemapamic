<?php

namespace MityDigital\Sitemapamic\Support;

use Closure;
use Illuminate\View\ViewFinderInterface;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use WeakMap;

class SiteViews
{
    /** @var WeakMap<ViewFinderInterface, ViewFinderInterface>|null each site finder and the finder it was made from */
    private static ?WeakMap $unswitched = null;

    /** Run $callback as $site, with the site's own view folders first, then restore both. */
    public function use(Site $site, Closure $callback): mixed
    {
        self::$unswitched ??= new WeakMap;

        $originalSite = Sites::current();
        $viewFactory = view();
        $originalFinder = $viewFactory->getFinder();

        // inside another site's views, start again from the unswitched finder
        $baseFinder = self::$unswitched[$originalFinder] ?? $originalFinder;

        $siteFinder = clone $baseFinder;
        $siteFinder->flush();
        $siteFinder->setPaths($this->sitePaths($site, $baseFinder->getPaths()));
        foreach ($baseFinder->getHints() as $namespace => $paths) {
            $siteFinder->replaceNamespace($namespace, $this->sitePaths($site, $paths));
        }
        self::$unswitched[$siteFinder] = $baseFinder;

        try {
            Sites::setCurrent($site->handle());
            $viewFactory->setFinder($siteFinder);

            return $callback();
        } finally {
            $viewFactory->setFinder($originalFinder);
            Sites::setCurrent($originalSite->handle());
        }
    }

    /** @return list<string> */
    private function sitePaths(Site $site, array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (is_dir($path.'/'.$site->handle())) {
                $result[] = $path.'/'.$site->handle();
            }

            $result[] = $path;
        }

        return $result;
    }
}
