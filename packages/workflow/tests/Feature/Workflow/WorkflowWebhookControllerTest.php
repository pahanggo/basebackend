<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Models\WorkflowInstanceHistory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
});

/**
 * A HasWorkflow-using class over the real `users` table — mirrors the
 * fixture other workflow tests use, since User itself doesn't declare the
 * trait in this app. `$slug` lets each test point at its own definition.
 */
function webhookTestModel(string $slug)
{
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        public static string $slugOverride = '';

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return static::$slugOverride;
        }
    };
    $class::$slugOverride = $slug;

    return $class::create([
        'name' => 'Webhook Test',
        'username' => 'webhook-test-'.$slug,
        'email' => 'webhook-test-'.$slug.'@example.com',
        'password' => bcrypt('password'),
    ]);
}

function definitionForWebhook(string $slug, array $edgeOverrides = []): WorkflowDefinition
{
    $graph = [
        'start' => 'pending',
        'nodes' => [
            ['id' => 'pending', 'type' => 'state'],
            ['id' => 'confirmed', 'type' => 'state'],
        ],
        'edges' => [
            array_merge([
                'id' => 'vendor_confirmed',
                'from' => 'pending',
                'to' => 'confirmed',
                'trigger' => 'webhook',
            ], $edgeOverrides),
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'webhook-test', 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('rejects a request with no signature at all', function () {
    definitionForWebhook('webhook-no-sig-test');
    $model = webhookTestModel('webhook-no-sig-test');
    $instance = $model->workflowInstance();

    $this->postJson("/workflows/webhook/{$instance->id}/vendor_confirmed")
        ->assertForbidden();
});

it('rejects a request whose signature was tampered with', function () {
    definitionForWebhook('webhook-tampered-test');
    $model = webhookTestModel('webhook-tampered-test');

    $url = $model->signedWebhookUrl('vendor_confirmed');

    $this->postJson($url.'&extra=tampered')
        ->assertForbidden();
});

it('fires a webhook-triggered edge given a validly signed url', function () {
    definitionForWebhook('webhook-fire-test');
    $model = webhookTestModel('webhook-fire-test');
    $instance = $model->workflowInstance();

    $url = $model->signedWebhookUrl('vendor_confirmed');

    $response = $this->postJson($url)->assertOk()->json();

    expect($response['ok'])->toBeTrue();
    expect($response['to'])->toBe('confirmed');
    expect($instance->fresh()->activeTokens()->first()->node_id)->toBe('confirmed');

    // Recorded as a system trigger — no actor at all, not just an absent one.
    $history = WorkflowInstanceHistory::where('workflow_instance_id', $instance->id)->latest('id')->first();
    expect($history->trigger)->toBe('webhook');
    expect($history->actor_type)->toBeNull();
    expect($history->actor_id)->toBeNull();
});

it('rejects an edge that is not declared trigger: webhook, even with a valid signature', function () {
    definitionForWebhook('webhook-wrong-trigger-test', ['trigger' => 'manual']);
    $model = webhookTestModel('webhook-wrong-trigger-test');

    $url = $model->signedWebhookUrl('vendor_confirmed');

    $this->postJson($url)
        ->assertStatus(422)
        ->assertJsonFragment(['ok' => false]);

    expect($model->workflowInstance()->fresh()->activeTokens()->first()->node_id)->toBe('pending');
});

it('rejects an edge id that does not exist on the instance\'s graph', function () {
    definitionForWebhook('webhook-missing-edge-test');
    $model = webhookTestModel('webhook-missing-edge-test');
    $instance = $model->workflowInstance();

    $url = \Illuminate\Support\Facades\URL::signedRoute('workflow.webhook', [
        'workflowInstance' => $instance->id,
        'edgeId' => 'does_not_exist',
    ]);

    $this->postJson($url)->assertStatus(404);
});

it('rejects firing an edge that is not available from the instance\'s current node', function () {
    $definition = definitionForWebhook('webhook-wrong-node-test');
    $version = $definition->publishedVersion;
    $graph = $version->graph;
    $graph['nodes'][] = ['id' => 'elsewhere', 'type' => 'state'];
    $graph['edges'][0]['from'] = 'elsewhere'; // edge no longer starts at 'pending'
    $version->update(['graph' => $graph]);

    $model = webhookTestModel('webhook-wrong-node-test');
    $url = $model->signedWebhookUrl('vendor_confirmed');

    $this->postJson($url)
        ->assertStatus(422)
        ->assertJsonFragment(['ok' => false]);
});

it('blocks the transition when a required input is missing, without firing it', function () {
    definitionForWebhook('webhook-required-input-test', [
        'inputs' => [['name' => 'reference', 'type' => 'text', 'required' => true]],
    ]);
    $model = webhookTestModel('webhook-required-input-test');

    $url = $model->signedWebhookUrl('vendor_confirmed');

    $this->postJson($url, [])
        ->assertStatus(422);

    expect($model->workflowInstance()->fresh()->activeTokens()->first()->node_id)->toBe('pending');
});

it('accepts inputs and applies store_as onto the workflowable', function () {
    definitionForWebhook('webhook-store-as-test', [
        'inputs' => [['name' => 'reference', 'type' => 'text', 'required' => true, 'store_as' => 'name']],
    ]);
    $model = webhookTestModel('webhook-store-as-test');

    $url = $model->signedWebhookUrl('vendor_confirmed');

    $this->postJson($url, ['inputs' => ['reference' => 'PAY-999']])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($model->fresh()->name)->toBe('PAY-999');
});

it('signedWebhookUrl returns null when the record has no active workflow instance', function () {
    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'unused';
        }
    };

    $existing = User::factory()->create();
    $wrapped = $class::find($existing->id); // plain find() never auto-starts a workflow

    expect($wrapped->signedWebhookUrl('anything'))->toBeNull();
});
