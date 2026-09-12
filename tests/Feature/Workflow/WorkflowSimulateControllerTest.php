<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Workflow\Models\WorkflowDefinition;
use Workflow\Models\WorkflowInstance;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();
    $this->definition = WorkflowDefinition::create(['name' => 'simulate-test', 'slug' => 'simulate-test-'.uniqid(), 'model' => User::class]);
});

function simulateGraph(array $edgeOverrides = []): array
{
    return [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'approved', 'type' => 'state'],
        ],
        'edges' => [
            array_merge(['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual'], $edgeOverrides),
        ],
    ];
}

it('describes a node\'s edges against a real sample record, without persisting anything', function () {
    $sample = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson(route('workflow.designer.simulate', $this->definition), [
            'graph' => simulateGraph(),
            'workflowable_id' => $sample->id,
            'node_id' => 'draft',
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue();
    expect($response['node']['id'])->toBe('draft');
    expect($response['edges'][0]['edge_id'])->toBe('approve');
    expect(WorkflowInstance::count())->toBe(0);
});

it('simulates firing an edge and reports the resulting node, never sending a real notification', function () {
    Notification::fake();
    $sample = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson(route('workflow.designer.simulate', $this->definition), [
            'graph' => simulateGraph([
                'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
            ]),
            'workflowable_id' => $sample->id,
            'node_id' => 'draft',
            'edge_id' => 'approve',
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeTrue();
    expect($response['resulting_nodes'])->toBe(['approved']);
    expect($response['actions_preview'][0])->toContain('Would send');

    Notification::assertNothingSent();
    expect(WorkflowInstance::count())->toBe(0);
});

it('rejects a sample record that does not exist', function () {
    $this->actingAs($this->admin)
        ->postJson(route('workflow.designer.simulate', $this->definition), [
            'graph' => simulateGraph(),
            'workflowable_id' => 999999,
            'node_id' => 'draft',
        ])
        ->assertStatus(422);
});

it('reports an edge the actor is not permitted to trigger, without executing it', function () {
    $sample = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson(route('workflow.designer.simulate', $this->definition), [
            'graph' => simulateGraph(['actor_rule' => ['roles' => ['hod'], 'match' => 'any']]),
            'workflowable_id' => $sample->id,
            'node_id' => 'draft',
            'edge_id' => 'approve',
        ])
        ->assertOk()
        ->json();

    expect($response['ok'])->toBeFalse();
    expect($response['error'])->toContain('not permitted');
});
