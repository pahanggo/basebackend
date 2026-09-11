<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the model\'s own columns, excluding id and timestamps', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)
        ->getJson(route('workflow.models.fields', ['model' => User::class]))
        ->assertOk();

    $fields = collect($response->json('fields'))->pluck('field');

    expect($fields)->toContain('name')->toContain('email')
        ->not->toContain('id')->not->toContain('created_at')->not->toContain('updated_at');
    expect($response->json('model_table'))->toBe((new User)->getTable());

    $nameField = collect($response->json('fields'))->firstWhere('field', 'name');
    expect($nameField['table'])->toBe((new User)->getTable());
});

it('rejects a class that is not a recognized app model', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.models.fields', ['model' => \stdClass::class]))
        ->assertOk()
        ->assertJson(['fields' => []]);
});

it('rejects a missing model parameter', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.models.fields'))
        ->assertOk()
        ->assertJson(['fields' => []]);
});
