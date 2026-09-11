<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('only returns public methods named callbackFunction*, matching the search term', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['model' => User::class, 'q' => 'callback']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

it('rejects a class that is not a recognized app model', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['model' => \stdClass::class, 'q' => '']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

it('rejects a missing model parameter', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson(route('workflow.model-callbacks.search', ['q' => '']))
        ->assertOk()
        ->assertJson(['results' => []]);
});
