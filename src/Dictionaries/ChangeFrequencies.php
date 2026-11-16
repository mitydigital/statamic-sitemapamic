<?php

namespace MityDigital\Sitemapamic\Dictionaries;

use MityDigital\Sitemapamic\Enums\ChangeFrequency;
use Statamic\Dictionaries\BasicDictionary;

/** The sitemap changefreq values, for a dictionary field in a blueprint. */
class ChangeFrequencies extends BasicDictionary
{
    protected static $handle = 'sitemapamic_change_frequencies';

    protected static $title = 'Sitemap Change Frequencies';

    protected array $keywords = ['sitemap', 'changefreq', 'frequency'];

    /** @return list<array{value: string, label: string}> */
    protected function getItems(): array
    {
        return array_map(
            fn (ChangeFrequency $frequency): array => ['value' => $frequency->value, 'label' => $frequency->label()],
            ChangeFrequency::cases(),
        );
    }
}
