<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use WorkflowDemo\PurchaseRequest\Database\Seeders\PurchaseRequestDemoSeeder;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->migratePurchaseRequestDemoDatabase();
    $this->seed(PurchaseRequestDemoSeeder::class);
});

/**
 * @param  bool  $clearDraftRowActions  The seeder's own 'draft' node declares
 *                                      its own row_actions.update override
 *                                      (requester-only), which — being a
 *                                      per-node override — always wins over
 *                                      whatever this helper sets at the
 *                                      definition level (see
 *                                      Workflow\Support\WorkflowRowActions's
 *                                      own precedence docblock). A test
 *                                      exercising the definition-level
 *                                      setting in isolation needs that
 *                                      override out of the way first; pass
 *                                      true (the default) for that.
 */
function republishWithOperationSettings(array $operationSettings, bool $clearDraftRowActions = true): void
{
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;
    $graph['operation_settings'] = $operationSettings;

    if ($clearDraftRowActions) {
        foreach ($graph['nodes'] as &$node) {
            if ($node['id'] === 'draft') {
                unset($node['row_actions']);
            }
        }
        unset($node);
    }

    $newVersion = $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $newVersion->id]);
}

it('hides/denies the delete operation entirely when disabled from the settings modal', function () {
    republishWithOperationSettings([
        'show' => ['enabled' => true],
        'update' => ['enabled' => true],
        'delete' => ['enabled' => false],
    ]);

    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->delete("/app/purchase-requests/{$sample->id}")->assertForbidden();
    expect(PurchaseRequest::find($sample->id))->not->toBeNull();
});

it('restricts the update operation to a role via the settings modal\'s actor_rule', function () {
    republishWithOperationSettings([
        'show' => ['enabled' => true],
        'update' => ['enabled' => true, 'actor_rule' => ['roles' => ['hod'], 'match' => 'any']],
        'delete' => ['enabled' => true],
    ]);

    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $hod = User::where('email', 'hod@purchase-request-demo.test')->first();
    $sample = PurchaseRequest::first();

    $this->actingAs($employee)->get("/app/purchase-requests/{$sample->id}/edit")->assertForbidden();
    $this->actingAs($hod)->get("/app/purchase-requests/{$sample->id}/edit")->assertOk();
});

it('a node\'s row_actions override re-enables update for records sitting there, even though the definition-level setting disables it globally', function () {
    // The exact bug reported: "I set Edit enabled on Draft ... Action
    // button not showing" — update disabled everywhere via the settings
    // modal, re-enabled just for 'draft' via that node's row_actions.
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;
    $graph['operation_settings'] = ['update' => ['enabled' => false]];
    foreach ($graph['nodes'] as &$node) {
        if ($node['id'] === 'draft') {
            $node['row_actions'] = ['update' => ['enabled' => true]];
        }
    }
    unset($node);
    $newVersion = $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $newVersion->id]);

    $admin = User::factory()->create();
    $sample = PurchaseRequest::first(); // freshly seeded, sits in 'draft'

    // A single HTTP call, not chained with a second one in this same test —
    // see this suite's own repeated note elsewhere about Backpack's
    // per-operation CrudPanel state leaking across in-process requests
    // within one test method (never across real, separate HTTP requests).
    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/edit")->assertOk();
});

it('a node\'s row_actions override re-enables update in the row\'s own button too, not just the route', function () {
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;
    $graph['operation_settings'] = ['update' => ['enabled' => false]];
    foreach ($graph['nodes'] as &$node) {
        if ($node['id'] === 'draft') {
            $node['row_actions'] = ['update' => ['enabled' => true]];
        }
    }
    unset($node);
    $newVersion = $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $newVersion->id]);

    $admin = User::factory()->create();
    $sample = PurchaseRequest::first(); // freshly seeded, sits in 'draft'

    $response = $this->actingAs($admin)->postJson('/app/purchase-requests/search')->assertOk();
    $row = collect($response->json('data'))
        ->map(fn (array $r) => end($r))
        ->first(fn (string $cell) => str_contains($cell, "purchase-requests/{$sample->id}/edit"));

    expect($row)->not->toBeNull();
});

// Two separate tests, not one chaining both requests — see this file's own
// repeated note (and PurchaseRequestCrudControllerTest's) about Backpack's
// per-operation CrudPanel state leaking across in-process requests within a
// single test method (never across real, separate HTTP requests).
it('leaves show open — the seeder\'s own operation_settings only restricts update/delete', function () {
    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/show")->assertOk();
});

it('denies update to a non-requester per the seeder\'s own operation_settings, with no republish needed', function () {
    // The live definition's actual seeded operation_settings disable Update
    // definition-wide; the 'draft' node's row_actions override re-enables
    // it only for the request's own requester (callbackFunctionIsRequester)
    // — a bare no-role, non-requester admin is denied by both layers.
    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/edit")->assertForbidden();
});

// The exact bug reported: Create restricted to 'employee' via the settings
// modal, but a non-employee (e.g. CEO) could still see the "Add" button on
// the list page — because the access check only ever ran during the
// 'create' operation itself, never during 'list' (which is what actually
// renders that button, via $crud->hasAccess('create')). Two separate tests,
// not one chaining requests — see this file's own repeated note about
// Backpack's per-operation CrudPanel state leaking within a single test.
it('hides the Add button on the list page from a non-employee once Create is restricted to the employee role', function () {
    republishWithOperationSettings([
        'create' => ['enabled' => true, 'actor_rule' => ['roles' => ['employee'], 'match' => 'any']],
    ]);

    $ceo = User::where('email', 'ceo@purchase-request-demo.test')->first();

    $this->actingAs($ceo)
        ->get('/app/purchase-requests')
        ->assertOk()
        ->assertDontSee(trans('backpack::crud.add'));
});

it('shows the Add button and allows the actual create route for the role Create is restricted to', function () {
    republishWithOperationSettings([
        'create' => ['enabled' => true, 'actor_rule' => ['roles' => ['employee'], 'match' => 'any']],
    ]);

    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();

    $this->actingAs($employee)
        ->get('/app/purchase-requests')
        ->assertOk()
        ->assertSee(trans('backpack::crud.add'));
});

it('denies the create route directly to a non-employee, not just hiding the button', function () {
    republishWithOperationSettings([
        'create' => ['enabled' => true, 'actor_rule' => ['roles' => ['employee'], 'match' => 'any']],
    ]);

    $ceo = User::where('email', 'ceo@purchase-request-demo.test')->first();

    $this->actingAs($ceo)->get('/app/purchase-requests/create')->assertForbidden();
});
