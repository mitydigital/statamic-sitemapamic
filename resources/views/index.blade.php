<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($submaps as $submap)
    <sitemap>
        <loc>{{ ("{$domain}/sitemap_{$submap}.xml") }}</loc>
    </sitemap>
@endforeach
</sitemapindex>