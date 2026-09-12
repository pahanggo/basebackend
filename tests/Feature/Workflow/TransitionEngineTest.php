<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Events\TransitionedIn;
use Workflow\Events\TransitionedOut;
use Workflow\Events\TransitioningIn;
use Workflow\Events\TransitioningOut;
use Workflow\Models\WorkflowDefinition;
use Workflow\Registries\WorkflowActionRegistry;
use Workflow\Registries\WorkflowPreconditionRegistry;
use Workflow\Support\ActorRuleResolver;
use Workflow\Support\PreconditionEvaluator;
use Workflow\Support\TransitionEngine;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();

    $this->engine = new TransitionEngine(
        new PreconditionEvaluator(new WorkflowPreconditionRegistry),
        new ActorRuleResolver,
        new WorkflowActionRegistry,
    );
});

function defineLinearWorkflow(string $slug): WorkflowDefinition
{
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'review', 'type' => 'state'],
            ['id' => 'approved', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'submit', 'from' => 'draft', 'to' => 'review', 'trigger' => 'manual'],
            ['id' => 'approve', 'from' => 'review', 'to' => 'approved', 'trigger' => 'manual'],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => $slug, 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('starts an instance on the graph start node', function () {
    $definition = defineLinearWorkflow('linear-start');
    $user = User::factory()->create();

    $instance = $this->engine->start($user, $definition);

    expect($instance->status)->toBe('active');
    expect($instance->activeTokens()->first()->node_id)->toBe('draft');
});

it('advances a token linearly and records history', function () {
    $definition = defineLinearWorkflow('linear-advance');
    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition);

    $token = $instance->activeTokens()->first();
    $history = $this->engine->transition($token, 'submit', [], null, true);

    expect($history)->not->toBeNull();
    expect($history->to_node_id)->toBe('review');

    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('review');
    expect($instance->history()->count())->toBe(1);
});

it('refuses a transition an edge does not offer from the current node', function () {
    $definition = defineLinearWorkflow('linear-wrong-edge');
    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition);
    $token = $instance->activeTokens()->first();

    $this->engine->transition($token, 'approve', [], null, true);
})->throws(RuntimeException::class);

it('denies a transition when actor_rule is not satisfied', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'review', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'submit', 'from' => 'draft', 'to' => 'review', 'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod']]],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'gated', 'slug' => 'gated', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition->fresh());
    $token = $instance->activeTokens()->first();

    $result = $this->engine->transition($token, 'submit', [], null, false);

    expect($result)->toBeNull();
    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('draft');
});

it('forks into parallel branches and only joins once every branch arrives', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'fork1', 'type' => 'fork'],
            ['id' => 'branch_a', 'type' => 'state'],
            ['id' => 'branch_b', 'type' => 'state'],
            ['id' => 'join1', 'type' => 'join'],
            ['id' => 'final', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'fork1', 'trigger' => 'manual'],
            ['id' => 'fork_a', 'from' => 'fork1', 'to' => 'branch_a', 'trigger' => 'automatic'],
            ['id' => 'fork_b', 'from' => 'fork1', 'to' => 'branch_b', 'trigger' => 'automatic'],
            ['id' => 'a_done', 'from' => 'branch_a', 'to' => 'join1', 'trigger' => 'manual'],
            ['id' => 'b_done', 'from' => 'branch_b', 'to' => 'join1', 'trigger' => 'manual'],
            ['id' => 'onward', 'from' => 'join1', 'to' => 'final', 'trigger' => 'automatic'],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'forkjoin', 'slug' => 'forkjoin', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition->fresh());

    $this->engine->transition($instance->activeTokens()->first(), 'go', [], null, true);
    $instance->refresh();
    expect($instance->activeTokens()->pluck('node_id')->sort()->values()->all())->toBe(['branch_a', 'branch_b']);

    $branchA = $instance->activeTokens()->where('node_id', 'branch_a')->first();
    $this->engine->transition($branchA, 'a_done', [], null, true);
    $instance->refresh();
    expect($instance->activeTokens()->pluck('node_id')->sort()->values()->all())->toBe(['branch_b', 'join1']);

    $branchB = $instance->activeTokens()->where('node_id', 'branch_b')->first();
    $this->engine->transition($branchB, 'b_done', [], null, true);
    $instance->refresh();
    expect($instance->activeTokens()->pluck('node_id')->all())->toBe(['final']);
});

