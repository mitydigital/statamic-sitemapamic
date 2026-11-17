<?php

use MityDigital\Sitemapamic\Enums\ChangeFrequency;
use MityDigital\Sitemapamic\Models\SitemapamicUrl;
use MityDigital\Sitemapamic\Tests\TestCase;

it('autoloads the URL value without booting the application', function (): void {
    $url = new SitemapamicUrl('https://example.com/page');

    expect($url->loc)->toBe('https://example.com/page')
        ->and($this)->not->toBeInstanceOf(TestCase::class);
});

it('stores a change frequency given as a case or a string', function (ChangeFrequency|string|null $changefreq, ?string $expected): void {
    expect((new SitemapamicUrl('https://example.com/page', changefreq: $changefreq))->changefreq)->toBe($expected);
})->with([
    'a case' => [ChangeFrequency::WEEKLY, 'weekly'],
    'a string' => ['daily', 'daily'],
    'none' => [null, null],
]);
