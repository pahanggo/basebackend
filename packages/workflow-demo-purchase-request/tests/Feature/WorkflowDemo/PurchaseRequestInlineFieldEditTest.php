<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Support\TransitionEngine;
use WorkflowDemo\PurchaseRequest\Database\Seeders\PurchaseRequestDemoSeeder;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->migratePurchaseRequestDemoDatabase();
    $this->seed(PurchaseRequestDemoSeeder::class);

    $this->engine = app(TransitionEngine::class);
    $this->users = collect(PurchaseRequestDemoSeeder::ROLES)
        ->mapWithKeys(fn ($role) => [$role => User::where('email', "{$role}@purchase-request-demo.test")->first()]);
});

function requestAtPendingHodReview(): PurchaseRequest
{
    $request = PurchaseRequest::create([
        'requester_id' => test()->users['employee']->id,
        'amount' => 1000,
        'purpose' => 'Original purpose',
    ]);
    $token = $request->workflowInstance()->activeTokens()->first();
    test()->engine->transition($token, 'submit', [], test()->users['employee']);

    return $request->fresh();
}

it('lets the hod edit amount/purpose directly from the show-workflow page, with no update-operation access needed', function () {
    // Update stays disabled definition-wide for this model (per the seeded
    // graph's own operation_settings) — the edit button/route is never
    // involved here, only field_policy readonly=false on this node.
    $request = requestAtPendingHodReview();
    expect($request->getAttribute('amount'))->toEqual(1000);

    $this->actingAs($this->users['hod'])
        ->post(route('workflow.show.update'), [
            'workflowable_type' => PurchaseRequest::class,
            'workflowable_id' => $request->id,
            'amount' => '2500',
            'purpose' => 'Updated on the show-workflow page',
        ])
        ->assertRedirect();

    $request->refresh();
    expect((float) $request->amount)->toEqual(2500.0);
    expect($request->purpose)->toBe('Updated on the show-workflow page');
});

it('shows the amount/purpose fields as real inputs on the show-workflow page, and hides the edit button/pencil icon', function () {
    $request = requestAtPendingHodReview();

    $response = $this->actingAs($this->users['hod'])->get(route('workflow.show', [
        'workflowable_type' => PurchaseRequest::class,
        'workflowable_id' => $request->id,
    ]));

    $response->assertOk();
    $response->assertSee('name="amount"', false);
    $response->assertSee('name="purpose"', false);
    $response->assertDontSee('workflow_gated_update', false);
});

it('wires up the data-init-function dispatcher so editable fields like money actually submit what the user typed', function () {
    // A normal Backpack create/edit form runs this dispatcher via
    // crud/form_content.blade.php; this page renders fields directly
    // instead, so without its own copy an input like money's hidden
    // "source of truth" field never gets wired to the visible one the user
    // types into — Save silently resubmits the value the page loaded with.
    $request = requestAtPendingHodReview();

    $response = $this->actingAs($this->users['hod'])->get(route('workflow.show', [
        'workflowable_type' => PurchaseRequest::class,
        'workflowable_id' => $request->id,
    ]));

    $response->assertOk();
    $response->assertSee('data-init-function="bpFieldInitMoneyElement"', false);
    $response->assertSee('wfInitializeFieldsWithJavascript', false);
});

it('adds a timeline entry for who edited what, and redirects back to the same show-workflow page', function () {
    $request = requestAtPendingHodReview();

    $response = $this->actingAs($this->users['hod'])
        ->post(route('workflow.show.update'), [
            'workflowable_type' => PurchaseRequest::class,
            'workflowable_id' => $request->id,
            'amount' => '2500',
            'purpose' => 'Updated on the show-workflow page',
        ]);

    $response->assertRedirect(route('workflow.show', [
        'workflowable_type' => PurchaseRequest::class,
        'workflowable_id' => $request->id,
    ]));

    $timeline = app(\Workflow\Support\WorkflowTimeline::class)->build($request->fresh());
    $editStep = collect($timeline)->firstWhere('edge_label', 'Edited fields');

    expect($editStep)->not->toBeNull();
    expect($editStep['trigger'])->toBe('edit');
    expect($editStep['actor'])->toBe($this->users['hod']->name ?? $this->users['hod']->email);

    $inputsByLabel = collect($editStep['inputs'])->keyBy('label');
    expect($inputsByLabel->get('Amount')['value'])->toBe('1000.00 → 2500.00');
    expect($inputsByLabel->get('Purpose')['value'])->toBe('Original purpose → Updated on the show-workflow page');
});

it('never lets a field not marked editable in field_policy be updated this way, even if submitted', function () {
    $request = requestAtPendingHodReview();

    $this->actingAs($this->users['hod'])->post(route('workflow.show.update'), [
        'workflowable_type' => PurchaseRequest::class,
        'workflowable_id' => $request->id,
        'amount' => '999',
        // hod_remarks is readonly (not editable) on the pending_hod_review node.
        'hod_remarks' => 'should never be saved this way',
    ])->assertRedirect();

    $request->refresh();
    expect($request->hod_remarks)->toBeNull();
});
