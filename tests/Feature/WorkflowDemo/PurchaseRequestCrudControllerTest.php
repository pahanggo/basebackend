<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use WorkflowDemo\PurchaseRequest\Database\Seeders\PurchaseRequestDemoSeeder;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->migratePurchaseRequestDemoDatabase();
    $this->seed(PurchaseRequestDemoSeeder::class);
});

// Three separate tests, not one test chaining three requests: Backpack's
// CrudPanel holds per-operation state that isn't fully reset between
// back-to-back requests inside a single test method the way it would be
// across real, separate HTTP requests in production — chaining list/show/edit
// in one test previously produced a false-positive crash (the "current
// operation" leaking from the first request into the third).
it('renders the list page without error', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get('/app/purchase-requests')->assertOk();
});

it('renders the show page without error', function () {
    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/show")->assertOk()->assertSee('Workflow:');
});

it('renders the workflow_transition_assets button script on the list page', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get('/app/purchase-requests')->assertOk()->assertSee('openWorkflowTransitionModal', false);
});

it('renders the workflow_transition_assets button script on the show page too', function () {
    // The list page uses the 'top' button stack, but this app's own
    // show.blade.php only ever renders the 'line' stack — a button
    // registered only for 'list' (as this was originally written) never
    // renders on show at all, silently leaving the workflow column's
    // transition buttons there with no working click handler.
    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/show")->assertOk()->assertSee('openWorkflowTransitionModal', false);
});

it('renders the edit page without error', function () {
    $admin = User::factory()->create();
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)->get("/app/purchase-requests/{$sample->id}/edit")->assertOk()->assertSee('Workflow');
});

it('starts the workflow immediately when a purchase request is created via the CRUD form', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post('/app/purchase-requests', [
            'purpose' => 'CRUD store test',
            'amount' => 40,
            'requester_id' => $admin->id,
        ])
        ->assertRedirect();

    $pr = PurchaseRequest::where('purpose', 'CRUD store test')->first();
    expect($pr)->not->toBeNull();
    expect($pr->workflowInstance())->not->toBeNull();
    expect($pr->workflowInstance()->activeTokens()->first()->node_id)->toBe('draft');
});
