<?php

use App\Models\User;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Workflow\HasWorkflow;
use Workflow\Http\Controllers\Operations\WorkflowOperation;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

// Real, named (not anonymous) fixtures — Route::crud()'s macro resolves the
// controller via App::make($controllerClass), which needs a stable class
// name, and CRUD::setModel() needs a model class string too.
if (! class_exists('WorkflowBulkTransitionTestModel')) {
    class WorkflowBulkTransitionTestModel extends User
    {
        use HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'bulk-transition-test';
        }
    }
}

if (! class_exists('WorkflowBulkTransitionTestCrudController')) {
    class WorkflowBulkTransitionTestCrudController extends CrudController
    {
        use ListOperation;
        use WorkflowOperation;

        public function setup(): void
        {
            CRUD::setModel(WorkflowBulkTransitionTestModel::class);
            CRUD::setRoute('workflow-bulk-transition-test');
            CRUD::setEntityNameStrings('entry', 'entries');
        }
    }
}

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();

    Route::group(['middleware' => 'web'], function () {
        Route::crud('workflow-bulk-transition-test', WorkflowBulkTransitionTestCrudController::class);
    });

    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'approved', 'type' => 'state'],
        ],
        'edges' => [
            ['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual', 'surfaces' => ['bulk_action']],
        ],
    ];

    $this->definition = WorkflowDefinition::create(['name' => 'bulk-test', 'slug' => 'bulk-test-'.uniqid(), 'model' => WorkflowBulkTransitionTestModel::class]);
    $version = $this->definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $this->definition->update(['published_version_id' => $version->id]);
});

function startBulkTransitionInstance(WorkflowDefinition $definition, User $user): WorkflowBulkTransitionTestModel
{
    $workflowable = WorkflowBulkTransitionTestModel::find($user->id);
    app(\Workflow\Support\TransitionEngine::class)->start($workflowable, $definition);

    return $workflowable;
}

it('transitions every qualifying entry and reports skipped ones with a reason', function () {
    $qualifying = startBulkTransitionInstance($this->definition, User::factory()->create());
    $notStarted = User::factory()->create(); // no active instance at all

    $response = $this->actingAs($this->admin)
        ->post('/workflow-bulk-transition-test/workflow-bulk-transition', [
            'entries' => [$qualifying->id, $notStarted->id],
            'edge_id' => 'approve',
        ])
        ->assertOk()
        ->json();

    expect($response['succeeded'])->toBe([$qualifying->id]);
    expect($response['skipped'])->toHaveCount(1);
    expect($response['skipped'][0]['id'])->toBe($notStarted->id);
    expect($response['skipped'][0]['reason'])->toBe('No active workflow instance.');

    $instance = $qualifying->workflowInstance();
    expect($instance->activeTokens()->first()->node_id)->toBe('approved');
});

it('skips an entry for which the requested edge is not available from its current node', function () {
    $qualifying = startBulkTransitionInstance($this->definition, User::factory()->create());
    // Advance it past "draft" so "approve" (from draft) no longer applies.
    $qualifying->transitionTo('approve');

    $response = $this->actingAs($this->admin)
        ->post('/workflow-bulk-transition-test/workflow-bulk-transition', [
            'entries' => [$qualifying->id],
            'edge_id' => 'approve',
        ])
        ->assertOk()
        ->json();

    expect($response['succeeded'])->toBe([]);
    expect($response['skipped'][0]['reason'])->toBe('Transition not available from the record\'s current state.');
});

it('rejects an entry the actor is not permitted to transition, without affecting a permitted one in the same request', function () {
    $graph = [
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'approved', 'type' => 'state'],
        ],
        'edges' => [
            [
                'id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual',
                'surfaces' => ['bulk_action'], 'actor_rule' => ['roles' => ['hod'], 'match' => 'any'],
            ],
        ],
    ];
    $definition = WorkflowDefinition::create(['name' => 'bulk-restricted', 'slug' => 'bulk-restricted-'.uniqid(), 'model' => WorkflowBulkTransitionTestModel::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $entry = startBulkTransitionInstance($definition, User::factory()->create());

    // $this->admin has no 'hod' role, so the actor_rule should reject them.
    $response = $this->actingAs($this->admin)
        ->post('/workflow-bulk-transition-test/workflow-bulk-transition', [
            'entries' => [$entry->id],
            'edge_id' => 'approve',
        ])
        ->assertOk()
        ->json();

    expect($response['succeeded'])->toBe([]);
    expect($response['skipped'][0]['reason'])->toBe('Not permitted, or a precondition was not met.');
});
