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

function definitionWithEditorFieldPolicy(): WorkflowDefinition
{
    $graph = [
        'start' => 'pending_review',
        'nodes' => [
            [
                'id' => 'pending_review',
                'type' => 'state',
                'field_policy' => [
                    ['field' => 'notes', 'visible' => true, 'readonly' => false, 'label' => 'Reviewer notes'],
                    ['field' => 'email', 'visible' => false],
                    ['field' => 'requester.department', 'visible' => true, 'readonly' => true, 'type' => 'text', 'label' => 'Department'],
                ],
            ],
        ],
        'edges' => [],
    ];

    $definition = WorkflowDefinition::create(['name' => 'editor-policy-test', 'slug' => 'editor-policy-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('applies the field-policy editor\'s visible/readonly/label shape, including a field absent from the base form', function () {
    $definition = definitionWithEditorFieldPolicy();
    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition);

    $fields = [
        ['name' => 'name'],
        ['name' => 'notes'],
        ['name' => 'email'],
    ];

    $result = $this->resolver->apply($fields, $instance);

    // "email" (visible: false) is dropped; "requester.department" doesn't
    // exist in the base form's $fields but is still added, built from the
    // policy entry itself.
    expect(collect($result)->pluck('name')->all())->toBe(['notes', 'requester.department', 'name']);

    $notes = collect($result)->firstWhere('name', 'notes');
    expect($notes['label'])->toBe('Reviewer notes');
    expect($notes['attributes'] ?? [])->not->toHaveKey('disabled');

    $department = collect($result)->firstWhere('name', 'requester.department');
    expect($department['label'])->toBe('Department');
    expect($department['type'])->toBe('text');
    expect($department['attributes']['disabled'])->toBe('disabled');
});

it('merges the field_definition JSON escape hatch onto the field, taking precedence over the simple columns', function () {
    $graph = [
        'start' => 'pending_review',
        'nodes' => [
            [
                'id' => 'pending_review',
                'type' => 'state',
                'field_policy' => [
                    [
                        'field' => 'notes',
                        'visible' => true,
                        'readonly' => false,
                        'label' => 'Reviewer notes',
                        'field_definition' => json_encode([
                            'label' => 'Overridden label',
                            'options' => ['a' => 'A', 'b' => 'B'],
                            'tab' => 'Review',
                        ]),
                    ],
                ],
            ],
        ],
        'edges' => [],
    ];

    $definition = WorkflowDefinition::create(['name' => 'field-definition-test', 'slug' => 'field-definition-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition->fresh());

    $result = $this->resolver->apply([['name' => 'notes']], $instance);
    $notes = collect($result)->firstWhere('name', 'notes');

    expect($notes['label'])->toBe('Overridden label');
    expect($notes['options'])->toBe(['a' => 'A', 'b' => 'B']);
    expect($notes['tab'])->toBe('Review');
});

function definitionWithNotesFieldPolicy(array $entryOverrides): WorkflowDefinition
{
    $graph = [
        'start' => 'pending_review',
        'nodes' => [
            [
                'id' => 'pending_review',
                'type' => 'state',
                'field_policy' => [
                    array_merge([
                        'field' => 'notes', 'visible' => true, 'readonly' => false, 'label' => 'Reviewer notes',
                    ], $entryOverrides),
                ],
            ],
        ],
        'edges' => [],
    ];

    $definition = WorkflowDefinition::create(['name' => 'custom-field-definition-test', 'slug' => 'custom-field-definition-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('merges the custom_field_definition PHP-literal escape hatch onto the field, taking precedence over the simple columns', function () {
    $definition = definitionWithNotesFieldPolicy([
        'custom_field_definition' => "[\n    'label' => 'Overridden label',\n    'options' => ['a' => 'A', 'b' => 'B'],\n    'tab' => 'Review',\n]",
    ]);
    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition);

    $result = $this->resolver->apply([['name' => 'notes']], $instance);
    $notes = collect($result)->firstWhere('name', 'notes');

    expect($notes['label'])->toBe('Overridden label');
    expect($notes['options'])->toBe(['a' => 'A', 'b' => 'B']);
    expect($notes['tab'])->toBe('Review');
});

it('never executes a custom_field_definition that is not a pure array literal, ignoring it instead', function (string $payload) {
    $definition = definitionWithNotesFieldPolicy(['custom_field_definition' => $payload]);
    $user = User::factory()->create();
    $instance = app(TransitionEngine::class)->start($user, $definition);

    $result = $this->resolver->apply([['name' => 'notes']], $instance);
    $notes = collect($result)->firstWhere('name', 'notes');

    // The simple-column label still applies; nothing from the rejected
    // payload (a function call, a variable reference, string
    // interpolation) ever reaches the field.
    expect($notes['label'])->toBe('Reviewer notes');
    expect($notes)->not->toHaveKey('pwned');
})->with([
    'function call' => "['pwned' => shell_exec('id')]",
    'variable reference' => "['pwned' => \$_SERVER]",
    'string interpolation' => '["pwned" => "$_SERVER[HTTP_HOST]"]',
    'static method call' => "['pwned' => \\Illuminate\\Support\\Str::random()]",
]);

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
