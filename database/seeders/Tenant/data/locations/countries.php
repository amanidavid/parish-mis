<?php

$jsonPath = __DIR__.DIRECTORY_SEPARATOR.'countries.json';
$currencyMap = require __DIR__.DIRECTORY_SEPARATOR.'country_currency_map.php';
$decoded = json_decode(file_get_contents($jsonPath), true);

if (!is_array($decoded)) {
    throw new RuntimeException('Invalid countries.json payload.');
}

return array_map(static function (array $row) use ($currencyMap): array {
    $code = strtoupper(trim((string) ($row['code'] ?? '')));
    $row['currency_code'] = $currencyMap[$code] ?? null;

    return $row;
}, $decoded);
