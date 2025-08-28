# Laravel AutoSeeder

Automatically generate realistic database seeders for Laravel models, including relationships.

## Quick Start

1. Install the package:
composer require dedsec/laravel-autoseeder --dev

2. Publish configuration (optional):
php artisan vendor:publish --provider="Dedsec\\LaravelAutoSeeder\\AutoSeederServiceProvider" --tag=config

3. Generate seeders:
php artisan make:auto-seeders

4. Run the seeders:
php artisan db:seed

## Requirements

- PHP 7.4 or higher
- Laravel 8.0 or higher
- Supported Databases: MySQL, PostgreSQL, SQLite, SQL Server

## Usage

php artisan make:auto-seeders

### Options

--path=app/Models      Models directory
--limit=10             Records per model
--only=User,Post       Specific models only
--force                Overwrite existing seeders
--quiet                Suppress output
--verbose              Show debug info

### Examples

Generate seeders for all models (10 records each):
php artisan make:auto-seeders

Generate 5 records per model:
php artisan make:auto-seeders --limit=5

Generate seeders for specific models (User and Post):
php artisan make:auto-seeders --only=User,Post

Use a custom models directory:
php artisan make:auto-seeders --path=app/CustomModels

Force overwrite existing seeders:
php artisan make:auto-seeders --force

## Features

- Automatic model discovery
- Type-aware data generation
- Foreign key integrity
- Relationship detection
- Unique and composite unique constraint handling
- Polymorphic relationships support
- Two-phase seeding for circular dependencies
- Correct seeding order resolution

## Configuration

Publish config:
php artisan vendor:publish --provider="Dedsec\\LaravelAutoSeeder\\AutoSeederServiceProvider" --tag=config

Options in config/autoseeder.php:

models_path => 'app/Models'
records_limit => 10
skip_tables => []
date_format => 'Y-m-d H:i:s'
string_max_length => 255
enable_strict_types => true
enable_relationship_validation => true
enable_uniqueness_guards => true

## Troubleshooting

- "No Eloquent models found": check --path or model namespace
- "Table doesn't exist": run php artisan migrate
- Foreign key or unique constraint errors: check relationships

Enable verbose output:
php artisan make:auto-seeders --verbose

## Best Practices

- Use in development/testing environments only
- Consider committing generated seeders to version control
- Generate related models together for referential integrity
- Use --only and reasonable --limit for large datasets

## License

MIT
