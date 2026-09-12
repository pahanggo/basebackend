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

it('hides the delete icon only for rows currently sitting on a node whose row_actions disables it', function () {
    $definition = WorkflowDefinition::where('slug', 'purchase-request-demo')->first();
    $version = $definition->publishedVersion;
    $graph = $version->graph;

    // Merged into the existing row_actions (not a wholesale replace) so the
    // seeder's own 'update' override for 'draft' (requester-only) survives
    // untouched — this test is specifically about 'delete' in isolation.
    foreach ($graph['nodes'] as &$node) {
        if ($node['id'] === 'draft') {
            $node['row_actions']['delete'] = ['enabled' => false];
        }
    }
    unset($node);

    $newVersion = $definition->versions()->create(['version' => $version->version + 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $newVersion->id]);

    $employee = User::where('email', 'employee@purchase-request-demo.test')->first();
    $response = $this->actingAs($employee)->postJson('/app/purchase-requests/search')->assertOk();

    // Both seeded sample requests sit in 'draft' — neither row should offer delete.
    $rows = collect($response->json('data'))->map(fn (array $row) => end($row));
    $rows->each(fn (string $cell) => expect($cell)->not->toContain('data-button-type="delete"'));

    // But show/update — untouched by the override — still render for the
    // requester (update's own row override still applies to them).
    $rows->each(fn (string $cell) => expect($cell)->toContain('la-eye')->toContain('la-edit'));
});

it('hides delete for everyone, falling back to the definition-level operation_settings when a node declares no delete override of its own', function () {
    // 'draft' only overrides 'update' in the seeded graph — no per-node
    // override for 'delete', so it falls back to the definition-level
    // operation_settings, which the live definition has disabled entirely.
    $admin = User::factory()->create();
    $response = $this->actingAs($admin)->postJson('/app/purchase-requests/search')->assertOk();

    $rows = collect($response->json('data'))->map(fn (array $row) => end($row));
    $rows->each(fn (string $cell) => expect($cell)->not->toContain('data-button-type="delete"'));
});
