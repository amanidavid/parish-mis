<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$floors = DB::table('property_floors')->limit(10)->pluck('id')->toArray();
$floorIds = implode(',', $floors ?: [0]);
$propertyId = DB::table('property_floors')->value('property_id') ?? 0;

echo "=== Floor IDs used: [{$floorIds}] | Property ID: {$propertyId} ===" . PHP_EOL . PHP_EOL;

// --- Test 1: Optimized whereIn on property_floor_id ---
echo "--- TEST 1: Optimized whereIn(property_floor_id) ---" . PHP_EOL;
$rows = DB::select("EXPLAIN SELECT * FROM units WHERE property_floor_id IN ({$floorIds}) LIMIT 15");
printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n", 'id', 'select_type', 'type', 'key', 'ref', 'rows', 'Extra');
foreach ($rows as $r) {
    $r = (array) $r;
    printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n",
        $r['id'] ?? '', $r['select_type'] ?? '', $r['type'] ?? '',
        $r['key'] ?? 'NULL', $r['ref'] ?? '', $r['rows'] ?? '', $r['Extra'] ?? '');
}

echo PHP_EOL;

// --- Test 2: Old nested EXISTS (slow original query) ---
echo "--- TEST 2: Old nested EXISTS (original slow query) ---" . PHP_EOL;
$rows2 = DB::select("EXPLAIN SELECT * FROM units WHERE EXISTS (SELECT 1 FROM property_floors WHERE property_floors.id = units.property_floor_id AND property_floors.property_id = ?) LIMIT 15", [$propertyId]);
printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n", 'id', 'select_type', 'type', 'key', 'ref', 'rows', 'Extra');
foreach ($rows2 as $r) {
    $r = (array) $r;
    printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n",
        $r['id'] ?? '', $r['select_type'] ?? '', $r['type'] ?? '',
        $r['key'] ?? 'NULL', $r['ref'] ?? '', $r['rows'] ?? '', $r['Extra'] ?? '');
}

echo PHP_EOL;

// --- Test 3: whereIn + unit_number LIKE search ---
echo "--- TEST 3: whereIn + unit_number LIKE prefix search ---" . PHP_EOL;
$rows3 = DB::select("EXPLAIN SELECT * FROM units WHERE property_floor_id IN ({$floorIds}) AND unit_number LIKE ? LIMIT 15", ['A%']);
printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n", 'id', 'select_type', 'type', 'key', 'ref', 'rows', 'Extra');
foreach ($rows3 as $r) {
    $r = (array) $r;
    printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n",
        $r['id'] ?? '', $r['select_type'] ?? '', $r['type'] ?? '',
        $r['key'] ?? 'NULL', $r['ref'] ?? '', $r['rows'] ?? '', $r['Extra'] ?? '');
}

echo PHP_EOL;

// --- Test 4: Single floor filter ---
$singleFloor = $floors[0] ?? 0;
echo "--- TEST 4: Single floor (property_floor_id = {$singleFloor}) ---" . PHP_EOL;
$rows4 = DB::select("EXPLAIN SELECT * FROM units WHERE property_floor_id = ? LIMIT 15", [$singleFloor]);
printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n", 'id', 'select_type', 'type', 'key', 'ref', 'rows', 'Extra');
foreach ($rows4 as $r) {
    $r = (array) $r;
    printf("%-6s %-14s %-12s %-30s %-20s %-8s %-50s\n",
        $r['id'] ?? '', $r['select_type'] ?? '', $r['type'] ?? '',
        $r['key'] ?? 'NULL', $r['ref'] ?? '', $r['rows'] ?? '', $r['Extra'] ?? '');
}

echo PHP_EOL . "=== Done ===" . PHP_EOL;
