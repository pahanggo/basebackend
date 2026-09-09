<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\TransitionEngine;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();
});

function definitionForTransitionEndpoint(): WorkflowDefinition
{
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'approved', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual'],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'endpoint-test', 'slug' => 'endpoint-test-'.uniqid(), 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('rejects a workflowable_type that does not use HasWorkflow', function () {
    $this->actingAs($this->admin)
        ->post(route('workflow.transition'), [
            'workflowable_type' => \stdClass::class,
            'workflowable_id' => 1,
            'edge_id' => 'approve',
        ])
        ->assertNotFound();
});

it('transitions a real workflow-enabled record through the shared endpoint', function () {
    // A tiny throwaway model, not User itself, so this test doesn't depend
    // on User ever adopting HasWorkflow.
    $definition = definitionForTransitionEndpoint();
    $subject = User::factory()->create();

    // Fake a HasWorkflow-using class pointed at the same underlying row, since
    // User itself doesn't declare the trait in this app.
    $workflowableClass = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'unused';
        }
    };
    $workflowable = $workflowableClass::find($subject->id);

    $engine = app(TransitionEngine::class);
    $instance = $engine->start($workflowable, $definition);

    $this->actingAs($this->admin)
        ->post(route('workflow.transition'), [
            'workflowable_type' => $workflowable::class,
            'workflowable_id' => $subject->id,
            'edge_id' => 'approve',
        ])
        ->assertRedirect();

    $instance->refresh();
    expect($instance->activeTokens()->first()->node_id)->toBe('approved');
});
