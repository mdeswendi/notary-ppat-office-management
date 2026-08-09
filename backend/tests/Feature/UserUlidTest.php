<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('gives every user a generated ULID primary key', function (): void {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string')
        ->and($user->getIncrementing())->toBeFalse()
        ->and($user->id)->toBeString()
        ->and(strlen($user->id))->toBe(26)
        ->and(Str::isUlid($user->id))->toBeTrue();
});

it('gives distinct users distinct identifiers', function (): void {
    $ids = User::factory()->count(3)->create()->pluck('id');

    expect($ids->unique())->toHaveCount(3);
});

it('returns the identifier as a JSON string rather than a number', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    // Decoding without assoc arrays keeps the JSON type distinction: a bigint
    // key would arrive as an int here and quietly break clients that treat the
    // identifier as opaque.
    $decoded = json_decode($response->getContent());

    expect($decoded->data->id)->toBeString()
        ->and($decoded->data->id)->toBe($user->id)
        ->and(Str::isUlid($decoded->data->id))->toBeTrue();

    expect($response->getContent())->toContain('"id":"'.$user->id.'"');
});

it('resolves a user by its ULID', function (): void {
    $user = User::factory()->create();

    expect(User::query()->find($user->id)?->email)->toBe($user->email);
});
