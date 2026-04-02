<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry->loc }}</loc>
        <lastmod>{{ $entry->lastmod }}</lastmod>
        @if ($entry->changefreq)<changefreq>{{ $entry->changefreq }}</changefreq>@endif

        @if ($entry->priority)<priority>{{ number_format($entry->priority, 1) }}</priority>@endif

        @foreach ($entry->alternates as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}"/>
        @endforeach
    </url>
@endforeach
</urlset>
