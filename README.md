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

---

## Viewing

The sitemap is available at your site's base URL with "sitemap.xml".

---

## Configuration

By default, the "pages" and "blog" collections have defaults set.

If you want to explore your own configuration, you can publish the config file:

```
php artisan vendor:publish --tag=sitemapamic-config
```

In there you can adjust the cache key, plus the defaults for each collection and configuration for the globals. You'll 
also see examples for both defaults and globals, just to help you out.

### Defaults

The defaults is an array with a key being the handle of a Collection, and a number of properties.

#### include
When `true` will automatically include entries from this collection. Set to `false` to not include them by default.

This can be overridden per entry using the `meta_include_in_xml_sitemap` field (see 
[Blueprint requirements](#blueprint-requirements)).

#### frequency

The default value for the `<changefreq>` tag in your sitemap for each entry.

Leave blank (and not have a `meta_change_frequency` field in your blueprint) will exclude this tag from your sitemap 
for that Collection's entries.

This can be overridden per entry using the `meta_change_frequency` field (see
[Blueprint requirements](#blueprint-requirements)).

#### priority

The default value for the `<priority>` tag in each entry's `<url>` in the sitemap XML.

Leave blank (and not have a `meta_priority` field in your blueprint) will exclude this tag from your sitemap
for that Collection's entries.

This can be overridden per entry using the `meta_priority` field (see
[Blueprint requirements](#blueprint-requirements)).

#### includeTaxonomies

When set to `true`, taxonomy links will be created for the collection, if your entries use them.

Taxonomy terms can also use a `meta_include_in_xml_sitemap` field in their blueprint to optionally force-exclude a term
from your sitemap. See [Blueprint requirements](#blueprint-requirements) for field details.

### Globals

Globals are not about Collections, but for additional URLs that come from Statamic. You can define settings for your
global Taxonomies here.

The configuration settings are the same as for [Defaults](#defaults) - so you can override the same three fields in your
Term blueprint if you need.

This example would set the `<changefreq>` and `<priority>` for the Tags Taxonomy, but neither for the Categories Taxonomy.

```php
'globals'  => [
    'taxonomies' => [
        'tags' => [
            'frequency' => 'yearly',
            'priority'  => '0.5',
        ],
        
        'categories' => []
    ]
]
```

### Dynamic Routes

If your site has routes that are not part of Statamic - such as your own custom Laravel routes - you can add these to 
you sitemap too.

In your `AppServiceProvider` (or you can create your own SitemapamicServiceProvider if you like too - especially if you 
use named routes, keep reading) you can add dynamic routes to Sitemapamic as a closure that returns an array
of `SitemapamicUrl` objects:

```php
Sitemapamic::addDynamicRoutes(function() {
    return [
        new SitemapamicUrl(
            'https://my-awesome-url/dynamic-route',
            Carbon::now()->toW3cString()
        ),
        new SitemapamicUrl(
            'https://my-awesome-url/a-different-dynamic-route',
            Carbon::now()->toW3cString()
        )
    ];
});
```

Each `SitemapamicUrl` expects:
- **loc**, required, a string with a full URL to include
- **lastmod**, required, a string in the date format you want (we use Carbon's `toW3cString` internally)
- **changefreq**, optional, a value for your the `<changefreq>` element
- **priority**, optional, a value between 0.0 and 1.0 for the `<priority>` element

Your **loc** can be whatever you need it to be for your app - including building dynamic URLs based on your app's own 
data. How many you add and how you build them is totally up to you.

#### Using named routes in a provider

It would be our recommendation to use Named Routes where you can - so that if you change your route, your sitemap can
pick it automatically. It also means you don't have to be hardcoding full URLs in your app.

To do this, you need a little bit of extra work. Firstly, create your own Service Provider, and make sure it is in your
app's config *after* the `RouteServiceProvider`.

Within your Service Provider's boot method, you can add Dynamic Routes to Sitemapamic after the app has booted:

```php
$this->app->booted(function () {
    Sitemapamic::addDynamicRoutes(function () {
        return [
            new SitemapamicUrl(
                route('dynamic-route'),
                Carbon::now()->toW3cString()
            )
        ];
    });
});
```

---

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
suited to an addon like Statamic's [SEO Pro](https://statamic.com/addons/statamic/seo-pro).

---

## Clearing the cache

Your sitemap is cached forever. Well, until you clear it that is.

To clear the cache, you can do one of three things:

- save (or delete) an Entry
- save (or delete) a Taxonomy or Term
- save (or delete) a Collection
- run a ``please`` command

Saving an Entry, Collection, Taxonomy or Term will automatically clear the sitemap cache.

You can force the cache to clear by running:

```
php please sitemapamic:clear
```

This could be a good command to have as part of your deployment script.

---

## Publishing the sitemap view

For most cases, you can use the default XML layout. If you need to be making changes, you can publish these:

```
php artisan vendor:publish --tag=sitemapamic-views
```

---

## Upgrade Notes

### v2.0 to v2.1

This most likely won't need your attention, but the `SitemapUrl` class has been renamed to `SitemapamicUrl`, and the 
function arguments are now named, not an anonymous array.

v2.1 also introduces the ability to:
- include global Taxonomy Terms
- include dynamic routes

To take advantage of the global Taxonomy Terms, you'll need to add the `globals` property to your config file. Take a 
look at the package's [default config file](./config/sitemapamic.php) for an example.

Refer to the documentation above for more details about implementing both features.

### v1.0 to v2.0

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

---

## Static Caching gotcha

If you are using full static caching, future-posting entries does not work: when the sitemap is cached, it is cached
until a change is made to an entry, taxonomy or is manually flushed.

In other words, if you create a post that won't appear until 'tomorrow', and the XML sitemap is generated, when tomorrow
comes around and your new post becomes publicly visible, the XML sitemap won't be updated until the cache is
invalidated.

You could achieve this by:

- editing content
- running a daily command that clears the sitemap cache

---

## Support

We love to share work like this, and help the community. However it does take time, effort and work.

The best thing you can do is [log an issue](../../issues).

Please try to be detailed when logging an issue, including a clear description of the problem, steps to reproduce the
issue, and any steps you may have tried or taken to overcome the issue too. This is an awesome first step to helping us
help you. So be awesome - it'll feel fantastic.

---

## Credits

- [Marty Friedel](https://github.com/martyf)

## License

This addon is licensed under the MIT license.