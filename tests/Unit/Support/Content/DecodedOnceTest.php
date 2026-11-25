<?php

use Illuminate\Database\Eloquent\Model;
use MityDigital\Sitemapamic\Support\Content\DecodedOnce;

function jsonModel(string $cast = 'json'): Model
{
    $model = new class extends Model {};
    $model->mergeCasts(['data' => $cast]);

    return $model->setRawAttributes(['data' => '{"title":"First"}'], true);
}

it('decodes a column once, and again after it changes', function (): void {
    $model = jsonModel();
    DecodedOnce::apply($model, 'data');

    expect($model->data)->toBe(['title' => 'First'])
        ->and($model->data)->toBe(['title' => 'First']);

    $model->data = ['title' => 'Second'];
    expect($model->data)->toBe(['title' => 'Second'])
        ->and($model->getAttributes()['data'])->toBe('{"title":"Second"}');
});

it('keeps null as null', function (): void {
    $model = jsonModel()->setRawAttributes(['data' => null]);
    DecodedOnce::apply($model, 'data');

    expect($model->data)->toBeNull();
});

it('leaves a column with another cast alone', function (string $cast): void {
    $model = jsonModel($cast);
    DecodedOnce::apply($model, 'data');

    expect($model->getCasts()['data'])->toBe($cast);
})->with(['array', 'collection']);
