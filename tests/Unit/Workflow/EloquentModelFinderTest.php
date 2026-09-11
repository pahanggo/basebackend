<?php

use Workflow\Support\EloquentModelFinder;

beforeEach(function () {
    $this->finder = new EloquentModelFinder;
});

it('finds concrete Eloquent models under app/Models, including nested namespaces', function () {
    $models = $this->finder->all();

    expect($models)->toContain(\App\Models\User::class);
    expect($models)->toContain(\App\Models\Auth\Role::class);
    expect($models)->toContain(\App\Models\KitchenSink\KitchenSink::class);
});

it('filters by a case-insensitive substring of the class name', function () {
    $results = $this->finder->search('kitchensinktag');

    expect($results)->toHaveCount(1);
    expect($results[0]['value'])->toBe(\App\Models\KitchenSink\KitchenSinkTag::class);
    expect($results[0]['label'])->toContain('KitchenSinkTag');
});

it('returns everything for an empty search term', function () {
    expect($this->finder->search(''))->not->toBeEmpty();
    expect($this->finder->search(null))->not->toBeEmpty();
});

it('respects the limit', function () {
    expect($this->finder->search('', 2))->toHaveCount(2);
});
