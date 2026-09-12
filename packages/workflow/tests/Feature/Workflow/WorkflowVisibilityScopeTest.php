<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\WorkflowVisibilityScope;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrateWorkflowDatabase();
    $this->scope = app(WorkflowVisibilityScope::class);
});

function definitionForVisibility(string $slug, array $visibilityRules = []): WorkflowDefinition
{
    $graph = ['start' => 'draft', 'nodes' => [['id' => 'draft', 'type' => 'state']], 'edges' => []];

    if ($visibilityRules) {
        $graph['visibility_rules'] = $visibilityRules;
    }

    $definition = WorkflowDefinition::create(['name' => 'vis-test', 'slug' => $slug, 'model' => User::class]);
    $version = $definition->versions()->create(['version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $definition->update(['published_version_id' => $version->id]);

    return $definition->fresh();
}

it('leaves the query untouched when no visibility_rules are configured at all', function () {
    definitionForVisibility('vis-none-test');
    $a = User::factory()->create();
    $b = User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $a);

    expect($query->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('sees everything when the matching rule\'s scope is "all"', function () {
    definitionForVisibility('vis-all-test', [
        ['actor_rule' => ['roles' => ['finance'], 'match' => 'any'], 'scope' => 'all'],
    ]);
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
    $finance = User::factory()->create();
    $finance->assignRole('finance');
    $other = User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $finance);

    expect($query->pluck('id')->sort()->values()->all())->toBe(collect([$finance->id, $other->id])->sort()->values()->all());
});

it('sees only its own row when the matching rule\'s scope is "owner"', function () {
    definitionForVisibility('vis-owner-test', [
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'id'],
    ]);
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
    $employee = User::factory()->create();
    $employee->assignRole('employee');
    User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $employee);

    expect($query->pluck('id')->all())->toBe([$employee->id]);
});

it('applies a model_callback scope for anything the no-code scopes can\'t express', function () {
    $class = new class extends User
    {
        protected $table = 'users';

        public function visibleToDemo($query, $actor)
        {
            $query->where('name', 'like', 'Demo%');
        }
    };

    $definition = definitionForVisibility('vis-callback-test', [
        ['scope' => 'model_callback', 'model_callback' => 'visibleToDemo'],
    ]);
    $definition->update(['model' => get_class($class)]);

    $demoUser = User::factory()->create(['name' => 'Demo User']);
    User::factory()->create(['name' => 'Someone Else']);
    $actor = User::factory()->create();

    $query = $class::query();
    $this->scope->apply($query, get_class($class), $actor);

    expect($query->pluck('id')->all())->toBe([$demoUser->id]);
});

it('denies by default (sees nothing) when the configured model_callback method does not exist', function () {
    definitionForVisibility('vis-missing-callback-test', [
        ['scope' => 'model_callback', 'model_callback' => 'thisMethodDoesNotExist'],
    ]);
    $actor = User::factory()->create();
    User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $actor);

    expect($query->count())->toBe(0);
});

it('denies by default (sees nothing) for an unrecognized scope value', function () {
    definitionForVisibility('vis-unknown-scope-test', [
        ['scope' => 'nonsense'],
    ]);
    $actor = User::factory()->create();
    User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $actor);

    expect($query->count())->toBe(0);
});

it('denies by default (sees nothing) when the actor matches none of the configured rules', function () {
    definitionForVisibility('vis-nomatch-test', [
        ['actor_rule' => ['roles' => ['finance'], 'match' => 'any'], 'scope' => 'all'],
    ]);
    $plainUser = User::factory()->create(); // no roles at all
    User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $plainUser);

    expect($query->count())->toBe(0);
});

it('evaluates rules in order — the first actor_rule match wins, not a later broader one', function () {
    definitionForVisibility('vis-order-test', [
        ['actor_rule' => ['roles' => ['employee'], 'match' => 'any'], 'scope' => 'owner', 'owner_field' => 'id'],
        ['actor_rule' => ['roles' => ['finance'], 'match' => 'any'], 'scope' => 'all'],
    ]);
    $roleModel = config('backpack.permissionmanager.models.role');
    $roleModel::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
    $roleModel::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
    $actor = User::factory()->create();
    $actor->assignRole(['employee', 'finance']); // matches BOTH rules
    User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $actor);

    // The first (owner) rule applies, not the second (all) one.
    expect($query->pluck('id')->all())->toBe([$actor->id]);
});

it('a rule with no actor_rule at all matches any actor, as a catch-all', function () {
    definitionForVisibility('vis-catchall-test', [
        ['scope' => 'all'],
    ]);
    $a = User::factory()->create();
    $b = User::factory()->create();

    $query = User::query();
    $this->scope->apply($query, User::class, $a);

    expect($query->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});
