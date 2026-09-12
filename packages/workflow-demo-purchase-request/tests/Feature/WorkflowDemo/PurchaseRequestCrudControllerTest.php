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

it('renders the show page without error, linking out to the dedicated workflow-show page', function () {
    // Uses 'employee' (not a bare no-role admin) since the icon is now
    // hidden on the show page too once the actor has no available action —
    // 'employee' has 'submit' available on this freshly seeded 'draft' row.
    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $sample = PurchaseRequest::first();

    $this->actingAs($employee)
        ->get("/app/purchase-requests/{$sample->id}/show")
        ->assertOk()
        ->assertSee('la-sitemap', false)
        ->assertSee("workflowable_id={$sample->id}", false);
});

it('renders the workflow_show icon with a red dot when the actor has an available transition', function () {
    // Every transition now fires from the dedicated "show-workflow" page,
    // never from an inline row button — the row itself only ever links to
    // that page via the workflow_show icon, with a red dot overlay whenever
    // WorkflowStatusPresenter reports at least one available transition.
    // Row content — every column, including this icon — comes back through
    // the DataTables AJAX /search endpoint, never as part of the initial
    // GET's HTML (that's just the empty table shell), and that response is
    // a JSON envelope whose own quotes come back escaped, so decode it
    // rather than assertSee-ing the raw (still-escaped) response body.
    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $sample = PurchaseRequest::first(); // freshly seeded, sits in 'draft' with 'submit' available to 'employee'

    $response = $this->actingAs($employee)->postJson('/app/purchase-requests/search')->assertOk();

    $actionsCell = collect($response->json('data'))
        ->map(fn (array $row) => end($row))
        ->first(fn (string $cell) => str_contains($cell, "workflowable_id={$sample->id}"));

    expect($actionsCell)->not->toBeNull();
    expect($actionsCell)->toContain('la-sitemap');
    expect($actionsCell)->toContain('Action needed'); // the red dot's title attribute
});

it('hides the workflow_show icon on the list page entirely when the actor has no available action for that record', function () {
    // $admin has no role at all, so none of the demo's role-gated edges
    // (including 'submit', which needs 'employee') are available to them —
    // the icon should disappear from the list row entirely, not just lose
    // its red dot, since the list is specifically where users scan for
    // "what needs my attention" and a dead-end icon there is just clutter.
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->postJson('/app/purchase-requests/search')->assertOk();

    $anyIconRendered = collect($response->json('data'))
        ->map(fn (array $row) => end($row))
        ->contains(fn (string $cell) => str_contains($cell, 'la-sitemap'));

    expect($anyIconRendered)->toBeFalse();
});

it('hides the workflow_show icon on the show page too when the actor has no available action for that record', function () {
    $admin = User::factory()->create(); // no role — no actions available
    $sample = PurchaseRequest::first();

    $this->actingAs($admin)
        ->get("/app/purchase-requests/{$sample->id}/show")
        ->assertOk()
        ->assertDontSee('la-sitemap', false);
});

it('renders the edit page without error', function () {
    // The live definition's operation_settings disable Update everywhere
    // except the 'draft' node's own row_actions override, which re-enables
    // it only for the request's own requester (callbackFunctionIsRequester)
    // — so a bare no-role admin 403s here now; the requester themself is
    // the one actor who genuinely can reach this page for a fresh sample.
    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $sample = PurchaseRequest::first();

    $this->actingAs($employee)->get("/app/purchase-requests/{$sample->id}/edit")->assertOk()->assertSee('Workflow');
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
