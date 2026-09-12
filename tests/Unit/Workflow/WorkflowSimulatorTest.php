<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinitionVersion;
use Workflow\Support\WorkflowSimulator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->simulator = app(WorkflowSimulator::class);
});

/**
 * An unsaved WorkflowDefinitionVersion — the whole point of the simulator is
 * to work against a designer's in-editor graph before it's ever published or
 * persisted, so none of these tests touch the `workflow` connection at all.
 */
function unsavedVersion(array $graph): WorkflowDefinitionVersion
{
    return new WorkflowDefinitionVersion(['graph' => $graph]);
}

it('describes a node availability without firing anything', function () {
    $version = unsavedVersion([
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual', 'name' => 'Approve']],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->describe($version, $user, null, 'draft');

    expect($result['node']['id'])->toBe('draft');
    expect($result['edges'])->toBe([[
        'edge_id' => 'approve', 'label' => 'Approve', 'trigger' => 'manual', 'to' => 'approved',
        'preconditions_pass' => true, 'actor_allowed' => true, 'available' => true,
    ]]);
});

it('marks a manual edge unavailable when the actor is not allowed', function () {
    $version = unsavedVersion([
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod']]]],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->describe($version, $user, User::factory()->create(), 'draft');

    expect($result['edges'][0]['actor_allowed'])->toBeFalse();
    expect($result['edges'][0]['available'])->toBeFalse();
});

it('reports an error rather than throwing when the edge does not start at the given node', function () {
    $version = unsavedVersion([
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual']],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->advance($version, $user, null, 'approved', 'approve');

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('not available from');
});

it('reports a permitted transition with its actions previewed and resulting node', function () {
    $version = unsavedVersion([
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [[
            'id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual',
            'actions' => [['type' => 'send_notification', 'to' => 'actor', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
        ]],
    ]);
    $user = User::factory()->create();
    $actor = User::factory()->create();

    $result = $this->simulator->advance($version, $user, $actor, 'draft', 'approve');

    expect($result['ok'])->toBeTrue();
    expect($result['resulting_nodes'])->toBe(['approved']);
    expect($result['trail'])->toBe(['approved']);
    expect($result['actions_preview'])->toHaveCount(1);
    expect($result['actions_preview'][0])->toContain('Would send');
});

it('walks forward through a chain of automatic edges to the resting state', function () {
    $version = unsavedVersion([
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'auto1', 'type' => 'state'],
            ['id' => 'auto2', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'auto1', 'trigger' => 'manual'],
            ['id' => 'chain1', 'from' => 'auto1', 'to' => 'auto2', 'trigger' => 'automatic'],
        ],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->advance($version, $user, null, 'draft', 'go');

    expect($result['resulting_nodes'])->toBe(['auto2']);
    expect($result['trail'])->toBe(['auto1', 'auto2']);
});

it('spawns one resulting node per fork branch, following each branch forward independently', function () {
    $version = unsavedVersion([
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'fork1', 'type' => 'fork'],
            ['id' => 'branch_a', 'type' => 'state'],
            ['id' => 'branch_b', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'fork1', 'trigger' => 'manual'],
            ['id' => 'fork_a', 'from' => 'fork1', 'to' => 'branch_a', 'trigger' => 'automatic'],
            ['id' => 'fork_b', 'from' => 'fork1', 'to' => 'branch_b', 'trigger' => 'automatic'],
        ],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->advance($version, $user, null, 'draft', 'go');

    expect($result['resulting_nodes'])->toBe(['branch_a', 'branch_b']);
});

it('reports arrival at a join rather than guessing whether it completes', function () {
    $version = unsavedVersion([
        'nodes' => [
            ['id' => 'branch_a', 'type' => 'state'],
            ['id' => 'join1', 'type' => 'join'],
        ],
        'edges' => [
            ['id' => 'a_done', 'from' => 'branch_a', 'to' => 'join1', 'trigger' => 'manual'],
            ['id' => 'onward', 'from' => 'join1', 'to' => 'final', 'trigger' => 'automatic'],
        ],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->advance($version, $user, null, 'branch_a', 'a_done');

    expect($result['resulting_nodes'])->toBe(['join1']);
});

it('surfaces a precondition failure without persisting anything', function () {
    $version = unsavedVersion([
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [[
            'id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual',
            'preconditions' => ['type' => 'field_equals', 'field' => 'name', 'value' => 'nonexistent-name'],
        ]],
    ]);
    $user = User::factory()->create();

    $result = $this->simulator->advance($version, $user, null, 'draft', 'approve');

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('preconditions');
    expect(\Workflow\Models\WorkflowInstance::count())->toBe(0);
});
