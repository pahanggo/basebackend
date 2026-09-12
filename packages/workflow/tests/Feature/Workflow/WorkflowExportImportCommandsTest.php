<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();

    $this->scratchDir = sys_get_temp_dir().'/workflow-export-import-test-'.uniqid();
    mkdir($this->scratchDir);
});

afterEach(function () {
    array_map('unlink', glob($this->scratchDir.'/*'));
    rmdir($this->scratchDir);
});

function simpleGraph(array $overrides = []): array
{
    return array_merge([
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state', 'name' => 'Draft'],
            ['id' => 'done', 'type' => 'state', 'name' => 'Done'],
        ],
        'edges' => [
            ['id' => 'finish', 'from' => 'draft', 'to' => 'done', 'trigger' => 'manual'],
        ],
    ], $overrides);
}

it('exports a published definition\'s graph to a JSON file', function () {
    $definition = WorkflowDefinition::create(['name' => 'Export Test', 'slug' => 'export-test', 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => simpleGraph(), 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    $path = $this->scratchDir.'/out.json';

    $this->artisan('workflow:export', ['slug' => 'export-test', '--path' => $path])->assertSuccessful();

    $decoded = json_decode(file_get_contents($path), true);
    expect($decoded['definition']['slug'])->toBe('export-test');
    expect($decoded['definition']['model'])->toBe(User::class);
    expect($decoded['graph']['nodes'])->toHaveCount(2);
});

it('exports the draft version when --draft is passed, instead of the published one', function () {
    $definition = WorkflowDefinition::create(['name' => 'Export Draft Test', 'slug' => 'export-draft-test', 'model' => User::class]);
    $published = $definition->versions()->create(['version' => 1, 'graph' => simpleGraph(), 'published_at' => now()]);
    $definition->update(['published_version_id' => $published->id]);
    $definition->versions()->create(['version' => null, 'graph' => simpleGraph(['start' => 'done']), 'published_at' => null]);

    $path = $this->scratchDir.'/draft.json';

    $this->artisan('workflow:export', ['slug' => 'export-draft-test', '--path' => $path, '--draft' => true])->assertSuccessful();

    $decoded = json_decode(file_get_contents($path), true);
    expect($decoded['graph']['start'])->toBe('done');
});

it('fails to export a slug that doesn\'t exist', function () {
    $this->artisan('workflow:export', ['slug' => 'does-not-exist'])->assertFailed();
});

it('validates a structurally sound graph', function () {
    $path = $this->scratchDir.'/valid.json';
    file_put_contents($path, json_encode(simpleGraph()));

    $this->artisan('workflow:validate', ['file' => $path])->assertSuccessful();
});

it('catches a dangling edge reference, a duplicate id, and a missing start node', function () {
    $path = $this->scratchDir.'/invalid.json';
    file_put_contents($path, json_encode([
        'start' => 'nowhere',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'draft', 'type' => 'state'], // duplicate
        ],
        'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'ghost', 'trigger' => 'manual'], // dangling
        ],
    ]));

    $this->artisan('workflow:validate', ['file' => $path])->assertFailed();
});

it('catches a join node with no incoming edges', function () {
    $path = $this->scratchDir.'/unreachable-join.json';
    file_put_contents($path, json_encode([
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'joined', 'type' => 'join'],
        ],
        'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'joined', 'trigger' => 'automatic'],
        ],
    ]));

    // 'joined' DOES have one incoming edge here, so this should pass —
    // proves the check isn't a false positive before the next test proves
    // it actually catches the true-negative case.
    $this->artisan('workflow:validate', ['file' => $path])->assertSuccessful();

    file_put_contents($path, json_encode([
        'start' => 'draft',
        'nodes' => [
            ['id' => 'draft', 'type' => 'state'],
            ['id' => 'joined', 'type' => 'join'],
        ],
        'edges' => [], // no edge points at 'joined' at all
    ]));

    $this->artisan('workflow:validate', ['file' => $path])->assertFailed();
});

it('imports a new definition as an unpublished draft by default', function () {
    $path = $this->scratchDir.'/import.json';
    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-new-test', 'name' => 'Import New Test', 'model' => User::class],
        'graph' => simpleGraph(),
    ]));

    $this->artisan('workflow:import', ['file' => $path])->assertSuccessful();

    $definition = WorkflowDefinition::where('slug', 'import-new-test')->first();
    expect($definition)->not->toBeNull();
    expect($definition->publishedVersion)->toBeNull();
    expect($definition->draftVersion())->not->toBeNull();
});

it('publishes immediately when --publish is passed', function () {
    $path = $this->scratchDir.'/import-publish.json';
    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-publish-test', 'name' => 'Import Publish Test', 'model' => User::class],
        'graph' => simpleGraph(),
    ]));

    $this->artisan('workflow:import', ['file' => $path, '--publish' => true])->assertSuccessful();

    $definition = WorkflowDefinition::where('slug', 'import-publish-test')->first();
    expect($definition->publishedVersion)->not->toBeNull();
    expect($definition->publishedVersion->version)->toBe(1);
});

it('re-importing updates the same draft row instead of piling up new ones', function () {
    $path = $this->scratchDir.'/import-reimport.json';
    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-reimport-test', 'name' => 'Import Reimport Test', 'model' => User::class],
        'graph' => simpleGraph(),
    ]));
    $this->artisan('workflow:import', ['file' => $path])->assertSuccessful();

    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-reimport-test', 'name' => 'Import Reimport Test', 'model' => User::class],
        'graph' => simpleGraph(['start' => 'done']),
    ]));
    $this->artisan('workflow:import', ['file' => $path])->assertSuccessful();

    $definition = WorkflowDefinition::where('slug', 'import-reimport-test')->first();
    expect($definition->versions()->count())->toBe(1);
    expect($definition->draftVersion()->graph['start'])->toBe('done');
});

it('refuses to import a structurally invalid graph without --force', function () {
    $path = $this->scratchDir.'/import-invalid.json';
    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-invalid-test', 'name' => 'Import Invalid Test', 'model' => User::class],
        'graph' => ['start' => 'draft', 'nodes' => [['id' => 'draft', 'type' => 'state']], 'edges' => [
            ['id' => 'go', 'from' => 'draft', 'to' => 'ghost', 'trigger' => 'manual'],
        ]],
    ]));

    $this->artisan('workflow:import', ['file' => $path])->assertFailed();
    expect(WorkflowDefinition::where('slug', 'import-invalid-test')->exists())->toBeFalse();

    $this->artisan('workflow:import', ['file' => $path, '--force' => true])->assertSuccessful();
    expect(WorkflowDefinition::where('slug', 'import-invalid-test')->exists())->toBeTrue();
});

it('refuses to import a brand-new definition with no model given', function () {
    $path = $this->scratchDir.'/import-no-model.json';
    file_put_contents($path, json_encode([
        'definition' => ['slug' => 'import-no-model-test', 'name' => 'No Model'],
        'graph' => simpleGraph(),
    ]));

    $this->artisan('workflow:import', ['file' => $path])->assertFailed();
    expect(WorkflowDefinition::where('slug', 'import-no-model-test')->exists())->toBeFalse();
});
