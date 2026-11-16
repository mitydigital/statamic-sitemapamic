<?php

use Illuminate\Support\Facades\DB;
use MityDigital\Sitemapamic\Data\Source;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Support\Content\UrlIterator;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

it('stores content in the database and lists it in the sitemap', function (): void {
    $this->template('article');
    Collection::make('articles')->routes('/articles/{slug}')->template('article')->save();
    Entry::make()->id('example')->collection('articles')->slug('example')->data(['title' => 'Example', 'updated_at' => 1700000000])->published(true)->save();
    $site = Site::default()->handle();

    $records = iterator_to_array(app(UrlIterator::class)->forSource(new Source($site, collection: 'articles')));

    expect(DB::table('entries')->where('id', 'example')->value('uri'))->toBe('/articles/example')
        ->and(array_map(fn (SitemapamicUrl $record): string => $record->loc, $records))->toBe(['http://localhost/articles/example']);
});
