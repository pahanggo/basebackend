<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Workflow\Models\WorkflowDefinition;
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

it('seeds a role and demo user for every role in the graph, and a published definition', function () {
    foreach (PurchaseRequestDemoSeeder::ROLES as $role) {
        expect($this->users[$role])->not->toBeNull("missing demo user for role [{$role}]");
        expect($this->users[$role]->hasRole($role))->toBeTrue();
    }

    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    expect($definition)->not->toBeNull();
    expect($definition->publishedVersion)->not->toBeNull();
});

it('seeds sample purchase requests already sitting in draft with an active instance', function () {
    expect(PurchaseRequest::count())->toBeGreaterThanOrEqual(2);

    $sample = PurchaseRequest::first();
    expect($sample->workflowInstance())->not->toBeNull();
    expect($sample->workflowInstance()->activeTokens()->first()->node_id)->toBe('draft');
});

it('renders the sample header_view/footer_view wired onto pending_hod_review on the show-workflow page', function () {
    $request = PurchaseRequest::create(['requester_id' => $this->users['employee']->id, 'amount' => 6000, 'purpose' => 'Header/footer demo test']);
    $token = $request->workflowInstance()->activeTokens()->first();
    $this->engine->transition($token, 'submit', [], $this->users['employee']);

    $this->actingAs($this->users['hod'])
        ->get(route('workflow.show', ['workflowable_type' => PurchaseRequest::class, 'workflowable_id' => $request->id]))
        ->assertOk()
        ->assertSee($this->users['employee']->name, false)
        ->assertSee('RM 6,000.00', false)
        ->assertSee('this one qualifies', false);
});

it('runs the skip-feedback happy path from draft to approved, enforcing each role\'s actor_rule', function () {
    Notification::fake();

    // HasWorkflow::bootHasWorkflow() starts the instance automatically on create.
    $request = PurchaseRequest::create(['requester_id' => $this->users['employee']->id, 'amount' => 100, 'purpose' => 'Test happy path']);
    $instance = $request->workflowInstance();

    $token = $instance->activeTokens()->first();
    expect($this->engine->transition($token, 'submit', [], $this->users['hod']))->toBeNull(); // wrong role

    $this->engine->transition($token, 'submit', [], $this->users['employee']);
    $token = $instance->fresh()->activeTokens()->first();
    expect($token->node_id)->toBe('pending_hod_review');

    $this->engine->transition($token, 'hod_skip_feedback', [], $this->users['hod']);
    $token = $instance->fresh()->activeTokens()->first();
    expect($token->node_id)->toBe('pending_finance_review');

    $this->engine->transition($token, 'finance_approve', [], $this->users['finance']);
    $token = $instance->fresh()->activeTokens()->first();
    expect($token->node_id)->toBe('pending_ceo_review');

    $this->engine->transition($token, 'ceo_approve', [], $this->users['ceo']);
    expect($instance->fresh()->activeTokens()->first()->node_id)->toBe('approved');
});

it('forks into three department-feedback branches and only proceeds once all three submit, mapping feedback via store_as', function () {
    $request = PurchaseRequest::create(['requester_id' => $this->users['employee']->id, 'amount' => 5000, 'purpose' => 'Test fork/join path']);
    $instance = $request->workflowInstance();

    $token = $instance->activeTokens()->first();
    $this->engine->transition($token, 'submit', [], $this->users['employee']);
    $token = $instance->fresh()->activeTokens()->first();
    $this->engine->transition($token, 'hod_request_feedback', [], $this->users['hod']);

    $branchNodes = $instance->fresh()->activeTokens()->pluck('node_id')->sort()->values()->all();
    expect($branchNodes)->toBe(['marketing_feedback', 'operations_feedback', 'technical_feedback']);

    $marketingToken = $instance->fresh()->activeTokens()->where('node_id', 'marketing_feedback')->first();
    $this->engine->transition($marketingToken, 'marketing_done', ['feedback' => 'Marketing OK'], $this->users['marketing']);

    $technicalToken = $instance->fresh()->activeTokens()->where('node_id', 'technical_feedback')->first();
    $this->engine->transition($technicalToken, 'technical_done', ['feedback' => 'Technical OK'], $this->users['technical']);

    // Only 2 of 3 branches done — the join must not have completed yet.
    expect($instance->fresh()->activeTokens()->pluck('node_id')->all())->toContain('operations_feedback');

    $operationsToken = $instance->fresh()->activeTokens()->where('node_id', 'operations_feedback')->first();
    $this->engine->transition($operationsToken, 'operations_done', ['feedback' => 'Operations OK'], $this->users['operations']);

    expect($instance->fresh()->activeTokens()->first()->node_id)->toBe('pending_finance_review');

    $request->refresh();
    expect($request->marketing_feedback)->toBe('Marketing OK');
    expect($request->technical_feedback)->toBe('Technical OK');
    expect($request->operations_feedback)->toBe('Operations OK');
});
