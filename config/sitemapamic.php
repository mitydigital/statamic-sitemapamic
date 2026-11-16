<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | By default, with "*", routeable content will be automatically discovered, including Taxonomies
    | within configured Collections.
    |
    | You can keep "*" and then provide a Collection/Taxonomy's handle, and set it to false to
    | auto-discover but exclude the false collection:
    |   'collections' => [
    |       '*',
    |       'support' => false
    |   ],
    |
    | This would auto-discover all collections, but exclude support (even if it is routeable).
    |
    | With auto-discovery, Taxonomies for Collections will be included too. You can set explicit Taxonomies
    | to include with:
    |   'collections' => [
    |       'blog' => [
    |           'taxonomies' => [
    |               'tags'
    |           ]
    |       ]
    |   ],
    |
    | This would include only the "blog" Collection and only the "tags" Taxonomy for the Blog, even if there are
    | other Taxonomies in the site.
    |
    | You can do this for Taxonomies too:
    |   'taxonomies' => [
    |       '*',
    |       'internal' => false
    |   ],
    |
    | This discovers all Taxonomies, but excludes "internal".
    |
    */
    'collections' => ['*'],

    'taxonomies' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | The changefreq and priority to use when an Entry or Term has no valid value in its mapped field.
    | Leave either as null to omit it from the sitemap when no mapping is found.
    |
    | A Collection or Taxonomy can set its own too, which will be used instead of defaults:
    |   'collections' => [
    |       'pages' => [
    |           'changefreq' => 'yearly',
    |           'priority' => 0.5,
    |       ]
    |   ],
    |
    | Term pages within a Collection use their Taxonomy's defaults.
    |
    | changefreq can be always, hourly, daily, weekly, monthly, yearly or never.
    | priority can be a number from 0 to 1.
    |
    */
    'defaults' => [
        'changefreq' => null,
        'priority' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | "automatic" creates a sitemap.xml index, with separate files for each Collection and Taxonomy.
    |
    | "single" merges everything into a single sitemap.xml file, which may suit smaller sites. If it
    | outgrows one file (50,000 URLs or 50MB), it is split into numbered files with a sitemap.xml index.
    |
    */
    'mode' => 'automatic',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | For performance, your sitemap can be cached.
    |
    | By default, it is enabled, will live forever (until content is changed, or you clear it), and will be
    | stored on your "local" disk.
    |
    | All of these can be adjusted by env variables.
    |
    */
    'cache' => [
        'enabled' => env('SITEMAPAMIC_CACHE_ENABLED', true),

        'key' => 'sitemapamic',

        'ttl' => env('SITEMAPAMIC_CACHE_TTL'),

        'disk' => env('SITEMAPAMIC_CACHE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Field mappings
    |--------------------------------------------------------------------------
    |
    | Allows you to map your blueprint fields with what Sitemapamic is expecting for controlling
    | the change frequency, inclusion and priority.
    |
    | Can also be a handle for a computed property, although does slow down generation.
    |
    | The key is the purpose (i.e. don't change this) and the value is the field handle in your blueprints.
    |
    */
    'mappings' => [
        'include' => 'meta_include_in_xml_sitemap',

        'changefreq' => 'meta_change_frequency',

        'priority' => 'meta_priority',
    ],
];
