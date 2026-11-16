<?php

namespace MityDigital\Sitemapamic\Fieldtypes;

use MityDigital\Sitemapamic\Dictionaries\ChangeFrequencies;
use Statamic\Fieldtypes\Dictionary;

/**
 * A dictionary field fixed to the sitemap change frequencies, holding one value.
 * It stores a plain string, so the changefreq mapping can read it directly.
 */
class ChangeFrequencyFieldtype extends Dictionary
{
    protected static $handle = 'sitemapamic_change_frequency';

    protected static $title = 'Sitemap Change Frequency';

    protected $component = 'dictionary';

    protected $icon = 'fieldtype-dictionary';

    protected $categories = ['controls'];

    protected $selectableInForms = false;

    protected function configFieldItems(): array
    {
        return [
            [
                'display' => __('Appearance'),
                'fields' => [
                    'placeholder' => [
                        'display' => __('Placeholder'),
                        'instructions' => __('statamic::fieldtypes.select.config.placeholder'),
                        'type' => 'text',
                        'default' => '',
                    ],
                    'clearable' => [
                        'display' => __('Clearable'),
                        'instructions' => __('statamic::fieldtypes.select.config.clearable'),
                        'type' => 'toggle',
                        // clearing a value falls back to the configured defaults
                        'default' => true,
                    ],
                    // the control panel loads options with the published config, so these must be published too
                    'dictionary' => ['type' => 'hidden', 'visibility' => 'hidden'],
                    'max_items' => ['type' => 'hidden', 'visibility' => 'hidden'],
                ],
            ],
        ];
    }

    /** The dictionary and a single value are fixed, whatever the field's own config says. */
    public function config(?string $key = null, $fallback = null)
    {
        $fixed = ['dictionary' => ChangeFrequencies::handle(), 'max_items' => 1];

        if (is_string($key) && $key !== '') {
            return array_key_exists($key, $fixed) ? $fixed[$key] : parent::config($key, $fallback);
        }

        return [...parent::config(), ...$fixed];
    }

    public function keywords(): array
    {
        return ['sitemap', 'changefreq', 'frequency', 'select'];
    }
}
