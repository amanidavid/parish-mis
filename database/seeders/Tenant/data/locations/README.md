Place the legacy location payloads in this folder before running tenant seeding.

Required files:
- `countries.json` or `countries.php`
- `regions.json` or `regions.php`
- `districts.json` or `districts.php`
- `wards.json` or `wards.php`

Rules:
- Each JSON file must contain a JSON array of objects.
- Each PHP file must `return` an array of associative arrays.
- Keep the same keys from the legacy source payloads such as `id`, `country_id`, `region_id`, `district_id`, `name`, `code`, `dial_code`, `post_code`, `created_at`, and `updated_at`.
- Public APIs will expose only `uuid`; the numeric legacy `id` values are used only internally during import to map parent-child relationships.
- `countries.json` may omit `id`. When it does, the importer assigns `legacy_id` using the record order so it still matches region references like Tanzania = `213`.
- Use the PHP option for very large payloads like wards when converting the original legacy seeder is easier than building one huge JSON file.

Example country record:

```json
[
  {
    "name": "Tanzania",
    "dial_code": "255",
    "code": "TZ"
  }
]
```

Example region record:

```json
[
  {
    "id": 1,
    "country_id": 213,
    "post_code": 10,
    "name": "dar es salaam",
    "created_at": "2025-05-24 11:24:13",
    "updated_at": "2025-05-24 11:24:13"
  }
]
```

Example PHP payload:

```php
<?php

return [
    [
        'id' => 1,
        'district_id' => 1,
        'post_code' => 11101,
        'name' => 'KIVUKONI',
        'created_at' => '2025-05-24 11:24:13',
        'updated_at' => '2025-05-24 11:24:13',
        'deleted_at' => null,
    ],
];
```
