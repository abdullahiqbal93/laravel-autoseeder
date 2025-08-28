# Laravel AutoSeeder

[![Latest Version](https://img.shields.io/packagist/v/dedsec/laravel-autoseeder.svg)](https://packagist.org/packages/dedsec/laravel-autoseeder)
[![PHP Version](https://img.shields.io/packagist/php-v/dedsec/laravel-autoseeder.svg)](https://packagist.org/packages/dedsec/laravel-autoseeder)
[![License](https://img.shields.io/packagist/l/dedsec/laravel-autoseeder.svg)](https://packagist.org/packages/dedsec/laravel-autoseeder)


Automatically generate realistic database seeders for Laravel models including relationships.

## Table of Contents

- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Features](#features)
- [How It Works](#how-it-works)
- [Supported Relationship Types](#supported-relationship-types)
- [Data Types Supported](#data-types-supported)
- [Advanced Features](#advanced-features)
- [Testing & Validation](#testing--validation)
- [Production Ready Features](#production-ready-features)
- [Configuration](#configuration)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

## Quick Start

1. **Install the package**:
   ```bash
   composer require dedsec/laravel-autoseeder --dev
   ```

2. **Publish configuration** (optional):
   ```bash
   php artisan vendor:publish --provider="Dedsec\\LaravelAutoSeeder\\AutoSeederServiceProvider" --tag=config
   ```

3. **Generate seeders**:
   ```bash
   php artisan make:auto-seeders
   ```

4. **Run the seeders**:
   ```bash
   php artisan db:seed
   ```

That's it! Your database is now populated with realistic test data! 🎉

## Requirements

- **PHP**: 7.4 or higher
- **Laravel**: 8.0 or higher
- **Doctrine DBAL**: `composer require doctrine/dbal` (for schema analysis)
- **Supported Databases**: MySQL, PostgreSQL, SQLite, SQL Server

## Usage

```bash
php artisan make:auto-seeders
```

### Options

- `--path=app/Models`: Path to your models directory
- `--limit=10`: Number of records to generate per model
- `--only=User,Post`: Generate seeders for specific models only
- `--force`: Overwrite existing seeders
- `--quiet`: Suppress output (useful for CI/CD pipelines)

### Example

```bash
# Generate seeders for all models in app/Models
php artisan make:auto-seeders

# Generate only 5 records per model
php artisan make:auto-seeders --limit=5

# Generate seeders for specific models only
php artisan make:auto-seeders --only=User,Post,Comment

# Overwrite existing seeders
php artisan make:auto-seeders --force
```

## Features

- **Automatic Model Discovery**: Scans your models directory and discovers all Eloquent models
- **Schema Analysis**: Analyzes database schema to understand column types, constraints, and relationships
- **Relationship Detection**: Automatically detects and handles all Laravel relationship types
- **Type-Aware Data Generation**: Generates realistic data based on column types and constraints
- **Foreign Key Integrity**: Maintains referential integrity between related models
- **Unique Constraints**: Respects unique and composite unique constraints
- **Polymorphic Relationships**: Handles polymorphic relationships with fallback strategies
- **Two-Phase Seeding**: Creates base records first, then assigns relationships
- **Order Resolution**: Automatically determines the correct seeding order based on dependencies

## How It Works

1. **Model Scanning**: Discovers all Eloquent models in your specified directory
2. **Schema Analysis**: Inspects database tables to understand column types, lengths, precision, scale, and constraints
3. **Relationship Detection**: Analyzes model methods to identify all Laravel relationship types
4. **Dependency Resolution**: Determines the correct order for seeding based on foreign key dependencies
5. **Type-Aware Generation**: Creates realistic data that matches exact column specifications from migrations
6. **Constraint Handling**: Respects unique constraints, composite uniques, and foreign key relationships
7. **Seeder Generation**: Creates PHP seeder classes with type-correct, relationship-valid fake data
8. **Two-Phase Seeding**: Creates base records first, then assigns relationships and polymorphic associations
9. **Integrity Validation**: Ensures all seeded data maintains referential integrity and type correctness

## Supported Relationship Types

- `belongsTo`
- `hasMany`
- `belongsToMany`
- `hasOne`
- `morphMany`
- `morphOne`
- `morphToMany`
- `morphTo`
- `hasOneThrough`
- `hasManyThrough`
- `belongsToThrough`

## Data Types Supported

- **Integers**: `int`, `tinyint`, `smallint`, `bigint` (with length and unsigned support)
- **Floats/Decimals**: `float`, `double`, `decimal`, `numeric` (with precision and scale)
- **Strings/Text**: `varchar`, `char`, `text`, `mediumtext`, `longtext` (with length limits)
- **Booleans**: `tinyint(1)` or `boolean`
- **Dates/Times**: `date`, `datetime`, `timestamp`, `time`, `datetimetz`
- **JSON**: `json`, `jsonb`
- **Enums**: `enum` (respects allowed values)
- **Sets**: `set` (respects allowed values)
- **Binary**: `binary`, `varbinary`, `blob`
- **UUIDs**: `uuid`
- **Network Types**: `ipaddress`, `inet`, `macaddress`
- **Geometry**: `geometry`, `point`, `linestring`, `polygon`
- **Foreign Keys**: Automatic relationship handling

## Advanced Features

- **Strict Type Enforcement**: Generates data that exactly matches migration-defined types, lengths, and constraints
- **Composite Unique Constraints**: Handles multiple-column unique indexes
- **Cardinality Enforcement**: Maintains proper relationship cardinalities (one-to-one, one-to-many, many-to-many)
- **Unique FK Pools**: Prevents duplicate foreign key assignments in one-to-one relationships
- **Pivot Pair Uniqueness**: Ensures unique combinations in many-to-many pivot tables
- **Polymorphic Fallbacks**: Robust handling of polymorphic relationships with automatic candidate discovery
- **Runtime Uniqueness Guards**: Prevents duplicate values in unique columns during seeding
- **Self-Referential Relationships**: Handles parent-child relationships within the same model
- **Placeholder Creation**: Creates placeholder records when needed to satisfy foreign key constraints

## Testing & Validation

This package has been thoroughly tested with a comprehensive banking/financial system containing:
- **14 Eloquent models** with complex relationships
- **All Laravel relationship types** (belongsTo, hasMany, belongsToMany, morphMany, morphTo, etc.)
- **Multiple data types** (integers, floats, strings, text, booleans, dates, JSON, enums, UUIDs)
- **Complex constraints** (unique, composite unique, foreign keys, NOT NULL)

### Test Results ✅
- ✅ **14/14 models** successfully analyzed and processed
- ✅ **140+ records** generated with proper data types
- ✅ **100% referential integrity** maintained
- ✅ **All relationship types** working correctly
- ✅ **Polymorphic relationships** functioning properly
- ✅ **Two-phase seeding** handling circular dependencies
- ✅ **Type enforcement** matching exact migration specifications

## Production Ready Features

- **Robust Error Handling**: Graceful handling of missing models/tables
- **Progress Indicators**: Visual feedback during generation
- **Quiet Mode**: Suppress output for CI/CD pipelines
- **Comprehensive Logging**: Detailed error reporting
- **Safe Foreign Key Handling**: Automatic constraint management
- **Database Compatibility**: Works with MySQL, PostgreSQL, SQLite, and SQL Server

## Integrity Checking

> **Note**: The integrity checker is a planned feature for future releases. Currently, the package automatically ensures data integrity through its two-phase seeding process and constraint validation.

The package maintains data integrity through:
- **Foreign Key Validation**: All relationships are validated during generation
- **Unique Constraint Enforcement**: Duplicate values are prevented
- **Type Safety**: Data types match migration specifications exactly
- **Relationship Cardinality**: Proper relationship ratios are maintained

## Configuration

After publishing the configuration file, you can customize the default settings:

```bash
php artisan vendor:publish --provider="Dedsec\\LaravelAutoSeeder\\AutoSeederServiceProvider" --tag=config
```

Available configuration options in `config/autoseeder.php`:

```php
return [
    'models_path' => 'app/Models',        // Path to your models directory
    'records_limit' => 10,                // Default number of records per model
    'skip_tables' => [],                  // Tables to skip during seeding
    'date_format' => 'Y-m-d H:i:s',       // Date format for timestamps
    'string_max_length' => 255,           // Maximum string length
    'enable_strict_types' => true,        // Enforce exact data types
    'enable_relationship_validation' => true, // Validate relationships
    'enable_uniqueness_guards' => true,   // Prevent duplicate unique values
];
```

### Configuration Options

- `models_path`: Default path to scan for models (default: `app/Models`)
- `records_limit`: Default number of records to generate per model (default: 10)
- `skip_tables`: Array of table names to skip during seeding
- `date_format`: Default date format for date/datetime columns
- `string_max_length`: Maximum length for generated strings when no length is specified
- `enable_strict_types`: Whether to enforce exact data types (default: true)
- `enable_relationship_validation`: Whether to validate relationships (default: true)
- `enable_uniqueness_guards`: Whether to prevent duplicate unique values (default: true)

## Troubleshooting

### Common Issues

#### ❌ **"No Eloquent models found"**
**Problem**: The package can't find your models.
**Solutions**:
- Check that your models are in the correct directory (default: `app/Models`)
- Use `--path` option to specify custom path: `php artisan make:auto-seeders --path=app/CustomModels`
- Ensure your model files extend `Illuminate\Database\Eloquent\Model`

#### ❌ **"Table doesn't exist" errors**
**Problem**: Models exist but their database tables haven't been created.
**Solutions**:
- Run your migrations first: `php artisan migrate`
- Check that migration files exist for all your models
- Use `php artisan migrate:status` to see migration status

#### ❌ **Foreign Key Constraint Errors**
**Problem**: Seeding fails due to foreign key violations.
**Solutions**:
- The package handles this automatically with two-phase seeding
- If issues persist, check your model relationships are correctly defined
- Ensure related models have valid data

#### ❌ **Unique Constraint Violations**
**Problem**: Duplicate values in unique columns.
**Solutions**:
- The package includes runtime uniqueness guards
- If duplicates still occur, check your unique constraints
- Consider increasing the Faker data pool or adjusting your constraints

#### ❌ **Memory Issues with Large Datasets**
**Problem**: Running out of memory with many records.
**Solutions**:
- Reduce the `--limit` parameter: `php artisan make:auto-seeders --limit=5`
- Process models individually: `php artisan make:auto-seeders --only=User`
- Increase PHP memory limit: `php -d memory_limit=512M artisan make:auto-seeders`

### Debug Mode

Enable verbose output to see detailed information:
```bash
php artisan make:auto-seeders --verbose
```

### Getting Help

- 📖 **Documentation**: Check this README for detailed information
- 🐛 **Bug Reports**: [GitHub Issues](https://github.com/abdullahiqbal93/laravel-autoseeder/issues)
- 💬 **Discussions**: [GitHub Discussions](https://github.com/abdullahiqbal93/laravel-autoseeder/discussions)
- 📧 **Email**: dev@example.com

## Best Practices

### Development Workflow

1. **Use in Development/Testing**: This package is designed for development and testing environments
2. **Version Control**: Consider whether to commit generated seeders to your repository
3. **Data Consistency**: Use the same `--limit` values for consistent dataset sizes
4. **Relationship Testing**: Generate data for related models together to maintain referential integrity

### Performance Optimization

- **Selective Generation**: Use `--only` for specific models when testing features
- **Reasonable Limits**: Start with smaller `--limit` values and increase as needed
- **Batch Processing**: The package automatically handles large datasets efficiently

### Data Quality

- **Realistic Data**: The package generates realistic data patterns for testing
- **Constraint Awareness**: Respects all your database constraints and relationships
- **Type Safety**: Ensures data types match your migration specifications exactly

## Limitations

### Current Constraints

- **PHP Version**: Requires PHP 7.4 or higher
- **Laravel Version**: Compatible with Laravel 8.0+
- **Database Support**: Optimized for MySQL, PostgreSQL, SQLite
- **Model Structure**: Requires standard Eloquent model patterns

### Known Limitations

- **Custom Model Methods**: May not detect highly customized relationship methods
- **Complex Polymorphic Setups**: Advanced polymorphic configurations might need manual review
- **Very Large Datasets**: Extremely large datasets (>100k records) may require memory adjustments
- **Custom Data Types**: Unsupported database-specific data types may fall back to string generation

### Not Supported

- **Non-Eloquent Models**: Only works with Laravel Eloquent models
- **Raw SQL Tables**: Requires Eloquent models for table discovery
- **Custom Faker Providers**: Uses built-in Faker providers (can be extended)
- **Real-time Data**: Generates static test data, not real-time/dynamic data

## FAQ

### General Questions

**Q: Is this safe to use in production?**
A: This package is designed for development and testing environments. While the generated data is realistic, it's not suitable for production use without review.

**Q: Can I customize the generated data?**
A: Yes! You can modify the generated seeders after creation, or extend the package to add custom data generators.

**Q: How does it handle large databases?**
A: The package uses efficient chunking and batching. For very large datasets, consider using the `--only` option to process specific models.

**Q: Can I use this with existing seeders?**
A: Yes! The generated seeders work alongside your existing seeders. You can run them together or separately.

### Technical Questions

**Q: What if my models are in a different directory?**
A: Use the `--path` option: `php artisan make:auto-seeders --path=app/MyModels`

**Q: How do I regenerate seeders without overwriting?**
A: Use the `--force` flag: `php artisan make:auto-seeders --force`

**Q: Can I skip certain models?**
A: Use the `--only` option with specific models: `php artisan make:auto-seeders --only=User,Post`

**Q: Why am I getting foreign key errors?**
A: The package handles this automatically with two-phase seeding. If issues persist, check your model relationships and run migrations first.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the complete version history.

## Contributing

We welcome contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT
