<?php

namespace MityDigital\Sitemapamic\Tests\Fixtures;

use Statamic\Eloquent\Entries\UuidEntryModel;

/** A model that casts its content its own way, which the addon must leave alone. */
class ArrayCastEntryModel extends UuidEntryModel
{
    protected $casts = [
        'date' => 'datetime',
        'data' => 'array',
        'published' => 'boolean',
    ];
}
