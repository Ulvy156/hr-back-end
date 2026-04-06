<?php

use App\Models\Employee;
use Database\Seeders\HrmsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the base demo employees plus fifty additional employees', function () {
    $this->seed(HrmsDemoSeeder::class);

    expect(Employee::query()->count())->toBe(57)
        ->and(Employee::query()->where('email', 'demo.employee.01@example.com')->exists())->toBeTrue()
        ->and(Employee::query()->where('email', 'demo.employee.50@example.com')->exists())->toBeTrue();
});
