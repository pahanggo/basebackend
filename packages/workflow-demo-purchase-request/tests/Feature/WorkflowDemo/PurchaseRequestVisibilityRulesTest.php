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
 * Demonstrates Workflow\Support\WorkflowVisibilityScope end to end against
 * the real demo: employees see only their own requests, finance (standing in
 * for every role that processes every request regardless of who owns it)
 * sees everything, and a role with no rule configured at all — here, hod,
 * deliberately left out — sees nothing, per the "unmatched actor" default.
 */
function republishWithVisibilityRules(array $visibilityRules): void
{
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;
    $graph['visibility_rules'] = $visibilityRules;

    $newVersion = $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $newVersion->id]);
}

it('leaves the list unrestricted when no visibility_rules are configured — today\'s default, unchanged', function () {
    $admin = User::factory()->create(); // no role at all

    $response = $this->actingAs($admin)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(PurchaseRequest::count());
});

it('scopes an employee to only their own requests', function () {
    republishWithVisibilityRules([
        ['actor_rule' => ['roles' => ['finance', 'operations', 'marketing', 'ceo'], 'match' => 'any'], 'scope' => 'all'],
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'requester_id'],
    ]);

    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    // Every seeded sample request already belongs to 'employee' — add one
    // owned by someone else to prove it's actually excluded.
    $hod = User::where('email', 'hod@purchase-request-demo.test')->first();
    PurchaseRequest::create(['requester_id' => $hod->id, 'amount' => 1, 'purpose' => 'Not the employee\'s own']);

    $response = $this->actingAs($employee)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(PurchaseRequest::where('requester_id', $employee->id)->count());
});

it('lets finance (processes every request) see everything, including other people\'s', function () {
    republishWithVisibilityRules([
        ['actor_rule' => ['roles' => ['finance', 'operations', 'marketing', 'ceo'], 'match' => 'any'], 'scope' => 'all'],
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'requester_id'],
    ]);

    $finance = User::where('email', 'finance@purchase-request-demo.test')->first();

    $response = $this->actingAs($finance)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(PurchaseRequest::count());
});

it('sees nothing for a role with no visibility rule configured at all — deny by default once any rule exists', function () {
    republishWithVisibilityRules([
        ['actor_rule' => ['roles' => ['finance', 'operations', 'marketing', 'ceo'], 'match' => 'any'], 'scope' => 'all'],
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'requester_id'],
        // 'hod' deliberately has no rule here.
    ]);

    $hod = User::where('email', 'hod@purchase-request-demo.test')->first();

    $response = $this->actingAs($hod)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(0);
});

/**
 * The full three-tier example, using PurchaseRequestDemoSeeder's own
 * department-tagged fixtures (one employee and one HOD per department, in
 * addition to the flat department-less employee/hod every other test in
 * this file uses) and PurchaseRequest::visibleToDepartment() — the
 * model_callback escape hatch standing in for "same department as me",
 * which the no-code 'owner'/'all' scopes can't express on their own.
 */
function republishWithDepartmentScopedHod(): void
{
    republishWithVisibilityRules([
        ['actor_rule' => ['roles' => ['finance', 'operations', 'marketing', 'ceo'], 'match' => 'any'], 'scope' => 'all'],
        ['actor_rule' => ['roles' => ['hod'], 'match' => 'any'], 'scope' => 'model_callback', 'model_callback' => 'visibleToDepartment'],
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'requester_id'],
    ]);
}

it('scopes a department HOD to only their own department\'s requests', function () {
    republishWithDepartmentScopedHod();

    $marketingEmployee = User::where('email', 'employee-marketing@purchase-request-demo.test')->first();
    $technicalEmployee = User::where('email', 'employee-technical@purchase-request-demo.test')->first();
    $marketingHod = User::where('email', 'hod-marketing@purchase-request-demo.test')->first();

    $marketingRequest = PurchaseRequest::create(['requester_id' => $marketingEmployee->id, 'amount' => 10, 'purpose' => 'Marketing department request']);
    $technicalRequest = PurchaseRequest::create(['requester_id' => $technicalEmployee->id, 'amount' => 20, 'purpose' => 'Technical department request']);

    $response = $this->actingAs($marketingHod)->postJson('/app/purchase-requests/search')->assertOk();
    $visiblePurposes = collect($response->json('data'))->flatten()->implode(' ');

    expect($response->json('recordsTotal'))->toBe(1);
    expect($visiblePurposes)->toContain($marketingRequest->purpose);
    expect($visiblePurposes)->not->toContain($technicalRequest->purpose);
});

it('a department HOD never sees another department\'s requests, even when asking for everything', function () {
    republishWithDepartmentScopedHod();

    $technicalEmployee = User::where('email', 'employee-technical@purchase-request-demo.test')->first();
    $technicalHod = User::where('email', 'hod-technical@purchase-request-demo.test')->first();
    PurchaseRequest::create(['requester_id' => $technicalEmployee->id, 'amount' => 30, 'purpose' => 'Technical department request 2']);

    $operationsEmployee = User::where('email', 'employee-operations@purchase-request-demo.test')->first();
    PurchaseRequest::create(['requester_id' => $operationsEmployee->id, 'amount' => 40, 'purpose' => 'Operations department request']);

    $response = $this->actingAs($technicalHod)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(
        PurchaseRequest::where('requester_id', $technicalEmployee->id)->count()
    );
});

it('the flat, department-less hod sees nothing under department scoping — they have no department to match', function () {
    republishWithDepartmentScopedHod();

    $hod = User::where('email', 'hod@purchase-request-demo.test')->first();

    $response = $this->actingAs($hod)->postJson('/app/purchase-requests/search')->assertOk();

    expect($response->json('recordsTotal'))->toBe(0);
});
