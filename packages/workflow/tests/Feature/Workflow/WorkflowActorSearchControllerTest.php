<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();
});

it('returns roles, permissions, and users grouped, without a model param', function () {
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'reviewer', 'guard_name' => 'web']);

    $response = $this->actingAs($this->admin)
        ->getJson(route('workflow.actors.search', ['q' => 'review']))
        ->assertOk();

    $groups = collect($response->json('results'))->keyBy('text');
    expect($groups['Roles']['children'])->toContain(['id' => 'role:reviewer', 'text' => 'reviewer']);
    expect($groups['Callbacks']['children'])->toBe([]);
});

it('includes a "Callbacks" group of the given model\'s callbackFunction* methods, when it is a real workflow target model', function () {
    $class = new class extends User
    {
        protected $table = 'users';

        public function callbackFunctionIsUrgent(): bool
        {
            return true;
        }
    };

    WorkflowDefinition::create(['name' => 'callback-search-test', 'slug' => 'callback-search-test', 'model' => get_class($class)]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('workflow.actors.search', ['q' => 'urgent', 'model' => get_class($class)]))
        ->assertOk();

    $groups = collect($response->json('results'))->keyBy('text');
    expect($groups['Callbacks']['children'])->toContain(['id' => 'callback:callbackFunctionIsUrgent', 'text' => 'callbackFunctionIsUrgent']);
});

it('excludes callbacks for a model string that is not any workflow definition\'s target', function () {
    $response = $this->actingAs($this->admin)
        ->getJson(route('workflow.actors.search', ['q' => '', 'model' => \stdClass::class]))
        ->assertOk();

    $groups = collect($response->json('results'))->keyBy('text');
    expect($groups['Callbacks']['children'])->toBe([]);
});
