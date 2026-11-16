<?php

namespace MityDigital\Sitemapamic\Support;

use InvalidArgumentException;
use MityDigital\Sitemapamic\Enums\ChangeFrequency;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

class Configuration
{
    /**
     * A '*' selects every collection, each with every attached taxonomy, and a plain handle beside it changes
     * nothing. Without '*', a plain handle selects entries only. Either way, a taxonomies key sets that
     * collection's contextual taxonomies and a handle set to false is left out.
     *
     * @return array<string, list<string>|null> collection handles, each with its contextual taxonomies; null is every attached one
     */
    public function collections(): array
    {
        $configured = $this->selection('collections');
        $everyCollection = in_array('*', $configured, true);

        $collections = [];
        if ($everyCollection) {
            foreach ($this->sorted(Collection::handles()->all()) as $handle) {
                $collections[$handle] = null;
            }
        }

        foreach ($configured as $key => $options) {
            // a plain handle selects entries only, and never replaces a keyed handle
            if (! $everyCollection && is_int($key) && is_string($options) && ! array_key_exists($options, $collections)) {
                $collections[$options] = [];
            }

            if (! is_string($key) || $options === false) {
                continue;
            }

            // only a taxonomies key sets contextual taxonomies; without one, a keyed handle selects as a plain handle would
            if (is_array($options) && array_key_exists('taxonomies', $options)) {
                $collections[$key] = $this->handles($options['taxonomies']);
            } elseif (! array_key_exists($key, $collections)) {
                $collections[$key] = $everyCollection ? null : [];
            }
        }

        return array_diff_key($collections, array_flip($this->excluded($configured)));
    }

    /**
     * A '*' selects every taxonomy, a keyed handle selects that taxonomy with its options,
     * and a handle set to false is left out.
     *
     * @return list<string>
     */
    public function taxonomies(): array
    {
        $configured = $this->selection('taxonomies');
        $listed = $this->handles($configured);
        $taxonomies = $listed === null
            ? $this->sorted(Taxonomy::handles()->all())
            : array_values(array_unique([...$listed, ...$this->keyed($configured)]));

        return array_values(array_diff($taxonomies, $this->excluded($configured)));
    }

    /**
     * The changefreq and priority a collection's or taxonomy's URLs use when their fields have no valid value.
     * The source's own options win over the global defaults, key by key.
     *
     * @param  'collections'|'taxonomies'  $selection
     * @return array{changefreq: ?string, priority: ?float}
     */
    public function defaults(string $selection, string $handle): array
    {
        $global = config('sitemapamic.defaults');
        if ($global !== null && ! is_array($global)) {
            throw new InvalidArgumentException('defaults must be an array or null.');
        }

        $options = $this->selection($selection)[$handle] ?? null;
        $options = is_array($options) ? $options : [];
        $path = $selection.'.'.$handle;

        return [
            'changefreq' => $this->changefreq($options, $path) ?? $this->changefreq($global ?? [], 'defaults'),
            'priority' => $this->priority($options, $path) ?? $this->priority($global ?? [], 'defaults'),
        ];
    }

    /** Single mode merges every source into sitemap.xml, split only when it outgrows one file. */
    public function isSingleFile(): bool
    {
        $mode = config('sitemapamic.mode') ?? 'automatic';
        if ($mode !== 'automatic' && $mode !== 'single') {
            throw new InvalidArgumentException("mode must be 'automatic' or 'single'.");
        }

        return $mode === 'single';
    }

    public function ttl(): ?int
    {
        $ttl = config('sitemapamic.cache.ttl');

        // env() gives strings, so digits are read as seconds and an empty value as no expiry
        if ($ttl === '') {
            return null;
        }
        if (is_string($ttl) && ctype_digit($ttl)) {
            $ttl = (int) $ttl;
        }

        if ($ttl === null || (is_int($ttl) && $ttl > 0)) {
            return $ttl;
        }

        throw new InvalidArgumentException('cache.ttl must be null or a positive integer number of seconds.');
    }

    private function selection(string $key): array
    {
        $configured = config('sitemapamic.'.$key);
        if ($configured !== null && ! is_array($configured)) {
            throw new InvalidArgumentException($key.' must be an array or null.');
        }

        return $configured ?? [];
    }

    private function changefreq(array $options, string $path): ?string
    {
        $changefreq = $options['changefreq'] ?? null;
        if ($changefreq !== null && ChangeFrequency::fromValue($changefreq) === null) {
            throw new InvalidArgumentException($path.'.changefreq must be one of '.implode(', ', ChangeFrequency::values()).'.');
        }

        return $changefreq;
    }

    private function priority(array $options, string $path): ?float
    {
        $priority = $options['priority'] ?? null;
        if ($priority === null) {
            return null;
        }

        $isNumber = is_int($priority) || is_float($priority) || (is_string($priority) && is_numeric($priority));
        if (! $isNumber || ! is_finite((float) $priority) || $priority < 0 || $priority > 1) {
            throw new InvalidArgumentException($path.'.priority must be a number from 0 to 1.');
        }

        return (float) $priority;
    }

    /** @return list<string> the handles given options, other than false */
    private function keyed(array $configured): array
    {
        return array_keys(array_filter(
            $configured,
            fn (mixed $options, int|string $key): bool => is_string($key) && $options !== false,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return list<string> the handles set to false */
    private function excluded(array $configured): array
    {
        return array_keys(array_filter(
            $configured,
            fn (mixed $options, int|string $key): bool => is_string($key) && $options === false,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** @return list<string> */
    private function sorted(array $handles): array
    {
        sort($handles, SORT_STRING);

        return $handles;
    }

    /** @return list<string>|null null is every handle */
    private function handles(mixed $configured): ?array
    {
        $configured = is_array($configured) ? $configured : [];
        if (in_array('*', $configured, true)) {
            return null;
        }

        return collect($configured)->filter(fn ($handle): bool => is_string($handle))->unique()->values()->all();
    }
}
