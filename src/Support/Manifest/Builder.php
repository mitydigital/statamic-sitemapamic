<?php

namespace MityDigital\Sitemapamic\Support\Manifest;

use ArrayObject;
use Carbon\CarbonInterface;
use Closure;
use Generator;
use MityDigital\Sitemapamic\Data\SitemapFile;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Support\Configuration;
use MityDigital\Sitemapamic\Support\Content\SourceResolver;
use MityDigital\Sitemapamic\Support\Content\UrlIterator;
use MityDigital\Sitemapamic\Support\SiteUrls;
use MityDigital\Sitemapamic\Support\SiteViews;
use MityDigital\Sitemapamic\Support\Xml\Renderer;
use RuntimeException;
use Statamic\Sites\Site;

class Builder
{
    /** The source name of the merged files in single mode. */
    private const string SINGLE_SOURCE = 'all';

    public function __construct(
        private SourceResolver $sourceResolver,
        private UrlIterator $urlIterator,
        private Renderer $renderer,
        private SiteViews $siteViews,
        private Writer $writer = new Writer,
        private Configuration $configuration = new Configuration,
    ) {}

    /**
     * Write every sitemap file for a site, handing each finished file to $store.
     * $store owns each stream it is given.
     *
     * @param  Closure(string, resource): void  $store
     * @return array{files: list<SitemapFile>, index: ?SitemapFile}
     */
    public function generate(Site $site, Closure $store): array
    {
        return $this->siteViews->use($site, fn (): array => $this->generateFiles($site, $store));
    }

    /** @return array{files: list<SitemapFile>, index: ?SitemapFile} */
    private function generateFiles(Site $site, Closure $store): array
    {
        $sources = $this->sourceResolver->resolve($site);

        return $this->configuration->isSingleFile()
            ? $this->generateSingleFile($site, $sources, $store)
            : $this->generatePerSource($site, $sources, $store);
    }

    /**
     * Merge every source into one file, which is served as sitemap.xml itself. Past a writer
     * limit, the merged URLs are split into numbered files listed by an index instead.
     *
     * @param  list<Source>  $sources
     * @return array{files: list<SitemapFile>, index: ?SitemapFile}
     */
    private function generateSingleFile(Site $site, array $sources, Closure $store): array
    {
        // hold the first file back until it is known whether it is the only one
        $held = null;
        $holdFirst = function (string $filename, $stream) use (&$held, $store): void {
            // false marks that the first file has been handed on, so later files pass straight through
            if (is_array($held)) {
                [$heldFilename, $heldStream] = $held;
                $held = false;
                $store($heldFilename, $heldStream);
            }

            if ($held === null) {
                $held = [$filename, $stream];

                return;
            }

            $store($filename, $stream);
        };

        try {
            $termDates = new ArrayObject;
            $fragments = (function () use ($sources, $termDates): Generator {
                foreach ($sources as $source) {
                    yield from $this->fragments($source, $termDates);
                }
            })();
            $files = $this->writer->write(self::SINGLE_SOURCE, $fragments, $this->renderer->urlsetStart(), $this->renderer->urlsetEnd(), $holdFirst);

            if (is_array($held)) {
                $stream = $held[1];
                $held = null;
                $store('sitemap.xml', $stream);
                $file = new SitemapFile('sitemap.xml', self::SINGLE_SOURCE, $files[0]->count, $files[0]->bytes, $files[0]->hash);

                return ['files' => [$file], 'index' => $file];
            }
        } finally {
            // if something failed part-way, close the file nobody received
            if (is_array($held)) {
                fclose($held[1]);
            }
        }

        if ($files === []) {
            return ['files' => [], 'index' => null];
        }

        return ['files' => $files, 'index' => $this->writeIndex($site, $files, $store)];
    }

    /**
     * @param  list<Source>  $sources
     * @return array{files: list<SitemapFile>, index: ?SitemapFile}
     */
    private function generatePerSource(Site $site, array $sources, Closure $store): array
    {
        $start = $this->renderer->urlsetStart();
        $end = $this->renderer->urlsetEnd();
        $files = [];
        $sourceForFile = [];
        $termDates = new ArrayObject;

        // write each source's files, in the resolver's stable order
        foreach ($sources as $source) {
            $written = $this->writer->write($source->name(), $this->fragments($source, $termDates), $start, $end, $store);

            foreach ($written as $file) {
                $earlier = $sourceForFile[$file->filename] ?? null;
                if ($earlier !== null) {
                    $sources = $this->describe($earlier).' and '.$this->describe($source);

                    throw new RuntimeException('Sources '.$sources.' both produce '.$file->filename.'.');
                }

                $sourceForFile[$file->filename] = $source;
                $files[] = $file;
            }
        }

        // an index is only needed when there is something to list
        if ($files === []) {
            return ['files' => [], 'index' => null];
        }

        return ['files' => $files, 'index' => $this->writeIndex($site, $files, $store)];
    }

    /**
     * @param  ArrayObject<string, array<string, array<string, CarbonInterface>>>  $termDates  shared by every source in the build
     * @return Generator<int, string>
     */
    private function fragments(Source $source, ArrayObject $termDates): Generator
    {
        foreach ($this->urlIterator->forSource($source, $termDates) as $url) {
            yield $this->renderer->url($url);
        }
    }

    /** @param list<SitemapFile> $files */
    private function writeIndex(Site $site, array $files, Closure $store): SitemapFile
    {
        $contents = $this->renderer->indexStart();
        foreach ($files as $file) {
            $contents .= $this->renderer->sitemap(SiteUrls::sitemapUrl($site, $file->filename));
        }
        $contents .= $this->renderer->indexEnd();

        $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        $store('sitemap.xml', $stream);

        return new SitemapFile('sitemap.xml', 'index', count($files), strlen($contents), hash(SitemapFile::HASH_ALGORITHM, $contents));
    }

    private function describe(Source $source): string
    {
        if ($source->dynamic) {
            return 'dynamic routes';
        }

        if ($source->isCollectionTaxonomy()) {
            return 'collection '.$source->collection.' with taxonomy '.$source->taxonomy;
        }

        return $source->isCollection() ? 'collection '.$source->collection : 'taxonomy '.$source->taxonomy;
    }
}
