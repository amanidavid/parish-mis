<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\PropertyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyTypeSeeder extends Seeder
{
    /**
     * Property types to keep in the tenant database.
     *
     * @var array<int, array{name: string, legacy_names: array<int, string>}>
     */
    private const PROPERTY_TYPES = [
        [
            'name' => 'Mall/Plaza',
            'legacy_names' => [
                'Commercial',
                'Mixed',
                'Mall',
                'Plaza',
                'Commercial Building',
                'Commercial building',
                'Commercial Complex',
                'Mall/Complex/Plaza',
            ],
        ],
        [
            'name' => 'Apartment',
            'legacy_names' => [
                'Residential',
            ],
        ],
    ];

    /**
     * Seed property types.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $targetTypes = [];

            foreach (self::PROPERTY_TYPES as $definition) {
                $propertyType = $this->resolveTargetType($definition['name'], $definition['legacy_names']);
                $targetTypes[$definition['name']] = $propertyType;
            }

            foreach (self::PROPERTY_TYPES as $definition) {
                $targetType = $targetTypes[$definition['name']];

                $legacyTypes = PropertyType::query()
                    ->whereIn('name', $definition['legacy_names'])
                    ->get();

                foreach ($legacyTypes as $legacyType) {
                    if ((int) $legacyType->id === (int) $targetType->id) {
                        continue;
                    }

                    DB::table('properties')
                        ->where('type_id', $legacyType->id)
                        ->update([
                            'type_id' => $targetType->id,
                            'updated_at' => now(),
                        ]);

                    $legacyType->delete();
                }
            }

            $this->removeNonTargetTypes($targetTypes);
        });
    }

    /**
     * Resolve the final target property type row.
     *
     * @param array<int, string> $legacyNames
     */
    private function resolveTargetType(string $targetName, array $legacyNames): PropertyType
    {
        $existingTarget = PropertyType::query()
            ->where('name', $targetName)
            ->first();

        if ($existingTarget !== null) {
            return $existingTarget;
        }

        $legacyTypes = PropertyType::query()
            ->whereIn('name', $legacyNames)
            ->get()
            ->keyBy('name');

        foreach ($legacyNames as $legacyName) {
            $legacyType = $legacyTypes->get($legacyName);

            if ($legacyType === null) {
                continue;
            }

            $legacyType->forceFill([
                'name' => $targetName,
            ])->save();

            return $legacyType->fresh();
        }

        return PropertyType::query()->create([
            'name' => $targetName,
        ]);
    }

    /**
     * Delete every non-target property type after moving its properties.
     *
     * @param array<string, PropertyType> $targetTypes
     */
    private function removeNonTargetTypes(array $targetTypes): void
    {
        $targetIds = collect($targetTypes)
            ->map(fn (PropertyType $propertyType) => (int) $propertyType->id)
            ->values()
            ->all();

        $fallbackType = $targetTypes['Mall/Plaza'] ?? reset($targetTypes);
        $apartmentType = $targetTypes['Apartment'] ?? $fallbackType;

        $nonTargetTypes = PropertyType::query()
            ->whereNotIn('id', $targetIds)
            ->get();

        foreach ($nonTargetTypes as $propertyType) {
            $destinationType = $this->resolveReplacementType($propertyType->name, $fallbackType, $apartmentType);

            DB::table('properties')
                ->where('type_id', $propertyType->id)
                ->update([
                    'type_id' => $destinationType->id,
                    'updated_at' => now(),
                ]);

            $propertyType->delete();
        }
    }

    /**
     * Resolve replacement target for a non-target property type.
     */
    private function resolveReplacementType(string $name, PropertyType $fallbackType, PropertyType $apartmentType): PropertyType
    {
        return in_array($name, ['Residential', 'Apartment'], true)
            ? $apartmentType
            : $fallbackType;
    }
}
