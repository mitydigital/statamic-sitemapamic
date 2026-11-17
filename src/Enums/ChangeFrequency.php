<?php

namespace MityDigital\Sitemapamic\Enums;

/** The changefreq values the sitemap protocol allows. */
enum ChangeFrequency: string
{
    case ALWAYS = 'always';
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
    case NEVER = 'never';

    /** The case for a stored value, or null for anything the protocol doesn't allow. */
    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __(ucfirst($this->value));
    }
}
