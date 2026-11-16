<?php

use MityDigital\Sitemapamic\Tests\EloquentTestCase;
use MityDigital\Sitemapamic\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(EloquentTestCase::class)->in('Eloquent');
