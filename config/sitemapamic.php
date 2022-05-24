<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Key
    |--------------------------------------------------------------------------
    |
    | The key used to store the output. Will be cached forever until EventSaved or TermSaved is fired.
    |
    */

    'cache' => 'sitemapamic',


    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Sets defaults for different collections.
    |
    | The key is the collection handle, and the array includes default configurations.
    |   Set "include" to true to either include or exclude without explicitly setting per article
    |   Frequency and Priority are standard for an XML sitemap
    |
    | 'includeTaxonomies' enables (or disables) whether taxonomy URLs will be generated, if used,
    | for the collection. Only applies to Collections that actually use Taxonomies.
    |
    */

    'defaults' => [
        /*'blog' => [
            'include'   => true,
            'frequency' => 'weekly',
            'priority'  => '0.7'
        ],*/

        'pages' => [
            'include'           => true,
            'frequency'         => 'yearly',
            'priority'          => '0.5',
            'includeTaxonomies' => true,
        ]
    ],


    /*
    |--------------------------------------------------------------------------
    | Globals
    |--------------------------------------------------------------------------
    |
    | Sets global behaviour for items like taxonomies. Currently that's all that is supported.
    |
    | The 'globals.taxonomies' key expects an array of Taxonomy handles, each with an optional
    | priority and frequency, just like the Defaults section. This means your Taxonomy blueprint
    | can also take advantage of Term-specific 'meta_change_frequency' and 'meta_priority' fields,
    | or fall back to these defaults when not set (or present).
    |
    | If you don't want the Taxonomy included in the sitemap, simply exclude it from the array.
    |
    */
    'globals'  => [
        'taxonomies' => [
            /*'tags' => [
                'frequency' => 'yearly',
                'priority'  => '0.5',
            ],

            'categories' => []*/
        ]
    ]
];