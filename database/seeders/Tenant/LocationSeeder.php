<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\Country;
use App\Models\Tenant\District;
use App\Models\Tenant\Region;
use App\Models\Tenant\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LocationSeeder extends Seeder
{
    private const DATA_DIRECTORY = 'database/seeders/Tenant/data/locations';
    private const DATA_FILES = [
        'countries' => ['countries.json', 'countries.php'],
        'regions' => ['regions.json', 'regions.php'],
        'districts' => ['districts.json', 'districts.php'],
        'wards' => ['wards.json', 'wards.php'],
    ];
    private const UPSERT_CHUNK_SIZE = 500;

    public function run(): void
    {
        $directory = base_path(self::DATA_DIRECTORY);

        if (!$this->hasAnyLocationFile($directory)) {
            $this->command?->warn('Tenant location seed skipped: no location import files were found.');

            return;
        }

        $this->assertRequiredFilesExist($directory);

        DB::transaction(function () use ($directory): void {
            $countries = $this->loadRecords($directory, 'countries');
            $regions = $this->loadRecords($directory, 'regions');
            $districts = $this->loadRecords($directory, 'districts');
            $wards = $this->loadRecords($directory, 'wards');

            $countryMap = $this->seedCountries($countries);
            $regionMap = $this->seedRegions($regions, $countryMap);
            $districtMap = $this->seedDistricts($districts, $regionMap);

            $this->seedWards($wards, $districtMap);
        });
    }

    private function hasAnyLocationFile(string $directory): bool
    {
        foreach (self::DATA_FILES as $candidateFiles) {
            foreach ($candidateFiles as $file) {
                if (File::exists($directory.DIRECTORY_SEPARATOR.$file)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertRequiredFilesExist(string $directory): void
    {
        $missingFiles = [];

        foreach (self::DATA_FILES as $dataset => $candidateFiles) {
            if ($this->resolveDataFilePath($directory, $dataset) === null) {
                $missingFiles[] = sprintf('%s [%s]', $dataset, implode(' or ', $candidateFiles));
            }
        }

        if ($missingFiles === []) {
            return;
        }

        throw new RuntimeException('Tenant location seed is incomplete. Missing files: '.implode(', ', $missingFiles));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecords(string $directory, string $dataset): array
    {
        $path = $this->resolveDataFilePath($directory, $dataset);

        if ($path === null) {
            throw new RuntimeException("Location import file for [{$dataset}] was not found.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $payload = require $path;

            if (!is_array($payload)) {
                throw new RuntimeException("Invalid location PHP payload in {$dataset}.");
            }

            return array_values($payload);
        }

        $decoded = json_decode(File::get($path), true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid location JSON payload in {$dataset}.");
        }

        return array_values($decoded);
    }

    private function resolveDataFilePath(string $directory, string $dataset): ?string
    {
        $candidateFiles = self::DATA_FILES[$dataset] ?? null;

        if ($candidateFiles === null) {
            return null;
        }

        foreach ($candidateFiles as $file) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;

            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, int>
     */
    private function seedCountries(array $records): array
    {
        $timestamp = now();
        $payload = [];

        foreach ($records as $index => $record) {
            $legacyId = $this->nullableInt($record['id'] ?? null) ?? ($index + 1);
            $name = $this->normalizeName($record['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $payload[] = [
                'legacy_id' => $legacyId,
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'dial_code' => $this->normalizeText($record['dial_code'] ?? null),
                'code' => $this->normalizeCode($record['code'] ?? null),
                'status' => 'active',
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null, $timestamp),
                'updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null, $timestamp),
            ];
        }

        $this->upsertByLegacyId('countries', $payload, ['name', 'dial_code', 'code', 'status', 'updated_at']);

        return Country::query()
            ->whereIn('legacy_id', array_column($payload, 'legacy_id'))
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, int> $countryMap
     * @return array<int, int>
     */
    private function seedRegions(array $records, array $countryMap): array
    {
        $timestamp = now();
        $payload = [];

        foreach ($records as $record) {
            $legacyId = $this->nullableInt($record['id'] ?? null);
            $legacyCountryId = $this->nullableInt($record['country_id'] ?? null);
            $name = $this->normalizeName($record['name'] ?? null);

            if ($legacyId === null || $legacyCountryId === null || $name === null) {
                continue;
            }

            $countryId = $countryMap[$legacyCountryId] ?? null;
            if ($countryId === null) {
                throw new RuntimeException("Region import failed: country legacy_id {$legacyCountryId} was not found.");
            }

            $payload[] = [
                'legacy_id' => $legacyId,
                'uuid' => (string) Str::uuid(),
                'country_id' => $countryId,
                'name' => $name,
                'post_code' => $this->nullableInt($record['post_code'] ?? null),
                'status' => 'active',
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null, $timestamp),
                'updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null, $timestamp),
            ];
        }

        $this->upsertByLegacyId('regions', $payload, ['country_id', 'name', 'post_code', 'status', 'updated_at']);

        return Region::query()
            ->whereIn('legacy_id', array_column($payload, 'legacy_id'))
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, int> $regionMap
     * @return array<int, int>
     */
    private function seedDistricts(array $records, array $regionMap): array
    {
        $timestamp = now();
        $payload = [];

        foreach ($records as $record) {
            $legacyId = $this->nullableInt($record['id'] ?? null);
            $legacyRegionId = $this->nullableInt($record['region_id'] ?? null);
            $name = $this->normalizeName($record['name'] ?? null);

            if ($legacyId === null || $legacyRegionId === null || $name === null) {
                continue;
            }

            $regionId = $regionMap[$legacyRegionId] ?? null;
            if ($regionId === null) {
                throw new RuntimeException("District import failed: region legacy_id {$legacyRegionId} was not found.");
            }

            $payload[] = [
                'legacy_id' => $legacyId,
                'uuid' => (string) Str::uuid(),
                'region_id' => $regionId,
                'name' => $name,
                'post_code' => $this->nullableInt($record['post_code'] ?? null),
                'status' => 'active',
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null, $timestamp),
                'updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null, $timestamp),
            ];
        }

        $this->upsertByLegacyId('districts', $payload, ['region_id', 'name', 'post_code', 'status', 'updated_at']);

        return District::query()
            ->whereIn('legacy_id', array_column($payload, 'legacy_id'))
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, int> $districtMap
     */
    private function seedWards(array $records, array $districtMap): void
    {
        $timestamp = now();
        $payload = [];

        foreach ($records as $record) {
            $legacyId = $this->nullableInt($record['id'] ?? null);
            $legacyDistrictId = $this->nullableInt($record['district_id'] ?? null);
            $name = $this->normalizeName($record['name'] ?? null);

            if ($legacyId === null || $legacyDistrictId === null || $name === null) {
                continue;
            }

            $districtId = $districtMap[$legacyDistrictId] ?? null;
            if ($districtId === null) {
                throw new RuntimeException("Ward import failed: district legacy_id {$legacyDistrictId} was not found.");
            }

            $payload[] = [
                'legacy_id' => $legacyId,
                'uuid' => (string) Str::uuid(),
                'district_id' => $districtId,
                'name' => $name,
                'post_code' => $this->nullableInt($record['post_code'] ?? null),
                'status' => 'active',
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null, $timestamp),
                'updated_at' => $this->normalizeTimestamp($record['updated_at'] ?? null, $timestamp),
            ];
        }

        $this->upsertByLegacyId('wards', $payload, ['district_id', 'name', 'post_code', 'status', 'updated_at']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $updateColumns
     */
    private function upsertByLegacyId(string $table, array $rows, array $updateColumns): void
    {
        if ($rows === []) {
            return;
        }

        Collection::make($rows)
            ->chunk(self::UPSERT_CHUNK_SIZE)
            ->each(function (Collection $chunk) use ($table, $updateColumns): void {
                DB::table($table)->upsert($chunk->all(), ['legacy_id'], $updateColumns);
            });
    }

    private function normalizeName(mixed $value): ?string
    {
        return $this->normalizeText($value);
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCode(mixed $value): ?string
    {
        $code = $this->normalizeText($value);

        return $code === null ? null : Str::upper($code);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeTimestamp(mixed $value, mixed $fallback): mixed
    {
        $timestamp = $this->normalizeText($value);

        return $timestamp ?? $fallback;
    }
}
