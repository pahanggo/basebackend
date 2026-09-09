<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\FieldPolicyResolver;
use Workflow\Support\TransitionEngine;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->resolver = new FieldPolicyResolver;
});

function definitionWithFieldPolicy(): WorkflowDefinition
{
    $graph = [
        'start' => 'pending_review',
        'nodes' => [
            [
                'id' => 'pending_review',
                'type' => 'state',
                'field_policy' => [
                    ['field' => 'notes', 'mode' => 'edit'],
                    ['field' => 'email', 'mode' => 'hidden'],
                ],
            ],
        ],
        'edges' => [],
    ];

    $definition = WorkflowDefinition::create(['name' => 'policy-test', 'slug' => 'policy-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('returns fields untouched when there is no instance', function () {
    $fields = [['name' => 'name'], ['name' => 'email']];

    expect($this->resolver->apply($fields, null))->toBe($fields);
});

it('reorders fields to match the policy, defaults unlisted fields to readonly, and hides declared-hidden fields', function () {
    $definition = definitionWithFieldPolicy();
    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition);

    $fields = [
        ['name' => 'name'],
        ['name' => 'notes'],
        ['name' => 'email'],
    ];

    $result = $this->resolver->apply($fields, $instance);

    // notes (edit) first per policy order, then "name" (unlisted -> readonly
    // default) appended after; "email" (hidden) is dropped entirely.
    expect(collect($result)->pluck('name')->all())->toBe(['notes', 'name']);

    $notes = collect($result)->firstWhere('name', 'notes');
    expect($notes['attributes'] ?? [])->not->toHaveKey('disabled');

    $name = collect($result)->firstWhere('name', 'name');
    expect($name['attributes']['disabled'] ?? null)->toBe('disabled');
});

it('leaves fields untouched when the node declares no field policy', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state']],
        'edges' => [],
    ];
    $definition = WorkflowDefinition::create(['name' => 'no-policy', 'slug' => 'no-policy-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition->fresh());

    $fields = [['name' => 'name'], ['name' => 'email']];

    expect($this->resolver->apply($fields, $instance))->toBe($fields);
});
