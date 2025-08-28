# Changelog

All notable changes to `laravel-autoseeder` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2025-08-28

### Added
- **Enum support**: Enum columns now generate values from their actual allowed enum values instead of generic strings
- **Multi-database enum support**: Works with MySQL/MariaDB, PostgreSQL, and SQLite
- Enhanced type-aware data generation for enum columns
- PostgreSQL enum detection using pg_enum system catalog
- SQLite enum pattern detection for common enum-like columns

## [1.0.0] - 2025-08-28

### Added
- Initial release of Laravel AutoSeeder
- Automatic model discovery and scanning
- Schema analysis with type detection (integers, floats, strings, text, booleans, dates, JSON, enums, UUIDs, etc.)
- Relationship detection for all Laravel relationship types (belongsTo, hasMany, belongsToMany, morphMany, morphTo, etc.)
- Two-phase seeding to handle circular dependencies
- Foreign key integrity maintenance
- Unique constraint handling (including composite uniques)
- Polymorphic relationship support
- Type-aware data generation with proper length/precision enforcement
- Configurable record limits and model path
- Comprehensive error handling and logging
- Production-ready code with proper documentation

### Features
- Generates realistic test data matching exact migration specifications
- Handles complex relationship cardinalities
- Supports all Laravel data types and constraints
- Automatic dependency resolution for seeding order
- Safe handling of missing tables and models
- Extensive customization options

### Dependencies
- PHP >= 7.4
- Laravel >= 8.0
- FakerPHP >= 1.9
- Carbon >= 2.0
