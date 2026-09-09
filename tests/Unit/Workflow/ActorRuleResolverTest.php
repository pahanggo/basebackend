<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Workflow\Support\ActorRuleResolver;

beforeEach(function () {
    $this->resolver = new ActorRuleResolver;
});

function fakeActor(int $id, array $roles = [], array $permissions = []): Authenticatable
{
    return new class($id, $roles, $permissions) implements Authenticatable
    {
        public function __construct(protected int $id, protected array $roles, protected array $permissions)
        {
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void
        {
        }

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }

        public function hasAnyRole(array $roles): bool
        {
            return (bool) array_intersect($roles, $this->roles);
        }

        public function hasAnyPermission(array $permissions): bool
        {
            return (bool) array_intersect($permissions, $this->permissions);
        }
    };
}

it('allows anyone when the rule is empty', function () {
    expect($this->resolver->allows(null, null))->toBeTrue();
    expect($this->resolver->allows([], fakeActor(1)))->toBeTrue();
});

it('denies a guest when a rule is declared', function () {
    expect($this->resolver->allows(['roles' => ['hod']], null))->toBeFalse();
});

it('matches by role', function () {
    $actor = fakeActor(1, roles: ['hod']);

    expect($this->resolver->allows(['roles' => ['hod']], $actor))->toBeTrue();
    expect($this->resolver->allows(['roles' => ['finance']], $actor))->toBeFalse();
});

it('matches by permission', function () {
    $actor = fakeActor(1, permissions: ['approve-purchase-request']);

    expect($this->resolver->allows(['permissions' => ['approve-purchase-request']], $actor))->toBeTrue();
    expect($this->resolver->allows(['permissions' => ['reject-purchase-request']], $actor))->toBeFalse();
});

it('matches a specifically named user', function () {
    $actor = fakeActor(42);

    expect($this->resolver->allows(['users' => [42]], $actor))->toBeTrue();
    expect($this->resolver->allows(['users' => [7]], $actor))->toBeFalse();
});

it('combines role and named user with any (default) match', function () {
    $actor = fakeActor(1, roles: []);

    expect($this->resolver->allows(['roles' => ['hod'], 'users' => [1]], $actor))->toBeTrue();
});

it('requires every check to pass with an all match', function () {
    $actor = fakeActor(1, roles: ['hod']);

    expect($this->resolver->allows(['roles' => ['hod'], 'permissions' => ['approve'], 'match' => 'all'], $actor))->toBeFalse();

    $actor = fakeActor(1, roles: ['hod'], permissions: ['approve']);
    expect($this->resolver->allows(['roles' => ['hod'], 'permissions' => ['approve'], 'match' => 'all'], $actor))->toBeTrue();
});

it('builds pending actor rows for the my tasks widget', function () {
    $rows = $this->resolver->pendingActorRows(['roles' => [1, 2], 'permissions' => [5], 'users' => [9]]);

    expect($rows)->toEqual([
        ['actor_type' => 'role', 'actor_id' => 1],
        ['actor_type' => 'role', 'actor_id' => 2],
        ['actor_type' => 'permission', 'actor_id' => 5],
        ['actor_type' => 'user', 'actor_id' => 9],
    ]);
});
