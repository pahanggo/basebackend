<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\TransitionEngine;
use Workflow\Support\WorkflowStatusPresenter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();

    // Resolved via the container (not `new`) so the config-registered
    // precondition/action types (see WorkflowServiceProvider) are actually
    // available — a manually-constructed registry starts empty.
    $this->presenter = app(WorkflowStatusPresenter::class);
    $this->engine = app(TransitionEngine::class);
});

/**
 * A tiny HasWorkflow-using class over the real `users` table — mirrors the
 * fixture WorkflowTransitionControllerTest uses, since User itself doesn't
 * declare the trait in this app.
 */
function workflowableUser(int $id)
{
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'unused';
        }
    };

    return $class::find($id);
}

function definitionForPresenter(array $edgeOverrides = []): WorkflowDefinition
{
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'name' => 'Draft', 'type' => 'state'],
            ['id' => 'approved', 'name' => 'Approved', 'type' => 'state'],
        ],
        'edges' => [
            array_merge([
                'id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual',
                'name' => 'Approve', 'surfaces' => ['record_button'],
            ], $edgeOverrides),
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'presenter-test', 'slug' => 'presenter-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('returns no tokens or transitions when the record has no active workflow instance', function () {
    $user = workflowableUser(User::factory()->create()->id);

    $status = $this->presenter->present($user, null);

    expect($status['instance'])->toBeNull();
    expect($status['tokens'])->toBe([]);
    expect($status['transitions'])->toBe([]);
});

it('resolves the active token to its node label from the graph', function () {
    $definition = definitionForPresenter();
    $user = workflowableUser(User::factory()->create()->id);
    $this->engine->start($user, $definition);

    $status = $this->presenter->present($user, null);

    expect($status['tokens'])->toBe([['node_id' => 'draft', 'label' => 'Draft']]);
});

it('only surfaces manual edges whose surfaces include the requested surface', function () {
    $definition = definitionForPresenter(['surfaces' => ['bulk_action']]);
    $user = workflowableUser(User::factory()->create()->id);
    $this->engine->start($user, $definition);

    expect($this->presenter->present($user, null, 'record_button')['transitions'])->toBe([]);
    expect($this->presenter->present($user, null, 'bulk_action')['transitions'])->toHaveCount(1);
});

it('excludes an edge the given actor is not allowed to trigger', function () {
    $definition = definitionForPresenter(['actor_rule' => ['roles' => ['hod'], 'match' => 'any']]);
    $user = workflowableUser(User::factory()->create()->id);
    $this->engine->start($user, $definition);

    $viewer = User::factory()->create();

    expect($this->presenter->present($user, $viewer)['transitions'])->toBe([]);
});

it('excludes an edge whose precondition does not pass', function () {
    $definition = definitionForPresenter([
        'preconditions' => ['type' => 'field_equals', 'field' => 'name', 'value' => 'nonexistent-name'],
    ]);
    $user = workflowableUser(User::factory()->create()->id);
    $this->engine->start($user, $definition);

    expect($this->presenter->present($user, null)['transitions'])->toBe([]);
});

it('includes an available edge with its button label, confirmation flag, and inputs', function () {
    $definition = definitionForPresenter([
        'button_label' => 'Approve it',
        'requires_confirmation' => true,
        'inputs' => [['name' => 'remarks', 'type' => 'textarea']],
    ]);
    $user = workflowableUser(User::factory()->create()->id);
    $this->engine->start($user, $definition);

    $transitions = $this->presenter->present($user, null)['transitions'];

    expect($transitions)->toBe([[
        'edge_id' => 'approve',
        'label' => 'Approve it',
        'requires_confirmation' => true,
        'inputs' => [['name' => 'remarks', 'type' => 'textarea']],
    ]]);
});
