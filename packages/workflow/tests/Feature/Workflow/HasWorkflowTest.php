<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
});

it('starts the workflow automatically when a HasWorkflow model is created', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state']],
        'edges' => [],
    ];
    $definition = WorkflowDefinition::create(['name' => 'auto-start-test', 'slug' => 'auto-start-test', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'auto-start-test';
        }
    };

    $model = $class::create(['name' => 'Auto Start', 'username' => 'auto-start', 'email' => 'auto-start@example.com', 'password' => bcrypt('password')]);

    $instance = $model->workflowInstance();
    expect($instance)->not->toBeNull();
    expect($instance->status)->toBe('active');
    expect($instance->activeTokens()->first()->node_id)->toBe('draft');
});

it('does not start a workflow for a plain find() on an existing row, only for create()', function () {
    // The fixture pattern used throughout the rest of this suite (wrap an
    // already-existing plain User row via find()) must stay side-effect
    // free — only actually creating a new row should auto-start anything.
    $existing = User::factory()->create();

    $definition = WorkflowDefinition::create(['name' => 'no-auto-start-test', 'slug' => 'no-auto-start-test', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => ['start' => 'draft', 'nodes' => [['id' => 'draft', 'type' => 'state']], 'edges' => []], 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'no-auto-start-test';
        }
    };

    $wrapped = $class::find($existing->id);

    expect($wrapped->workflowInstance())->toBeNull();
});
