<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CambodiaLocationSeeder extends Seeder
{
    private const UPSERT_CHUNK_SIZE = 1000;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedProvinces();
            $this->seedDistricts();
            $this->seedCommunes();
            $this->seedVillages();
        });
    }

    private function seedProvinces(): void
    {
        $rows = array_map(
            fn (array $row): array => [
                'source_id' => (int) $row['id'],
                'code' => (string) $row['pro_id'],
                'name_kh' => (string) $row['pro_khname'],
                'name_en' => (string) $row['pro_name'],
            ],
            $this->parseSqlFile(database_path('data/locations/province.sql'))
        );

        DB::table('provinces')->upsert(
            $rows,
            ['source_id'],
            ['code', 'name_kh', 'name_en']
        );
    }

    private function seedDistricts(): void
    {
        $provinceIdsBySourceId = DB::table('provinces')->pluck('id', 'source_id')->all();

        $sourceRows = array_filter(
            $this->parseSqlFile(database_path('data/locations/district.sql')),
            fn (array $row): bool => $row['dis_id'] !== null
                && trim($row['dis_id']) !== ''
                && $row['province_id'] !== null
                && (int) $row['province_id'] > 0
        );

        $rows = array_map(function (array $row) use ($provinceIdsBySourceId): array {
            $provinceId = $provinceIdsBySourceId[(int) $row['province_id']] ?? null;

            if (! is_numeric($provinceId)) {
                throw new RuntimeException('Unable to map district province reference.');
            }

            return [
                'source_id' => (int) $row['id'],
                'code' => (string) $row['dis_id'],
                'province_id' => (int) $provinceId,
                'name_kh' => (string) $row['dis_khname'],
                'name_en' => (string) $row['dis_name'],
                'type' => $row['type'],
            ];
        }, $sourceRows);

        foreach (array_chunk($rows, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('districts')->upsert(
                $chunk,
                ['source_id'],
                ['code', 'province_id', 'name_kh', 'name_en', 'type']
            );
        }
    }

    private function seedCommunes(): void
    {
        $districtIdsByCode = DB::table('districts')->pluck('id', 'code')
            ->mapWithKeys(fn (int $id, string $code): array => [(int) $code => $id])
            ->all();

        $rows = array_map(function (array $row) use ($districtIdsByCode): array {
            $districtId = $districtIdsByCode[(int) $row['district_id']] ?? null;

            if (! is_numeric($districtId)) {
                throw new RuntimeException('Unable to map commune district reference.');
            }

            return [
                'source_id' => (int) $row['id'],
                'code' => (string) $row['com_id'],
                'district_id' => (int) $districtId,
                'name_kh' => (string) $row['com_khname'],
                'name_en' => (string) $row['com_name'],
            ];
        }, $this->parseSqlFile(database_path('data/locations/commune.sql')));

        foreach (array_chunk($rows, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('communes')->upsert(
                $chunk,
                ['source_id'],
                ['code', 'district_id', 'name_kh', 'name_en']
            );
        }
    }

    private function seedVillages(): void
    {
        $communeIdsByCode = DB::table('communes')->pluck('id', 'code')
            ->mapWithKeys(fn (int $id, string $code): array => [(int) $code => $id])
            ->all();

        $rows = array_map(function (array $row) use ($communeIdsByCode): array {
            $communeId = $communeIdsByCode[(int) $row['commune_id']] ?? null;

            if (! is_numeric($communeId)) {
                throw new RuntimeException('Unable to map village commune reference.');
            }

            return [
                'source_id' => (int) $row['id'],
                'code' => (string) $row['vil_id'],
                'commune_id' => (int) $communeId,
                'name_kh' => (string) $row['vil_khname'],
                'name_en' => (string) $row['vil_name'],
                'is_not_active' => is_null($row['is_not_active']) ? null : (int) $row['is_not_active'] === 1,
            ];
        }, $this->parseSqlFile(database_path('data/locations/village.sql')));

        foreach (array_chunk($rows, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('villages')->upsert(
                $chunk,
                ['source_id'],
                ['code', 'commune_id', 'name_kh', 'name_en', 'is_not_active']
            );
        }
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function parseSqlFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException("Unable to read location seed file [{$path}].");
        }

        $rows = [];
        $columns = null;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            if (str_starts_with($trimmedLine, 'INSERT INTO')) {
                preg_match('/INSERT INTO\s+`[^`]+`\s+\((?<columns>[^)]+)\)\s+VALUES$/', $trimmedLine, $matches);

                if (! isset($matches['columns'])) {
                    throw new RuntimeException("Unable to parse insert statement from [{$path}].");
                }

                $columns = array_map(
                    static fn (string $column): string => trim($column, " `\t\n\r\0\x0B"),
                    explode(',', $matches['columns'])
                );

                continue;
            }

            if ($columns === null || ! str_starts_with($trimmedLine, '(')) {
                continue;
            }

            $tuple = trim(rtrim($trimmedLine, ',;'), '()');
            $values = array_map(
                fn (string $value): ?string => $this->normalizeValue($value),
                str_getcsv($tuple, ',', "'", '\\')
            );

            $rows[] = array_combine($columns, $values);
        }

        if ($rows === []) {
            throw new RuntimeException("Unable to parse insert rows from [{$path}].");
        }

        return $rows;
    }

    private function normalizeValue(string $value): ?string
    {
        $value = trim($value);

        if (strtoupper($value) === 'NULL') {
            return null;
        }

        return $value;
    }
}
