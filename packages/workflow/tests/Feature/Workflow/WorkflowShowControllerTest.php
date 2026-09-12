<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->admin = User::factory()->create();

    // Real, findable view names for header_view/footer_view — @include()
    // needs a view the finder can resolve, not a raw string, so these are
    // written to a temp directory registered as an extra view location for
    // the duration of each test.
    $this->viewsDir = sys_get_temp_dir().'/workflow-show-test-'.uniqid();
    mkdir($this->viewsDir);
    file_put_contents($this->viewsDir.'/header.blade.php', '<div id="wf-test-header">Header for {{ $entry->name }}</div>');
    file_put_contents($this->viewsDir.'/footer.blade.php', '<div id="wf-test-footer">Footer content</div>');
    View::addLocation($this->viewsDir);
});

afterEach(function () {
    array_map('unlink', glob($this->viewsDir.'/*'));
    rmdir($this->viewsDir);
});

function definitionForShowEndpoint(string $slug, array $nodeOverrides = []): WorkflowDefinition
{
    $graph = [
        'start' => 'draft',
        'nodes' => [
            array_merge(['id' => 'draft', 'type' => 'state', 'name' => 'Draft'], $nodeOverrides),
            ['id' => 'approved', 'type' => 'state', 'name' => 'Approved'],
        ],
        'edges' => [
            ['id' => 'approve', 'from' => 'draft', 'to' => 'approved', 'trigger' => 'manual', 'name' => 'Approve'],
        ],
    ];

    $definition = WorkflowDefinition::create(['name' => 'show-endpoint-test', 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('rejects a workflowable_type that does not use HasWorkflow', function () {
    $this->actingAs($this->admin)
        ->get(route('workflow.show', ['workflowable_type' => \stdClass::class, 'workflowable_id' => 1]))
        ->assertNotFound();
});

it('renders the header_view, field-policy columns, footer_view, and available transitions', function () {
    definitionForShowEndpoint('show-endpoint-columns-test', [
        'header_view' => 'header',
        'footer_view' => 'footer',
        'field_policy' => [
            ['field' => 'name', 'label' => 'Full name', 'visible' => true],
            ['field' => 'email', 'visible' => true],
            ['field' => 'username', 'visible' => false],
        ],
    ]);

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'show-endpoint-columns-test';
        }
    };

    $model = $class::create([
        'name' => 'Jane Doe',
        'username' => 'hidden-username-marker',
        'email' => 'jane@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->actingAs($this->admin)
        ->get(route('workflow.show', ['workflowable_type' => get_class($model), 'workflowable_id' => $model->id]))
        ->assertOk()
        ->assertSee('Header for Jane Doe', false)
        ->assertSee('Footer content')
        ->assertSee('Full name')
        ->assertSee('Jane Doe')
        ->assertSee('jane@example.com')
        ->assertDontSee('hidden-username-marker')
        ->assertSee('Approve');
});

it('threads a local return_to into each transition form as a hidden field', function () {
    definitionForShowEndpoint('show-endpoint-return-to-test');

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'show-endpoint-return-to-test';
        }
    };
    $model = $class::create(['name' => 'Return To', 'username' => 'return-to', 'email' => 'return-to@example.com', 'password' => bcrypt('password')]);

    $this->actingAs($this->admin)
        ->get(route('workflow.show', [
            'workflowable_type' => get_class($model),
            'workflowable_id' => $model->id,
            'return_to' => url('/app/some-list-page'),
        ]))
        ->assertOk()
        ->assertSee('name="return_to" value="'.url('/app/some-list-page').'"', false);
});

it('drops a return_to pointing at a different host instead of threading it through', function () {
    definitionForShowEndpoint('show-endpoint-unsafe-return-to-test');

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'show-endpoint-unsafe-return-to-test';
        }
    };
    $model = $class::create(['name' => 'Unsafe', 'username' => 'unsafe-return-to', 'email' => 'unsafe-return-to@example.com', 'password' => bcrypt('password')]);

    // The base layout's own sidebar-menu-active-state script separately
    // echoes the *current request's raw URL* (query string included) for
    // unrelated highlighting purposes — that's expected and harmless (never
    // used as a redirect target), so assert on the one thing that actually
    // matters: no `return_to` hidden field carries the unsafe value through
    // to the transition forms themselves.
    $this->actingAs($this->admin)
        ->get(route('workflow.show', [
            'workflowable_type' => get_class($model),
            'workflowable_id' => $model->id,
            'return_to' => 'https://evil.example.com/phishing',
        ]))
        ->assertOk()
        ->assertDontSee('name="return_to" value="https://evil.example.com', false);
});

it('shows a message instead of columns when the record has no active workflow instance', function () {
    definitionForShowEndpoint('show-endpoint-inactive-test');

    $class = new class extends User
    {
        use \Workflow\HasWorkflow;

        protected $table = 'users';

        public function workflowDefinitionSlug(): string
        {
            return 'show-endpoint-inactive-test';
        }
    };

    // A plain find()-wrapped row never auto-starts a workflow (see HasWorkflowTest).
    $existing = User::factory()->create();
    $wrapped = $class::find($existing->id);

    $this->actingAs($this->admin)
        ->get(route('workflow.show', ['workflowable_type' => get_class($wrapped), 'workflowable_id' => $wrapped->id]))
        ->assertOk()
        ->assertSee('This record has no active workflow instance.');
});
