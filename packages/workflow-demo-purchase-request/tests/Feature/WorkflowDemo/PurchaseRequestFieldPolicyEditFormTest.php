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
 * Proves WorkflowServiceProvider::applyFieldPolicyToEditForm() actually
 * reaches a real downstream CrudController's update form — this is the
 * fix for the bug report "changed field position, doesn't reflect": the
 * engine's own version-resolution (HasWorkflow::setEnableVersioning(),
 * WorkflowInstance::effectiveVersion()) was already correct, but nothing
 * ever applied a node's field_policy to the Backpack edit form itself.
 */
it('reorders and hides the update form fields per the current node\'s field_policy, with no per-controller wiring needed', function () {
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;

    foreach ($graph['nodes'] as &$node) {
        if ($node['id'] === 'draft') {
            $node['field_policy'] = [
                ['field' => 'amount', 'visible' => true, 'label' => 'Amount (reordered first)'],
                ['field' => 'purpose', 'visible' => false],
            ];
        }
    }
    unset($node);

    $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $definition->versions()->latest('version')->first()->id]);

    // The republished graph still carries 'draft's own row_actions override
    // (copied over from $graph verbatim), which only re-enables Update for
    // the request's own requester — a bare no-role admin would now 403.
    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $sample = PurchaseRequest::first(); // freshly seeded, sits in 'draft'

    $response = $this->actingAs($employee)->get("/app/purchase-requests/{$sample->id}/edit")->assertOk();

    $response->assertSee('Amount (reordered first)');
    $response->assertDontSee('name="purpose"', false);

    // "Amount (reordered first)" must appear before the workflow field —
    // originally added right after the create fields (requester_id, purpose,
    // amount) — proving the reorder (not just the relabel) actually took effect.
    $content = $response->getContent();
    $amountPos = strpos($content, 'Amount (reordered first)');
    $workflowFieldPos = strpos($content, 'data-field-type="workflow"');
    expect($amountPos)->not->toBeFalse();
    expect($workflowFieldPos)->not->toBeFalse();
    expect($amountPos)->toBeLessThan($workflowFieldPos);
});
