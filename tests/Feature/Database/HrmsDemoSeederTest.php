<?php

use App\Models\Employee;
use Database\Seeders\HrmsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the demo employee hierarchy with dedicated leave approvers', function () {
    $this->seed(HrmsDemoSeeder::class);

    $headOfHr = Employee::query()->where('email', 'helen.hr@example.com')->firstOrFail();
    $director = Employee::query()->where('email', 'derek.director@example.com')->firstOrFail();
    $operationsManager = Employee::query()->where('email', 'mark.ops@example.com')->firstOrFail();
    $normalEmployee = Employee::query()->where('email', 'emma.employee@example.com')->firstOrFail();

    expect(Employee::query()->count())->toBe(10)
        ->and($headOfHr->leave_approver_id)->toBe($director->id)
        ->and($operationsManager->leave_approver_id)->toBe($director->id)
        ->and($normalEmployee->leave_approver_id)->toBeNull();
});
