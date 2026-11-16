<?php

namespace MityDigital\StatamicScheduledCacheInvalidator\Events;

class ScheduledCacheInvalidated
{
    public function __construct(public array $collections) {}
}
