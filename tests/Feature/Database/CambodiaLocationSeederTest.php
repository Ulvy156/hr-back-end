<?php

use Database\Seeders\CambodiaLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds the cambodia location hierarchy from the provided sql dumps', function () {
    $this->seed(CambodiaLocationSeeder::class);

    $province = DB::table('provinces')->where('code', '01')->first();
    $district = DB::table('districts')->where('code', '102')->first();
    $commune = DB::table('communes')->where('code', '10201')->first();
    $village = DB::table('villages')->where('code', '1020101')->first();

    expect(DB::table('provinces')->count())->toBe(25)
        ->and(DB::table('districts')->count())->toBe(203)
        ->and(DB::table('communes')->count())->toBe(1646)
        ->and(DB::table('villages')->count())->toBe(14372)
        ->and($province)->not->toBeNull()
        ->and($province->name_en)->toBe('Banteay Meanchey')
        ->and($district)->not->toBeNull()
        ->and($district->province_id)->toBe($province->id)
        ->and($district->name_en)->toBe('Mongkol Borei')
        ->and($commune)->not->toBeNull()
        ->and($commune->district_id)->toBe($district->id)
        ->and($commune->name_en)->toBe('Banteay Neang')
        ->and($village)->not->toBeNull()
        ->and($village->commune_id)->toBe($commune->id)
        ->and($village->name_en)->toBe('Ou Thum');
});
