<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\WorkflowTimeline;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->timeline = app(WorkflowTimeline::class);
});

/**
 * A HasWorkflow-using class over the real `users` table, walking through 4
 * chained states (draft -> s1 -> s2 -> s3), so its instance accumulates 3
 * workflow_instance_history rows to build a timeline from.
 */
function timelineTestModel(string $slug)
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

    return $class::create([
        'name' => 'Timeline Test',
        'username' => 'timeline-test-'.$slug,
        'email' => 'timeline-test-'.$slug.'@example.com',
        'password' => bcrypt('password'),
    ]);
}

it('returns an empty array for a model with no active workflow instance', function () {
    $user = User::factory()->create();

    expect($this->timeline->build($user))->toBe([]);
});

it('returns steps newest-first with correctly computed time_spent per step', function () {
    $model = timelineTestModel('timeline-order-test');

    $this->travel(1)->hours();
    $model->transitionTo('go1');
    $this->travel(2)->hours();
    $model->transitionTo('go2');
    $this->travel(3)->hours();
    $model->transitionTo('go3');

    $steps = $this->timeline->build($model->fresh());

    expect($steps)->toHaveCount(3);

    // Newest-first: the last transition fired (go3, s2 -> s3) is step 0.
    expect($steps[0]['from_label'])->toBe('Step 2');
    expect($steps[0]['to_label'])->toBe('Step 3');
    expect($steps[1]['from_label'])->toBe('Step 1');
    expect($steps[1]['to_label'])->toBe('Step 2');
    expect($steps[2]['from_label'])->toBe('Draft');
    expect($steps[2]['to_label'])->toBe('Step 1');

    // time_spent is "how long since the previous step", not since now —
    // go1 fired 1h after the instance started, go2 2h after go1, go3 3h
    // after go2. Asserting on the hour digit is enough to prove the
    // chronological (not reversed) duration calc survived the newest-first
    // display order.
    expect($steps[2]['time_spent'])->toContain('1');
    expect($steps[1]['time_spent'])->toContain('2');
    expect($steps[0]['time_spent'])->toContain('3');
});

it('only shows a captured input whose edge schema explicitly sets show_in_timeline to true', function () {
    $slug = 'timeline-input-visibility-test';
    $definition = WorkflowDefinition::create(['name' => $slug, 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create([
        'version' => 1,
        'published_at' => now(),
        'graph' => [
            'start' => 'draft',
            'nodes' => [
                ['id' => 'draft', 'type' => 'state', 'name' => 'Draft'],
                ['id' => 's1', 'type' => 'state', 'name' => 'Step 1'],
            ],
            'edges' => [[
                'id' => 'go1',
                'from' => 'draft',
                'to' => 's1',
                'trigger' => 'manual',
                'name' => 'Go 1',
                'inputs' => [
                    ['name' => 'hidden_note', 'type' => 'textarea', 'show_in_timeline' => false],
                    ['name' => 'visible_note', 'type' => 'textarea', 'show_in_timeline' => true],
                    ['name' => 'legacy_note', 'type' => 'textarea'], // no key at all — defaults to hidden
                ],
            ]],
        ],
    ]);
    $definition->update(['published_version_id' => $version->id]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'timeline-input-visibility-test';
        }
    };
    $model = $class::create([
        'name' => 'Timeline Input Visibility',
        'username' => 'timeline-input-visibility',
        'email' => 'timeline-input-visibility@example.com',
        'password' => bcrypt('password'),
    ]);

    $model->transitionTo('go1', [
        'hidden_note' => 'should not appear',
        'visible_note' => 'should appear',
        'legacy_note' => 'should not appear either',
    ]);

    $steps = $this->timeline->build($model->fresh());
    $labels = collect($steps[0]['inputs'])->pluck('label')->all();

    expect($labels)->not->toContain('Hidden Note');
    expect($labels)->toContain('Visible Note');
    expect($labels)->not->toContain('Legacy Note');
});
