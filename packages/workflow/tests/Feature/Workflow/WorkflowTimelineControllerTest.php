<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();
});

/**
 * A HasWorkflow-using class over the real `users` table, walking through 3
 * chained transitions so its instance accumulates 3 workflow_instance_history
 * rows — enough to exercise the "3 visible, rest behind offset" split this
 * endpoint backs (see packages/workflow/src/resources/views/inc/workflow_timeline.blade.php).
 */
function timelineEndpointTestModel(string $slug)
{
    $definition = WorkflowDefinition::create(['name' => $slug, 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create([
        'version' => 1,
        'published_at' => now(),
        'graph' => [
            'start' => 'draft',
            'nodes' => [
                ['id' => 'draft', 'type' => 'state', 'name' => 'Draft'],
                ['id' => 's1', 'type' => 'state', 'name' => 'Step 1'],
                ['id' => 's2', 'type' => 'state', 'name' => 'Step 2'],
                ['id' => 's3', 'type' => 'state', 'name' => 'Step 3'],
            ],
            'edges' => [
                ['id' => 'go1', 'from' => 'draft', 'to' => 's1', 'trigger' => 'manual', 'name' => 'Go 1'],
                ['id' => 'go2', 'from' => 's1', 'to' => 's2', 'trigger' => 'manual', 'name' => 'Go 2'],
                ['id' => 'go3', 'from' => 's2', 'to' => 's3', 'trigger' => 'manual', 'name' => 'Go 3'],
            ],
        ],
    ]);
    $definition->update(['published_version_id' => $version->id]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        public static string $slugOverride = '';

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return static::$slugOverride;
        }
    };
    $class::$slugOverride = $slug;

    $model = $class::create([
        'name' => 'Timeline Endpoint Test',
        'username' => 'timeline-endpoint-'.$slug,
        'email' => 'timeline-endpoint-'.$slug.'@example.com',
        'password' => bcrypt('password'),
    ]);

    $model->transitionTo('go1');
    $model->transitionTo('go2');
    $model->transitionTo('go3');

    return $model;
}

it('rejects a workflowable_type that does not use HasWorkflow', function () {
    $this->actingAs($this->admin)
        ->get(route('workflow.timeline', ['workflowable_type' => \stdClass::class, 'workflowable_id' => 1]))
        ->assertNotFound();
});

it('returns steps from the given offset onward as a bare HTML fragment', function () {
    $model = timelineEndpointTestModel('timeline-endpoint-offset-test');

    $response = $this->actingAs($this->admin)
        ->get(route('workflow.timeline', [
            'workflowable_type' => get_class($model),
            'workflowable_id' => $model->id,
            'offset' => 1,
        ]))
        ->assertOk();

    // Offset 1 skips the newest step (s2 -> s3) and returns the older two.
    $response->assertSee('Step 1 &rarr; Step 2', false);
    $response->assertSee('Draft &rarr; Step 1', false);
    $response->assertDontSee('Step 2 &rarr; Step 3', false);
});

it('shows only the 3 most recent steps on the show-workflow page, with a link for the rest', function () {
    // A 4th transition makes 4 history rows total — 1 more than the
    // partial's visible-count, so the "... N more" trigger actually appears.
    $definition = WorkflowDefinition::create(['name' => 'timeline-truncate-test', 'slug' => 'timeline-truncate-test', 'model' => User::class]);
    $version = $definition->versions()->create([
        'version' => 1,
        'published_at' => now(),
        'graph' => [
            'start' => 'draft',
            'nodes' => [
                ['id' => 'draft', 'type' => 'state', 'name' => 'Draft'],
                ['id' => 's1', 'type' => 'state', 'name' => 'Step 1'],
                ['id' => 's2', 'type' => 'state', 'name' => 'Step 2'],
                ['id' => 's3', 'type' => 'state', 'name' => 'Step 3'],
                ['id' => 's4', 'type' => 'state', 'name' => 'Step 4'],
            ],
            'edges' => [
                ['id' => 'go1', 'from' => 'draft', 'to' => 's1', 'trigger' => 'manual', 'name' => 'Go 1'],
                ['id' => 'go2', 'from' => 's1', 'to' => 's2', 'trigger' => 'manual', 'name' => 'Go 2'],
                ['id' => 'go3', 'from' => 's2', 'to' => 's3', 'trigger' => 'manual', 'name' => 'Go 3'],
                ['id' => 'go4', 'from' => 's3', 'to' => 's4', 'trigger' => 'manual', 'name' => 'Go 4'],
            ],
        ],
    ]);
    $definition->update(['published_version_id' => $version->id]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'timeline-truncate-test';
        }
    };
    $model = $class::create([
        'name' => 'Timeline Truncate Test',
        'username' => 'timeline-truncate-test',
        'email' => 'timeline-truncate-test@example.com',
        'password' => bcrypt('password'),
    ]);
    $model->transitionTo('go1');
    $model->transitionTo('go2');
    $model->transitionTo('go3');
    $model->transitionTo('go4');

    $response = $this->actingAs($this->admin)
        ->get(route('workflow.show', ['workflowable_type' => get_class($model), 'workflowable_id' => $model->id]))
        ->assertOk();

    // Newest 3 (s1->s2, s2->s3, s3->s4) visible; oldest (draft->s1) is not.
    $response->assertSee('Step 3 &rarr; Step 4', false);
    $response->assertSee('Step 2 &rarr; Step 3', false);
    $response->assertSee('Step 1 &rarr; Step 2', false);
    $response->assertDontSee('Draft &rarr; Step 1', false);
    $response->assertSee('&hellip; 1 more', false);
    $response->assertSee('data-offset="3"', false);
});
