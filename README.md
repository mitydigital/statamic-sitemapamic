# XML Sitemap generator for Statamic 3

![Statamic 3.0+](https://img.shields.io/badge/Statamic-3.0+-FF269E?style=for-the-badge&link=https://statamic.com)

Ultimately this has been a learning experiment to create an XML sitemap for my own personal site.

This will create and cache an XML sitemap for your site, and include:

- entries from your collections
- taxonomy pages for collections that use them

Unpublished and private entries (for date-based collections) are excluded.

## Installation

Install it via the composer command

```
composer require mitydigital/statamic-xml-sitemap
```

## Viewing

The sitemap is available at your site's base URL with "sitemap.xml".

## Configuration

By default, the "pages" and "blog" collections have defaults set.

If you want to explore your own configuration, you can publish the config file:

```
php artisan vendor:publish --provider="MityDigital\StatamicXmlSitemap\ServiceProvider" --tag=config
```

In there you can adjust the cache key, plus the defaults for each collection.

## Blueprint requirements

This is an opinionated point, but useful for my use case. For my own site, I have a simple "meta_" fieldset that
includes title, description plus the ability to override my OG image.

I also have three fields that play with this addon:

- **meta_include_in_xml_sitemap** a button group, default of "", and options of:
    - '': 'Use default'
    - 'false': Exclude
    - 'true': Include
- **meta_change_frequency**, a select item, max 1, default of "default", and options of:
    - 'default': 'Use default'
    - always: 'Always (stock market data, social media)'
    - hourly: 'Hourly (major news site, weather, forums)'
    - daily: 'Daily (blog index, message boards)'
    - weekly: 'Weekly (product pages, directories)'
    - monthly: 'Monthly (FAQs, occasionally updated articles)'
    - yearly: 'Yearly (contact, about)'
- **meta_priority**, float, min 0.0, max 1.0

These fields can alter the behaviour of the sitemap generator per entry or term. I then link this fieldset in my
collections.

As mentioned, this is my opinionated approach for this use case for a simple site. For client work, I think the Statamic
SEO Pro package is the defacto go-to.

## Clearing the cache

Your sitemap when rendered is cached forever.

To clear the cache, you can do one of three things:

- save an entry
- save a taxonomy term
- run a ``please`` command

Saving an entry or term will automatically clear the sitemap cache.

You can force the cache to clear by running:

```
php please sitemap-cache:clear
```

## Outstanding issue

Personally I want to use full caching for my site, and as such, future-posting entries does not function as expected as
they won't appear at the right times due to caching.

This also means that your sitemap would need re-generating too.

Given this has been an experiment for my personal Statamic site, and to keep it simple, I'll just edit (or publish)
content as it needs to appear, and let the caching do its thing.

## License

This addon is licensed under the MIT license.