<url>
    <loc>{{ $url->loc }}</loc>
@if ($url->lastmod !== null)
    <lastmod>{{ $url->lastmod }}</lastmod>
@endif
@if ($url->changefreq)
    <changefreq>{{ $url->changefreq }}</changefreq>
@endif
@if ($url->priority !== null)
    <priority>{{ $url->priority }}</priority>
@endif
@foreach ($url->alternates as $alternate)
    <xhtml:link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}" />
@endforeach
</url>
