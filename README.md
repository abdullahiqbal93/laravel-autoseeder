# Laravel AutoSeeder

Automatically generate realistic database seeders for Laravel models, including relationships.

---

## Quick start

1. Install (dev dependency):

```bash
composer require dedsec/laravel-autoseeder --dev
```

2. (Optional) Publish config:

```bash
php artisan vendor:publish --provider="Dedsec\\LaravelAutoSeeder\\AutoSeederServiceProvider" --tag=config
```

3. Generate seeders:

```bash
php artisan make:auto-seeders
```

4. Run seeders:

```bash
php artisan db:seed
```

---

## Requirements

- PHP: >= 7.4
- Laravel: >= 8.0
- Supported DB: MySQL, PostgreSQL, SQLite, SQL Server (enum support for MySQL/MariaDB, PostgreSQL, and SQLite)

---

## Usage & options

Run the generator (common options shown):

```bash
php artisan make:auto-seeders [--path=app/Models] [--limit=10] [--only=User,Post] [--force] [--quiet] [--verbose]
```

Options (summary):

- `--path` — models directory (default: `app/Models`)
- `--limit` — number of records per model (default: `10`)
- `--only` — comma-separated model class basenames to process (e.g. `User,Post`)
- `--force` — overwrite existing generated seeders
- `--quiet` — suppress output (useful for CI)
- `--verbose` — show debug details

---

## Examples

- Generate seeders for all models (10 records each):

```bash
php artisan make:auto-seeders
```

- Generate 5 records per model:

```bash
php artisan make:auto-seeders --limit=5
```

- Generate seeders for specific models (User and Post):

```bash
php artisan make:auto-seeders --only=User,Post
```

- Use a custom models directory:

```bash
php artisan make:auto-seeders --path=app/CustomModels
```

- Force overwrite of any previously generated seeders:

```bash
php artisan make:auto-seeders --force
```

---

## Key features

- Automatic model discovery (scans `app/Models` or provided path)
- Type-aware data generation (respect column types/lengths, including enum values across all supported databases)
- Foreign-key aware seeding and ordering
- Relationship detection (belongsTo, hasMany, belongsToMany, morphs, etc.)
- Unique & composite-unique constraint handling
- Polymorphic support and two-phase seeding for circular deps

---

## Configuration (defaults)

After publishing, edit `config/autoseeder.php`. example defaults:

```php
return [
	'models_path' => 'app/Models',
	'records_limit' => 10,
	'skip_tables' => [],
	'date_format' => 'Y-m-d H:i:s',
	'string_max_length' => 255,
	'enable_strict_types' => true,
	'enable_relationship_validation' => true,
	'enable_uniqueness_guards' => true,
];
```

---

## Troubleshooting

- "No Eloquent models found" — ensure models are in the path or pass `--path`.
- "Table doesn't exist" — run `php artisan migrate` first.
- Foreign key / unique constraint errors — confirm relationships and migration state.

For verbose debug output use:

```bash
php artisan make:auto-seeders --verbose
```

---

## Best practices

- Use this package in development and testing only.
- Consider committing generated seeders if they are part of test fixtures.
- Generate related models together to preserve referential integrity.
- Use `--only` and reasonable limits when working with large schemas.

---

## License

MIT

