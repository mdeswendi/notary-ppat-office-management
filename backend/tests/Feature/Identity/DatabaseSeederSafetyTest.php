<?php

use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('keeps the default database seeder empty', function (): void {
    $this->seed();

    expect(Organization::query()->exists())->toBeFalse()
        ->and(Office::query()->exists())->toBeFalse()
        ->and(User::withTrashed()->exists())->toBeFalse();
});

it('does not expose a demo data seed command', function (): void {
    expect(Artisan::all())->not->toHaveKey('demo:seed');
});
