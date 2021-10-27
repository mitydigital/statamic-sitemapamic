<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry->loc }}</loc>
        <lastmod>{{ $entry->lastmod }}</lastmod>
        @if ($entry->changefreq)<changefreq>{{ $entry->changefreq }}</changefreq>@endif

        @if ($entry->priority)<priority>{{ number_format($entry->priority, 1) }}</priority>@endif

    </url>
@endforeach
</urlset>