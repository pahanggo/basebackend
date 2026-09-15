<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
});

it('only returns public methods named callbackFunction*, matching the search term', function () {
    $admin = User::factory()->create();
    $class = new class extends User
    {
        protected $table = 'users';

        public function callbackFunctionIsUrgent(): bool
        {
            return true;
        }

        public function notACallback(): bool
        {
            return true;
        }
    };
    WorkflowDefinition::create(['name' => 'callback-search-filter-test', 'slug' => 'callback-search-filter-test', 'model' => get_class($class)]);

    $response = $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['model' => get_class($class), 'q' => 'urgent']))
        ->assertOk();

    expect($response->json('results'))->toBe([['id' => 'callbackFunctionIsUrgent', 'text' => 'callbackFunctionIsUrgent']]);
});

it('allows a model declared outside app/Models, as long as it is a real workflow definition target', function () {
    // The Purchase Request demo's own model is the real-world case this
    // guards — living in its own package, not app/Models — but this test
    // stays self-contained rather than depending on that separate package.
    $admin = User::factory()->create();
    $class = new class extends User
    {
        protected $table = 'users';

        public function callbackFunctionIsOwner(): bool
        {
            return true;
        }
    };
    WorkflowDefinition::create(['name' => 'callback-search-outside-app-models-test', 'slug' => 'callback-search-outside-app-models-test', 'model' => get_class($class)]);

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['model' => get_class($class), 'q' => '']))
        ->assertOk()
        ->assertJsonFragment(['id' => 'callbackFunctionIsOwner']);
});

it('rejects a class that is not a recognized app model', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['model' => \stdClass::class, 'q' => '']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

it('rejects a missing model parameter', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['q' => '']))
        ->assertOk()
        ->assertJson(['results' => []]);
});
