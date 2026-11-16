<?php

use MityDigital\Sitemapamic\Dictionaries\ChangeFrequencies;
use Statamic\Facades\Dictionary;

it('registers the change frequencies under a prefixed handle', function (): void {
    expect(Dictionary::find('sitemapamic_change_frequencies'))->toBeInstanceOf(ChangeFrequencies::class);
});

it('offers every change frequency with a label', function (): void {
    expect(Dictionary::find('sitemapamic_change_frequencies')->options())->toBe([
        'always' => 'Always',
        'hourly' => 'Hourly',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'never' => 'Never',
    ]);
});

it('finds an item by its value only', function (): void {
    $dictionary = Dictionary::find('sitemapamic_change_frequencies');

    expect($dictionary->get('weekly')->label())->toBe('Weekly')
        ->and($dictionary->get('sometimes'))->toBeNull();
});
