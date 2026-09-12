<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports no errors when every definition is a valid literal array', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('workflow.field-policy.validate-definitions'), [
            'definitions' => [
                0 => "['tab' => 'Details']",
                2 => '',
            ],
        ])
        ->assertOk()
        ->assertJson(['errors' => []]);
});

it('reports an error keyed by index for each invalid definition, leaving valid ones out', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('workflow.field-policy.validate-definitions'), [
            'definitions' => [
                0 => "['tab' => 'Details']",
                1 => "['pwned' => shell_exec('id')]",
            ],
        ])
        ->assertOk();

    $errors = $response->json('errors');

    expect($errors)->toHaveKey('1')->not->toHaveKey('0');
    expect($errors['1'])->toBeString()->not->toBe('');
});

it('treats a missing definitions payload as nothing to validate', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('workflow.field-policy.validate-definitions'), [])
        ->assertOk()
        ->assertJson(['errors' => []]);
});