it('fires an automatic edge whose precondition passes without a human click', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'review', 'type' => 'state'],
            ['id' => 'auto_approved', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'submit', 'from' => 'draft', 'to' => 'review', 'trigger' => 'manual'],
            [
                'id' => 'auto_approve',
                'from' => 'review',
                'to' => 'auto_approved',
                'trigger' => 'automatic',
                'preconditions' => ['type' => 'field_equals', 'field' => 'name', 'value' => 'Ada'],
            ],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'auto', 'slug' => 'auto', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $registry = new WorkflowPreconditionRegistry;
    $registry->register('field_equals', \Workflow\Preconditions\FieldEquals::class);
    $engine = new TransitionEngine(new PreconditionEvaluator($registry), new ActorRuleResolver, new WorkflowActionRegistry);

    $user = User::factory()->create(['name' => 'Ada']);
    $instance = $engine->start($user, $definition->fresh());

    $engine->transition($instance->activeTokens()->first(), 'submit', [], null, true);

    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('auto_approved');
});

it('dispatches directional in/out events around a transition', function () {
    Event::fake([TransitioningOut::class, TransitionedOut::class, TransitioningIn::class, TransitionedIn::class]);

    $definition = defineLinearWorkflow('linear-events');
    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition);

    Event::assertDispatched(TransitioningIn::class);
    Event::assertDispatched(TransitionedIn::class);

    $this->engine->transition($instance->activeTokens()->first(), 'submit', [], null, true);

    Event::assertDispatched(TransitioningOut::class);
    Event::assertDispatched(TransitionedOut::class);
    Event::assertDispatched(TransitioningIn::class, 2);
    Event::assertDispatched(TransitionedIn::class, 2);
});

it('clears pending-actor rows once a token is consumed', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'review', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'submit', 'from' => 'draft', 'to' => 'review', 'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod']]],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'pending-actors', 'slug' => 'pending-actors', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition->fresh());
    $token = $instance->activeTokens()->first();

    expect(\Workflow\Models\WorkflowInstancePendingActor::where('workflow_instance_token_id', $token->id)->count())->toBe(1);

    $this->engine->transition($token, 'submit', [], null, true);

    expect(\Workflow\Models\WorkflowInstancePendingActor::where('workflow_instance_token_id', $token->id)->count())->toBe(0);
});

it('lets a TransitioningOut listener cancel the transition', function () {
    Event::listen(TransitioningOut::class, fn (TransitioningOut $event) => $event->cancel());

    $definition = defineLinearWorkflow('linear-cancel');
    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition);

    $result = $this->engine->transition($instance->activeTokens()->first(), 'submit', [], null, true);

    expect($result)->toBeNull();
    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('draft');
});

it('blocks a transition when a required input is missing', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'rejected', 'type' => 'state']],
        'edges' => [[
            'id' => 'reject', 'from' => 'draft', 'to' => 'rejected', 'trigger' => 'manual',
            'inputs' => [['name' => 'reason', 'type' => 'textarea', 'required' => true]],
        ]],
    ];
    $definition = WorkflowDefinition::create(['name' => 'required-input', 'slug' => 'required-input', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition->fresh());
    $token = $instance->activeTokens()->first();

    expect($this->engine->transition($token, 'reject', [], null, true))->toBeNull();

    $token->refresh();
    expect($token->status)->toBe('active');

    $history = $this->engine->transition($token, 'reject', ['reason' => 'Over budget'], null, true);
    expect($history)->not->toBeNull();
});

it('maps a captured input onto the workflowable model\'s own column via store_as', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'reviewed', 'type' => 'state']],
        'edges' => [[
            'id' => 'review', 'from' => 'draft', 'to' => 'reviewed', 'trigger' => 'manual',
            'inputs' => [['name' => 'remarks', 'type' => 'textarea', 'store_as' => 'name']],
        ]],
    ];
    $definition = WorkflowDefinition::create(['name' => 'store-as', 'slug' => 'store-as', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create(['name' => 'Original Name']);
    $instance = $this->engine->start($user, $definition->fresh());
    $token = $instance->activeTokens()->first();

    $history = $this->engine->transition($token, 'review', ['remarks' => 'Looks good'], null, true);

    expect($history->inputs)->toBe(['remarks' => 'Looks good']);
    expect($user->fresh()->name)->toBe('Looks good');
});

it('completes the transition even when an action throws, instead of corrupting the instance', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [[
            'id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual',
            'actions' => [['type' => 'call_webhook', 'url' => 'http://this-host-does-not-resolve.invalid']],
        ]],
    ];
    $definition = WorkflowDefinition::create(['name' => 'failing-action', 'slug' => 'failing-action', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $user = User::factory()->create();
    $instance = $this->engine->start($user, $definition->fresh());
    $token = $instance->activeTokens()->first();

    $history = $this->engine->transition($token, 'approve', [], null, true);

    expect($history)->not->toBeNull();
    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('approved');
});
