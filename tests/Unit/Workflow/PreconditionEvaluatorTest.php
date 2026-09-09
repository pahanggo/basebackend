<?php

use App\Models\User;
use Workflow\Preconditions\FieldCompare;
use Workflow\Preconditions\FieldEquals;
use Workflow\Preconditions\FieldIn;
use Workflow\Registries\WorkflowPreconditionRegistry;
use Workflow\Support\PreconditionEvaluator;

beforeEach(function () {
    $this->registry = new WorkflowPreconditionRegistry;
    $this->registry->register('field_equals', FieldEquals::class);
    $this->registry->register('field_in', FieldIn::class);
    $this->registry->register('field_compare', FieldCompare::class);
    $this->evaluator = new PreconditionEvaluator($this->registry);
    $this->model = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
});

it('passes an empty or missing tree', function () {
    expect($this->evaluator->passes(null, $this->model))->toBeTrue();
    expect($this->evaluator->passes([], $this->model))->toBeTrue();
});

it('evaluates a single leaf', function () {
    expect($this->evaluator->passes(['type' => 'field_equals', 'field' => 'name', 'value' => 'Ada'], $this->model))->toBeTrue();
    expect($this->evaluator->passes(['type' => 'field_equals', 'field' => 'name', 'value' => 'Grace'], $this->model))->toBeFalse();
});

it('evaluates a nested and group requiring every child', function () {
    $tree = [
        'op' => 'and',
        'children' => [
            ['type' => 'field_equals', 'field' => 'name', 'value' => 'Ada'],
            ['type' => 'field_in', 'field' => 'email', 'values' => ['ada@example.com', 'other@example.com']],
        ],
    ];

    expect($this->evaluator->passes($tree, $this->model))->toBeTrue();

    $tree['children'][1]['values'] = ['nope@example.com'];
    expect($this->evaluator->passes($tree, $this->model))->toBeFalse();
});

it('evaluates an or group requiring at least one child', function () {
    $tree = [
        'op' => 'or',
        'children' => [
            ['type' => 'field_equals', 'field' => 'name', 'value' => 'Grace'],
            ['type' => 'field_equals', 'field' => 'name', 'value' => 'Ada'],
        ],
    ];

    expect($this->evaluator->passes($tree, $this->model))->toBeTrue();

    $tree['children'][1]['value'] = 'Nope';
    expect($this->evaluator->passes($tree, $this->model))->toBeFalse();
});

it('evaluates a nested or-within-and tree', function () {
    $model = new User(['name' => 'Ada', 'email' => 'ada@example.com']);
    $model->amount = 500;

    $tree = [
        'op' => 'and',
        'children' => [
            ['type' => 'field_equals', 'field' => 'name', 'value' => 'Ada'],
            [
                'op' => 'or',
                'children' => [
                    ['type' => 'field_compare', 'field' => 'amount', 'operator' => 'gt', 'value' => 1000],
                    ['type' => 'field_compare', 'field' => 'amount', 'operator' => 'lte', 'value' => 1000],
                ],
            ],
        ],
    ];

    expect($this->evaluator->passes($tree, $model))->toBeTrue();
});

it('compares fields with every operator', function (string $operator, int $left, int $right, bool $expected) {
    $model = new User;
    $model->amount = $left;

    $result = $this->evaluator->passes(['type' => 'field_compare', 'field' => 'amount', 'operator' => $operator, 'value' => $right], $model);

    expect($result)->toBe($expected);
})->with([
    ['eq', 5, 5, true],
    ['eq', 5, 6, false],
    ['ne', 5, 6, true],
    ['lt', 4, 5, true],
    ['lte', 5, 5, true],
    ['gt', 6, 5, true],
    ['gte', 5, 5, true],
]);
