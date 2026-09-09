<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();
});

it('lists workflow definitions', function () {
    WorkflowDefinition::create(['name' => 'Simple Approval', 'slug' => 'simple-approval', 'model' => User::class]);

    // The list page itself is just the DataTables shell; rows are populated via
    // its AJAX search endpoint, so that's what actually needs asserting on.
    $this->actingAs($this->admin)->get(route('definitions.index'))->assertOk();

    // DataTables' response encodes each column as rendered HTML inside a
    // data array-of-arrays (not named keys), so assert on raw content.
    $this->actingAs($this->admin)
        ->post(route('definitions.search'))
        ->assertOk()
        ->assertSee('Simple Approval');
});

it('creates a workflow definition through the Backpack form', function () {
    $this->actingAs($this->admin)
        ->post(route('definitions.store'), [
            'name' => 'Simple Approval',
            'slug' => 'simple-approval',
            'model' => User::class,
            'description' => 'A test workflow',
        ])
        ->assertRedirect();

    expect(WorkflowDefinition::where('slug', 'simple-approval')->exists())->toBeTrue();
});

it('renders the designer with the currently published graph', function () {
    $definition = WorkflowDefinition::create(['name' => 'Simple Approval', 'slug' => 'simple-approval', 'model' => User::class]);
    $version = $definition->versions()->create([
        'version' => 1,
        'graph' => ['start' => 'draft', 'nodes' => [['id' => 'draft', 'type' => 'state']], 'edges' => []],
        'published_at' => now(),
    ]);
    $definition->update(['published_version_id' => $version->id]);

    $this->actingAs($this->admin)
        ->get(route('workflow.designer.edit', $definition))
        ->assertOk()
        ->assertSee('draft', false);
});

it('publishes a new version from the designer without disturbing in-flight instances', function () {
    $definition = WorkflowDefinition::create(['name' => 'Simple Approval', 'slug' => 'simple-approval', 'model' => User::class]);
    $v1 = $definition->versions()->create([
        'version' => 1,
        'graph' => ['start' => 'draft', 'nodes' => [['id' => 'draft', 'type' => 'state']], 'edges' => []],
        'published_at' => now(),
    ]);
    $definition->update(['published_version_id' => $v1->id]);

    // An instance in flight on version 1.
    $instance = $definition->instances()->create([
        'workflow_definition_version_id' => $v1->id,
        'workflowable_type' => User::class,
        'workflowable_id' => $this->admin->id,
        'status' => 'active',
    ]);

    $newGraph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'approved', 'type' => 'state']],
        'edges' => [['id' => 'submit', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual']],
    ];

    $this->actingAs($this->admin)
        ->post(route('workflow.designer.update', $definition), ['graph' => json_encode($newGraph)])
        ->assertRedirect(route('workflow.designer.edit', $definition));

    $definition->refresh();
    expect($definition->versions()->count())->toBe(2);
    expect($definition->publishedVersion->version)->toBe(2);

    // The in-flight instance still points at version 1's graph.
    $instance->refresh();
    expect($instance->version->version)->toBe(1);
    expect($instance->version->graph['edges'])->toBe([]);
});
