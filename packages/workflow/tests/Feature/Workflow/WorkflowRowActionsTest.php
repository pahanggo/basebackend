<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\WorkflowRowActions;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->rowActions = app(WorkflowRowActions::class);
});

function definitionForRowActions(string $slug, array $nodeOverrides = [], array $operationSettings = []): WorkflowDefinition
{
    $graph = [
        'start' => 'draft',
        'nodes' => [
            array_merge(['id' => 'draft', 'type' => 'state', 'name' => 'Draft'], $nodeOverrides),
        ],
        'edges' => [],
    ];

    if ($operationSettings) {
        $graph['operation_settings'] = $operationSettings;
    }

    $definition = WorkflowDefinition::create(['name' => 'row-actions-test', 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('allows an operation when the node has no row_actions override at all', function () {
    definitionForRowActions('row-actions-none-test');
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-none-test';
        }
    };
    $model = $class::create(['name' => 'A', 'username' => 'row-actions-none', 'email' => 'row-actions-none@example.com', 'password' => bcrypt('password')]);

    expect($this->rowActions->allowed($model, 'delete', null))->toBeTrue();
});

it('hides an operation the node explicitly disables', function () {
    definitionForRowActions('row-actions-disabled-test', [
        'row_actions' => ['delete' => ['enabled' => false]],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-disabled-test';
        }
    };
    $model = $class::create(['name' => 'B', 'username' => 'row-actions-disabled', 'email' => 'row-actions-disabled@example.com', 'password' => bcrypt('password')]);

    expect($this->rowActions->allowed($model, 'delete', null))->toBeFalse();
    expect($this->rowActions->allowed($model, 'show', null))->toBeTrue(); // untouched operation still falls back to allowed
});

it('restricts an operation to a role via the node\'s row_actions actor_rule', function () {
    definitionForRowActions('row-actions-role-test', [
        'row_actions' => ['update' => ['enabled' => true, 'actor_rule' => ['roles' => ['hod'], 'match' => 'any']]],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-role-test';
        }
    };
    $model = $class::create(['name' => 'C', 'username' => 'row-actions-role', 'email' => 'row-actions-role@example.com', 'password' => bcrypt('password')]);

    $plainUser = User::factory()->create();
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'hod', 'guard_name' => 'web']);
    $hod = User::factory()->create();
    $hod->assignRole('hod');

    expect($this->rowActions->allowed($model, 'update', $plainUser))->toBeFalse();
    expect($this->rowActions->allowed($model, 'update', $hod))->toBeTrue();
});

it('falls back to the definition-level operation_settings when the node declares no override for that operation', function () {
    definitionForRowActions('row-actions-fallback-test', [], [
        'update' => ['enabled' => false],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-fallback-test';
        }
    };
    $model = $class::create(['name' => 'D', 'username' => 'row-actions-fallback', 'email' => 'row-actions-fallback@example.com', 'password' => bcrypt('password')]);

    expect($this->rowActions->allowed($model, 'update', null))->toBeFalse();
});

it('a node override RE-ENABLES an operation the definition-level setting disabled globally, not just restricts it further', function () {
    // This is the exact bug reported: Update disabled everywhere via the
    // gear-icon settings modal, then re-enabled just for 'draft' via that
    // node's row_actions — the node override must win outright, not be
    // ANDed with the (disabled) definition-level setting.
    definitionForRowActions('row-actions-override-wins-test', [
        'row_actions' => ['update' => ['enabled' => true]],
    ], [
        'update' => ['enabled' => false],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-override-wins-test';
        }
    };
    $model = $class::create(['name' => 'E', 'username' => 'row-actions-override-wins', 'email' => 'row-actions-override-wins@example.com', 'password' => bcrypt('password')]);

    expect($this->rowActions->allowed($model, 'update', null))->toBeTrue();
});

it('always allows a record with no active workflow instance', function () {
    definitionForRowActions('row-actions-no-instance-test', [
        'row_actions' => ['delete' => ['enabled' => false]],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-no-instance-test';
        }
    };
    $existing = User::factory()->create();
    $wrapped = $class::find($existing->id); // plain find() never auto-starts a workflow

    expect($this->rowActions->allowed($wrapped, 'delete', null))->toBeTrue();
});

it('allows create when the definition sets no operation_settings.create at all', function () {
    $definition = definitionForRowActions('row-actions-create-none-test');
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-create-none-test';
        }
    };
    // allowedToCreate() looks the definition up by its 'model' column
    // (there's no entry/instance yet to resolve it any other way) — point
    // it at this test's own throwaway class rather than the helper's
    // default User::class, which doesn't carry HasWorkflow in this app.
    $definition->update(['model' => get_class($class)]);

    expect($this->rowActions->allowedToCreate(get_class($class), null))->toBeTrue();
});

it('denies create when the definition-level operation_settings.create is disabled', function () {
    $definition = definitionForRowActions('row-actions-create-disabled-test', [], [
        'create' => ['enabled' => false],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-create-disabled-test';
        }
    };
    $definition->update(['model' => get_class($class)]);

    expect($this->rowActions->allowedToCreate(get_class($class), null))->toBeFalse();
});

it('restricts create to a role via operation_settings.create\'s actor_rule', function () {
    $definition = definitionForRowActions('row-actions-create-role-test', [], [
        'create' => ['enabled' => true, 'actor_rule' => ['roles' => ['hod'], 'match' => 'any']],
    ]);
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'row-actions-create-role-test';
        }
    };
    $definition->update(['model' => get_class($class)]);

    $plainUser = User::factory()->create();
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'hod', 'guard_name' => 'web']);
    $hod = User::factory()->create();
    $hod->assignRole('hod');

    expect($this->rowActions->allowedToCreate(get_class($class), $plainUser))->toBeFalse();
    expect($this->rowActions->allowedToCreate(get_class($class), $hod))->toBeTrue();
});
