# Sitemapamic

<!-- statamic:hide -->

![Statamic 3.3+](https://img.shields.io/badge/Statamic-3.3+-FF269E?style=for-the-badge&link=https://statamic.com)
[![Sitemapamic on Packagist](https://img.shields.io/packagist/v/mitydigital/sitemapamic?style=for-the-badge)](https://packagist.org/packages/mitydigital/sitemapamic/stats)

---

<!-- /statamic:hide -->

> Sitemapamic is a XML sitemap generator for Statamic 3

This addon will create and cache a XML sitemap for your site, and include:

- entries from your collections
- taxonomy pages for collections that use them

Unpublished and private entries (for date-based collections) are excluded.

## Installation

Install it via the composer command

```
composer require mitydigital/sitemapamic
```

## Viewing

The sitemap is available at your site's base URL with "sitemap.xml".

## Configuration

By default, the "pages" and "blog" collections have defaults set.

If you want to explore your own configuration, you can publish the config file:

```
php artisan vendor:publish --tag=sitemapamic-config
```

In there you can adjust the cache key, plus the defaults for each collection.

## Blueprint requirements

This is an opinionated point, but can be useful. We have used a simple "meta_" fieldset that includes title, description
plus the ability to override the OG image.

This addon also looks for the following three fields:

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

These fields can alter the behaviour of the sitemap generator per entry or term. This fieldset is used for your Entries
so that you can override and adjust these properties on a case-by-case basis.

This is an opinionated approach for a simple site. If you need greater control of SEO for your site, you may be better
suited to an addon like Statamic's [SEO Pro](https://statamic.com/addons/statamic/seo-pro) (we use this in our larger
sites).

## Clearing the cache

Your sitemap is cached forever. Well, until you clear it that is.

To clear the cache, you can do one of three things:

- save an entry
- save a taxonomy term
- run a ``please`` command

Saving an entry or term will automatically clear the sitemap cache.

You can force the cache to clear by running:

```
php please sitemapamic:clear
```

## Publishing the sitemap view

For most cases, you can use the default XML layout. If you need to be making changes, you can publish these:

```
php artisan vendor:publish --tag=sitemapamic-views
```

## Upgrade Notes

When upgrading to v2.0+, if you've published the view, manually check to see if anything needs tweaking.

If you're using the command in your deployment script or as a daily job, please update the command:
```bash
# Before 
php please sitemap-cache:clear

# After
php please sitemapamic:clear
```

You may also want to update your `composer.json` file to use the new package name:

```bash
# Before
"mitydigital/statamic-xml-sitemap": "^1.0",

# After
"mitydigital/sitemapamic": "^2.0",
```

## Static Caching gotcha

If you are using full static caching, future-posting entries does not work: when the sitemap is cached, it is cached
until a change is made to an entry, taxonomy or is manually flushed.

In other words, if you create a post that won't appear until 'tomorrow', and the XML sitemap is generated, when tomorrow
comes around and your new post becomes publicly visible, the XML sitemap won't be updated until the cache is
invalidated.

You could achieve this by:

- editing content
- running a daily command that clears the sitemap cache

## Support

We love to share work like this, and help the community. However it does take time, effort and work.

The best thing you can do is [log an issue](../../issues).

Please try to be detailed when logging an issue, including a clear description of the problem, steps to reproduce the
issue, and any steps you may have tried or taken to overcome the issue too. This is an awesome first step to helping us
help you. So be awesome - it'll feel fantastic.

## Credits

- [Marty Friedel](https://github.com/martyf)

## License

This addon is licensed under the MIT license.