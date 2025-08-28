<?php

namespace Dedsec\LaravelAutoSeeder\Console;

use Illuminate\Console\Command;
use Dedsec\LaravelAutoSeeder\Services\ModelScanner;
use Dedsec\LaravelAutoSeeder\Services\SchemaAnalyzer;
use Dedsec\LaravelAutoSeeder\Services\RelationshipDetector;
use Dedsec\LaravelAutoSeeder\Services\SeederGenerator;
use Dedsec\LaravelAutoSeeder\Services\SeederManager;

class MakeAutoSeedersCommand extends Command
{
    protected $signature = 'make:auto-seeders
                          {--path=app/Models : Path to your models directory}
                          {--limit=10 : Number of records to generate per model}
                          {--only= : Comma-separated list of specific models to generate seeders for}
                          {--force : Overwrite existing seeders without prompting}';

    protected $description = 'Generate realistic database seeders for Laravel models with relationships and type-aware data.';

    public function handle()
    {
        $path = $this->option('path');
        if (!str_starts_with($path, '/') && !str_starts_with($path, '\\') && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = base_path($path);
        }
        $limit = (int) $this->option('limit');
        $only = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : [];
        $force = (bool) $this->option('force');
        $quiet = $this->output->isQuiet();

        // Validate inputs
        if ($limit < 1) {
            $this->error('Limit must be at least 1');
            return 1;
        }

        if (!is_dir($path)) {
            $this->error("Models directory not found: {$path}");
            return 1;
        }

        if (!$quiet) {
            $this->info("🔍 Scanning models in: {$path}");
        }

        try {
            $scanner = new ModelScanner($path);
            $models = $scanner->getModels();
        } catch (\Exception $e) {
            $this->error("Failed to scan models: " . $e->getMessage());
            return 1;
        }

        if (empty($models)) {
            $this->warn('No Eloquent models found in the specified directory.');
            return 0;
        }

        // Check for existing seeders and prompt for confirmation if --force is not used
        if (!$force) {
            // Filter models first to get the actual models that will be processed
            $modelsToProcess = $models;
            if ($only) {
                $modelsToProcess = array_filter($models, function ($m) use ($only) {
                    return in_array(class_basename($m), $only, true) || in_array($m, $only, true);
                });
            }

            $existingSeeders = $this->checkExistingSeeders($modelsToProcess, base_path('database/seeders'));
            if (!empty($existingSeeders)) {
                if (!$quiet) {
                    $this->warn('⚠️  The following seeders already exist:');
                    foreach ($existingSeeders as $seeder) {
                        $this->line("   • {$seeder}");
                    }
                    $this->newLine();
                }

                // Check if we're in an interactive terminal
                if ($this->input->isInteractive()) {
                    if (!$this->confirm('Do you want to overwrite existing seeders?', false)) {
                        $this->info('❌ Operation cancelled by user.');
                        return 0;
                    }
                    $force = true; // Set force to true since user confirmed
                } else {
                    // Non-interactive mode: show warning and abort
                    $this->error('❌ Existing seeders found. Use --force to overwrite or remove existing seeders first.');
                    $this->error('Existing seeders: ' . implode(', ', $existingSeeders));
                    return 1;
                }
            }
        }

        // Filter models if --only option is used
        if ($only) {
            $originalCount = count($models);
            $models = array_filter($models, function ($m) use ($only) {
                return in_array(class_basename($m), $only, true) || in_array($m, $only, true);
            });

            if (count($models) !== $originalCount) {
                $foundModels = array_map('class_basename', $models);
                if (!$quiet) {
                    $this->info('Filtered to specific models: ' . implode(', ', $foundModels));
                }
            }
        }

        if (!$quiet) {
            $this->info("📊 Found " . count($models) . ' model(s) to process');
        }

        // Initialize services
        try {
            $schema = new SchemaAnalyzer();
            $relDetector = new RelationshipDetector();
            $generator = new SeederGenerator($schema, $relDetector, $limit);
            $manager = new SeederManager(base_path('database/seeders'));
        } catch (\Exception $e) {
            $this->error("Failed to initialize services: " . $e->getMessage());
            return 1;
        }

        // Analyze models
        $meta = [];
        $progressBar = !$quiet ? $this->output->createProgressBar(count($models)) : null;
        $progressBar?->setFormat('verbose');

        foreach ($models as $model) {
            try {
                $meta[$model] = [
                    'columns' => $schema->columnsForModel($model),
                    'relations' => $relDetector->relationsForModel($model),
                ];
                $progressBar?->advance();
            } catch (\Exception $e) {
                if (!$quiet) {
                    $this->warn("Skipping {$model}: " . $e->getMessage());
                }
                $progressBar?->advance();
            }
        }

        $progressBar?->finish();
        if (!$quiet) {
            $this->newLine();
        }

        if (empty($meta)) {
            $this->error('No valid models could be analyzed. Please check your model files.');
            return 1;
        }

        $resolved = $manager->resolveOrder(array_keys($meta), $meta);
        $order = $resolved['order'];
        $cycles = $resolved['cycles'];

        if (!$quiet) {
            $this->info('📋 Seeding order: ' . implode(' -> ', array_map('class_basename', $order)));
            if (!empty($cycles)) {
                $this->warn('⚠️  Potential cycle detected involving models: ' . implode(', ', array_map('class_basename', $cycles)) . '. Generated order may be approximate.');
            }
        }

        // Generate seeders
        $successCount = 0;
        $progressBar = !$quiet ? $this->output->createProgressBar(count($order)) : null;
        $progressBar?->setFormat('verbose');

        foreach ($order as $model) {
            try {
                if (!$quiet) {
                    $this->info('🔧 Generating seeder for ' . class_basename($model));
                }
                $seederClass = $generator->generateForModel($model, $meta[$model]['columns'], $meta[$model]['relations'], $meta);
                $written = $manager->writeSeeder($seederClass, $model, $force);
                if (!$written && !$force) {
                    if (!$quiet) {
                        $this->warn('⏭️  Seeder for ' . class_basename($model) . ' already exists. Use --force to overwrite.');
                    }
                } else {
                    $successCount++;
                }
                $progressBar?->advance();
            } catch (\Exception $e) {
                if (!$quiet) {
                    $this->error("❌ Failed to generate seeder for {$model}: " . $e->getMessage());
                }
                $progressBar?->advance();
            }
        }

        $progressBar?->finish();
        if (!$quiet) {
            $this->newLine();
        }

        // Update DatabaseSeeder
        try {
            $merged = $manager->updateDatabaseSeeder($order, $force);
            if (!$merged && !$force) {
                if (!$quiet) {
                    $this->warn('⚠️  DatabaseSeeder was not updated. Use --force to overwrite.');
                }
            }
        } catch (\Exception $e) {
            if (!$quiet) {
                $this->error("❌ Failed to update DatabaseSeeder: " . $e->getMessage());
            }
            return 1;
        }

        if (!$quiet) {
            $this->info("✅ Successfully generated {$successCount} seeder(s)");
            $this->info('🚀 Run `php artisan db:seed` to populate your database with test data!');
        }

        return 0;
    }

    /**
     * Check which seeders already exist for the given models
     */
    private function checkExistingSeeders(array $models, string $seedersPath): array
    {
        $existing = [];

        foreach ($models as $model) {
            $parts = explode('\\', $model);
            $short = end($parts);
            $seederFile = $seedersPath . DIRECTORY_SEPARATOR . $short . 'Seeder.php';

            if (file_exists($seederFile)) {
                $existing[] = $short . 'Seeder.php';
            }
        }

        // Also check for DatabaseSeeder
        $dbSeederFile = $seedersPath . DIRECTORY_SEPARATOR . 'DatabaseSeeder.php';
        if (file_exists($dbSeederFile)) {
            $existing[] = 'DatabaseSeeder.php';
        }

        return $existing;
    }
}
