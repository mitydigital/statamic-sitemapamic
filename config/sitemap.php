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

    'cache' => 'statamic-xml-sitemap',

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
    */

    'defaults' => [
        'blog' => [
            'include'   => true,
            'frequency' => 'weekly',
            'priority'  => '0.7'
        ],

        'pages' => [
            'include'   => true,
            'frequency' => 'yearly',
            'priority'  => '0.5'
        ]
    ]
];