<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
});

function publishVersioningTestDefinition(string $slug, array $graph)
{
    $definition = WorkflowDefinition::firstOrCreate(
        ['slug' => $slug],
        ['name' => $slug, 'model' => User::class],
    );

    $version = $definition->versions()->create([
        'version' => ($definition->versions()->max('version') ?? 0) + 1,
        'graph' => $graph,
        'published_at' => now(),
    ]);
    $definition->update(['published_version_id' => $version->id]);

    return [$definition->fresh(), $version];
}

it('setEnableVersioning() defaults to true — an in-flight instance stays pinned to the version it started on after a republish', function () {
    // Asserted explicitly rather than relying on the shipped config file's
    // own default value, which a downstream app (this one included) is
    // free to change — the trait's *own* fallback-to-config behavior is
    // what this test is proving, not what any particular config file ships with.
    config(['workflow.enable_versioning' => true]);

    [$definition, $v1] = publishVersioningTestDefinition('versioning-default-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v1_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v1_only', 'trigger' => 'manual']],
    ]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'versioning-default-test';
        }
    };

    expect($class::versioningEnabled())->toBeTrue();

    $model = $class::create(['name' => 'V1', 'username' => 'v1-pin', 'email' => 'v1-pin@example.com', 'password' => bcrypt('password')]);
    $instance = $model->workflowInstance();

    // Republish a graph whose only edge leads somewhere v1 never had.
    [, $v2] = publishVersioningTestDefinition('versioning-default-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v2_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v2_only', 'trigger' => 'manual']],
    ]);

    $instance->refresh();
    expect($instance->effectiveVersion()->id)->toBe($v1->id);
    expect($model->transitionTo('go'))->toBeTrue();
    expect($model->workflowInstance()->activeTokens()->first()->node_id)->toBe('v1_only');
});

it('disables versioning globally via config — every existing instance immediately runs against the current published version', function () {
    config(['workflow.enable_versioning' => false]);

    [$definition, $v1] = publishVersioningTestDefinition('versioning-config-disabled-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v1_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v1_only', 'trigger' => 'manual']],
    ]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'versioning-config-disabled-test';
        }
    };

    $model = $class::create(['name' => 'Config Disabled', 'username' => 'config-disabled', 'email' => 'config-disabled@example.com', 'password' => bcrypt('password')]);
    $instance = $model->workflowInstance();

    // Republish — the already-existing instance never touched this version
    // when it started, but with versioning disabled it should run against
    // it anyway, with no data migration.
    [, $v2] = publishVersioningTestDefinition('versioning-config-disabled-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v2_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v2_only', 'trigger' => 'manual']],
    ]);

    $instance->refresh();
    expect($instance->workflow_definition_version_id)->toBe($v1->id); // pinned column itself is untouched
    expect($instance->effectiveVersion()->id)->toBe($v2->id); // but it now resolves to the new one
    expect($model->transitionTo('go'))->toBeTrue();
    expect($model->workflowInstance()->activeTokens()->first()->node_id)->toBe('v2_only');
});

it('disables versioning per model via HasWorkflow::setEnableVersioning(false), overriding the (still enabled) global config', function () {
    // See the "defaults to true" test's own comment on why this is set
    // explicitly instead of assumed from the shipped config file.
    config(['workflow.enable_versioning' => true]);

    [$definition, $v1] = publishVersioningTestDefinition('versioning-model-override-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v1_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v1_only', 'trigger' => 'manual']],
    ]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'versioning-model-override-test';
        }
    };
    $class::setEnableVersioning(false);

    expect($class::versioningEnabled())->toBeFalse();
    expect(config('workflow.enable_versioning'))->toBeTrue();

    $model = $class::create(['name' => 'Model Override', 'username' => 'model-override', 'email' => 'model-override@example.com', 'password' => bcrypt('password')]);
    $instance = $model->workflowInstance();

    [, $v2] = publishVersioningTestDefinition('versioning-model-override-test', [
        'start' => 'draft',
        'nodes' => [['id' => 'draft', 'type' => 'state'], ['id' => 'v2_only', 'type' => 'state']],
        'edges' => [['id' => 'go', 'from' => 'draft', 'to' => 'v2_only', 'trigger' => 'manual']],
    ]);

    $instance->refresh();
    expect($instance->effectiveVersion()->id)->toBe($v2->id);
    expect($model->transitionTo('go'))->toBeTrue();
    expect($model->workflowInstance()->activeTokens()->first()->node_id)->toBe('v2_only');

    $class::setEnableVersioning(true);
});
