<?php

use MityDigital\Sitemapamic\Enums\ChangeFrequency;

it('lists the protocol values in order', function (): void {
    expect(ChangeFrequency::values())->toBe(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']);
});

it('reads a stored value', function (mixed $value, ?ChangeFrequency $expected): void {
    expect(ChangeFrequency::fromValue($value))->toBe($expected);
})->with([
    'a protocol value' => ['weekly', ChangeFrequency::Weekly],
    'the wrong case' => ['Weekly', null],
    'an unknown word' => ['sometimes', null],
    'null' => [null, null],
    'a number' => [1, null],
    'a list' => [['weekly'], null],
]);
