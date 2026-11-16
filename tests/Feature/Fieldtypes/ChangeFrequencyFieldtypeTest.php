<?php

use MityDigital\Sitemapamic\Dictionaries\ChangeFrequencies;
use MityDigital\Sitemapamic\Fieldtypes\ChangeFrequencyFieldtype;
use Statamic\Dictionaries\Item;
use Statamic\Fieldtypes\Dictionary;
use Statamic\Fields\FieldtypeRepository;
use Statamic\Fields\Field;

function changeFrequencyFieldtype(array $config = []): ChangeFrequencyFieldtype
{
    return (new Field('meta_change_frequency', ['type' => 'sitemapamic_change_frequency', ...$config]))->fieldtype();
}

it('registers the fieldtype under a prefixed handle', function (): void {
    expect(app(FieldtypeRepository::class)->find('sitemapamic_change_frequency'))->toBeInstanceOf(ChangeFrequencyFieldtype::class);
});

it('reuses the dictionary component with the change frequencies, one value at a time', function (array $config): void {
    $fieldtype = changeFrequencyFieldtype($config);
    $data = $fieldtype->extraRenderableFieldData();

    expect($fieldtype->component())->toBe('dictionary')
        ->and($fieldtype->dictionary())->toBeInstanceOf(ChangeFrequencies::class)
        ->and($data['multiple'])->toBeFalse()
        ->and(array_keys($data['options']))->toBe(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']);
})->with([
    'no config' => [[]],
    'config copied from a dictionary field' => [['dictionary' => 'countries', 'max_items' => 5]],
]);

it('offers only appearance settings, clearable by default', function (): void {
    $fieldtype = changeFrequencyFieldtype();
    $visible = $fieldtype->configFields()->all()->reject(fn (Field $field): bool => $field->visibility() === 'hidden');

    expect($visible->keys()->all())->toBe(['placeholder', 'clearable'])
        ->and($fieldtype->config('clearable'))->toBeTrue();
});

it('publishes a config the control panel can load its options from', function (array $config): void {
    $published = (new Field('meta_change_frequency', ['type' => 'sitemapamic_change_frequency', ...$config]))->toPublishArray();

    // the control panel's options endpoint rebuilds a core dictionary field from the published config
    $endpointFieldtype = (new Dictionary)->setField(new Field('relationship', $published));

    expect($published['dictionary'])->toBe('sitemapamic_change_frequencies')
        ->and($published['max_items'])->toBe(1)
        ->and($endpointFieldtype->dictionary())->toBeInstanceOf(ChangeFrequencies::class);
})->with([
    'no config' => [[]],
    'config copied from a dictionary field' => [['dictionary' => 'countries', 'max_items' => 5]],
]);

it('augments a stored string to a single item', function (?string $value, ?string $expected): void {
    $item = changeFrequencyFieldtype()->augment($value);

    expect($item)->toBeInstanceOf(Item::class)
        ->and($item->value())->toBe($expected);
})->with(['a value' => ['weekly', 'weekly'], 'empty' => [null, null], 'unknown' => ['sometimes', null]]);
