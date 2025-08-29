<?php

namespace Dedsec\LaravelAutoSeeder\Services;

class SeederManager
{
    protected $seedersPath;

    public function __construct(string $seedersPath)
    {
        $this->seedersPath = rtrim($seedersPath, '\\/');
        if (!is_dir($this->seedersPath)) {
            mkdir($this->seedersPath, 0755, true);
        }
    }

    public function resolveOrder(array $models, array $meta): array
    {
        $adj = [];
        foreach ($models as $m) {
            $adj[$m] = [];
        }

        // Build relationship map for indirect dependency resolution
        $relationshipMap = [];
        foreach ($models as $m) {
            $relationshipMap[$m] = $meta[$m]['relations'] ?? [];
        }

        foreach ($models as $m) {
            $deps = [];

            // Check relationships - enhanced to handle multiple relationship types
            foreach ($meta[$m]['relations'] as $rel) {
                $relType = $rel['type'];
                $relatedModel = $rel['related'];

                if (empty($relatedModel)) {
                    continue;
                }

                // Direct dependencies that require parent records to exist first
                if (in_array($relType, ['BelongsTo', 'belongsTo'], true) ||
                    (stripos($relType, 'BelongsTo') !== false && $relType !== 'BelongsToMany')) {
                    // Direct BelongsTo relationship
                    $deps[] = $relatedModel;
                } elseif (in_array($relType, ['MorphTo', 'morphTo'], true)) {
                    // Polymorphic BelongsTo - need to find possible morphable models
                    $morphableModels = $this->findMorphableModels($m, $rel['name'], $models);
                    $deps = array_merge($deps, $morphableModels);
                } elseif (in_array($relType, ['HasOneThrough', 'HasManyThrough', 'hasOneThrough', 'hasManyThrough'], true)) {
                    // Through relationships create indirect dependencies
                    $throughDeps = $this->findThroughDependencies($m, $rel, $models);
                    $deps = array_merge($deps, $throughDeps);
                } elseif (in_array($relType, ['MorphOne', 'MorphMany', 'morphOne', 'morphMany'], true)) {
                    // These are inverse polymorphic relationships, covered by MorphTo detection
                    continue;
                } elseif (in_array($relType, ['MorphToMany', 'MorphedByMany', 'morphToMany', 'morphedByMany'], true)) {
                    // Polymorphic many-to-many relationships
                    $morphManyDeps = $this->findMorphToManyDependencies($m, $rel, $models);
                    $deps = array_merge($deps, $morphManyDeps);
                }
            }

            // Check for indirect dependencies (relationship chains)
            $indirectDeps = $this->findIndirectDependencies($m, $relationshipMap, $models, []);
            $deps = array_merge($deps, $indirectDeps);

            // Check fillable columns for foreign keys (fallback method)
            if (!empty($meta[$m]['columns']) && is_array($meta[$m]['columns'])) {
                foreach ($meta[$m]['columns'] as $colName => $colMeta) {
                    if (substr($colName, -3) === '_id' && $colName !== 'id') {
                        $prefix = substr($colName, 0, -3);

                        // Look for matching model by class name
                        foreach ($models as $candidate) {
                            $candidateShort = (strpos($candidate, '\\') !== false) ? substr($candidate, strrpos($candidate, '\\') + 1) : $candidate;
                            if (strtolower($candidateShort) === strtolower($prefix)) {
                                $deps[] = $candidate;
                                break;
                            }
                        }

                        // If not found by class name, try table name
                        if (!in_array($candidate, $deps, true)) {
                            foreach ($models as $candidate) {
                                try {
                                    if (class_exists($candidate)) {
                                        $inst = new $candidate;
                                        if (method_exists($inst, 'getTable')) {
                                            $table = $inst->getTable();
                                            if (strtolower($table) === strtolower($prefix) ||
                                                strtolower($table) === strtolower($prefix) . 's' ||
                                                strtolower($table) === strtolower($prefix . 's')) {
                                                $deps[] = $candidate;
                                                break;
                                            }
                                        }
                                    }
                                } catch (\Throwable $e) {
                                }
                            }
                        }
                    }
                }
            }

            // Handle self-referencing relationships (hierarchical data)
            $selfRefs = $this->findSelfReferencingRelationships($m, $meta[$m]['relations'] ?? []);
            if (!empty($selfRefs)) {
                // For self-referencing, we need to ensure some base records exist first
                // Add a dependency on itself to create a small base set first
                $deps[] = $m;
            }

            // Remove duplicates and self-references, then add to adjacency list
            $deps = array_unique($deps);
            $validModels = $models; // Capture for closure
            $deps = array_filter($deps, function($dep) use ($m, $validModels) {
                return $dep !== $m && in_array($dep, $validModels, true);
            });

            foreach ($deps as $dep) {
                if (in_array($dep, $models, true)) {
                    $adj[$m][] = $dep;
                }
            }
        }

        // Perform topological sort
        $inDegree = [];
        foreach ($models as $model) {
            $inDegree[$model] = 0;
        }

        // Calculate in-degree: how many dependencies each model has
        foreach ($adj as $dependent => $dependencies) {
            foreach ($dependencies as $dependency) {
                if (isset($inDegree[$dependency])) {
                    $inDegree[$dependency]++;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $node => $deg) {
            if ($deg === 0) $queue[] = $node;
        }

        $order = [];
        while (!empty($queue)) {
            $n = array_shift($queue);
            $order[] = $n;
            foreach ($adj[$n] as $m) {
                $inDegree[$m]--;
                if ($inDegree[$m] === 0) {
                    $queue[] = $m;
                }
            }
        }

        $cycles = [];
        if (count($order) !== count($models)) {
            $remaining = array_values(array_diff($models, $order));
            $cycles = $remaining;

            // Enhanced cycle resolution: consider relationship complexity
            $remainingWithDeps = [];
            foreach ($remaining as $rem) {
                $depCount = count($adj[$rem] ?? []);
                $relCount = count($meta[$rem]['relations'] ?? []);
                $complexity = $depCount + ($relCount * 0.5); // Weight relationships
                $remainingWithDeps[] = [
                    'model' => $rem,
                    'deps' => $depCount,
                    'complexity' => $complexity
                ];
            }

            // Sort by complexity (simpler models first) then by dependency count
            usort($remainingWithDeps, function($a, $b) {
                if ($a['complexity'] === $b['complexity']) {
                    return $a['deps'] <=> $b['deps'];
                }
                return $a['complexity'] <=> $b['complexity'];
            });

            $sortedRemaining = array_column($remainingWithDeps, 'model');
            $order = array_merge($order, $sortedRemaining);
        }

        // Final pass: ensure foreign key dependencies are satisfied
        // Convert to indexed array for safe iteration
        $order = array_values($order);

        foreach ($order as $m) {
            $currentPos = array_search($m, $order);
            if (empty($meta[$m]['columns']) || !is_array($meta[$m]['columns'])) {
                continue;
            }

            foreach ($meta[$m]['columns'] as $colName => $colMeta) {
                if (substr($colName, -3) !== '_id' || $colName === 'id') continue;

                $prefix = substr($colName, 0, -3);
                foreach ($models as $candidate) {
                    $parts = explode('\\', $candidate);
                    $short = end($parts);

                    if (strtolower($short) === strtolower($prefix)) {
                        $depIndex = array_search($candidate, $order);
                        if ($depIndex !== false && $depIndex > $currentPos) {
                            // Move dependency before current model
                            array_splice($order, $depIndex, 1);
                            array_splice($order, $currentPos, 0, [$candidate]);
                            // Re-index after modification
                            $order = array_values($order);
                        }
                        break;
                    }

                    try {
                        if (class_exists($candidate)) {
                            $inst = new $candidate;
                            if (method_exists($inst, 'getTable')) {
                                $table = $inst->getTable();
                                if (strtolower($table) === strtolower($prefix) ||
                                    strtolower($table) === strtolower($prefix . 's')) {
                                    $depIndex = array_search($candidate, $order);
                                    if ($depIndex !== false && $depIndex > $currentPos) {
                                        array_splice($order, $depIndex, 1);
                                        array_splice($order, $currentPos, 0, [$candidate]);
                                        $order = array_values($order);
                                    }
                                    break;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        return ['order' => $order, 'cycles' => $cycles];
    }

    /**
     * Find models that can be morphed to by a polymorphic relationship
     */
    protected function findMorphableModels(string $modelClass, string $relationName, array $allModels): array
    {
        $morphableModels = [];

        try {
            if (!class_exists($modelClass)) {
                return $morphableModels;
            }

            $reflection = new \ReflectionClass($modelClass);
            $method = $reflection->getMethod($relationName);

            if (!$method) {
                return $morphableModels;
            }

            // Try to get morphable types from the method
            $instance = $reflection->newInstanceWithoutConstructor();
            $relation = $method->invoke($instance);

            if (method_exists($relation, 'getMorphType')) {
                $morphType = $relation->getMorphType();
                // Look for models that might be morphable to this type
                foreach ($allModels as $candidateModel) {
                    if ($candidateModel === $modelClass) {
                        continue;
                    }

                    try {
                        if (class_exists($candidateModel)) {
                            $candidateInstance = new $candidateModel;
                            if (method_exists($candidateInstance, 'getTable')) {
                                $table = $candidateInstance->getTable();
                                // Check if this model has a morph map entry or similar
                                if (strpos($morphType, $table) !== false ||
                                    strpos($table, strtolower(substr($morphType, 0, -5))) !== false) {
                                    $morphableModels[] = $candidateModel;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $morphableModels;
    }

    /**
     * Find indirect dependencies through relationship chains
     */
    protected function findIndirectDependencies(string $modelClass, array $relationshipMap, array $allModels, array $visited = []): array
    {
        $indirectDeps = [];

        // Prevent infinite recursion
        if (in_array($modelClass, $visited, true)) {
            return $indirectDeps;
        }

        $visited[] = $modelClass;

        if (!isset($relationshipMap[$modelClass])) {
            return $indirectDeps;
        }

        foreach ($relationshipMap[$modelClass] as $relation) {
            $relType = $relation['type'];
            $relatedModel = $relation['related'];

            if (empty($relatedModel) || !in_array($relatedModel, $allModels, true)) {
                continue;
            }

            // If this model belongs to another, check what that model belongs to
            if (in_array($relType, ['BelongsTo', 'belongsTo'], true) ||
                (stripos($relType, 'BelongsTo') !== false && $relType !== 'BelongsToMany')) {

                // Add the direct dependency
                if (!in_array($relatedModel, $indirectDeps, true)) {
                    $indirectDeps[] = $relatedModel;
                }

                // Recursively find indirect dependencies
                $furtherDeps = $this->findIndirectDependencies($relatedModel, $relationshipMap, $allModels, $visited);
                foreach ($furtherDeps as $furtherDep) {
                    if (!in_array($furtherDep, $indirectDeps, true) && $furtherDep !== $modelClass) {
                        $indirectDeps[] = $furtherDep;
                    }
                }
            }
        }

        return $indirectDeps;
    }

    /**
     * Find self-referencing relationships in a model
     */
    protected function findSelfReferencingRelationships(string $modelClass, array $relations): array
    {
        $selfRefs = [];

        foreach ($relations as $relation) {
            $relType = $relation['type'];
            $relatedModel = $relation['related'];

            // Check for self-referencing relationships
            if ($relatedModel === $modelClass &&
                (in_array($relType, ['BelongsTo', 'belongsTo'], true) ||
                 (stripos($relType, 'BelongsTo') !== false && $relType !== 'BelongsToMany'))) {
                $selfRefs[] = $relation;
            }
        }

        return $selfRefs;
    }

    /**
     * Find dependencies for HasOneThrough and HasManyThrough relationships
     */
    protected function findThroughDependencies(string $modelClass, array $relation, array $allModels): array
    {
        $throughDeps = [];

        try {
            if (!class_exists($modelClass)) {
                return $throughDeps;
            }

            $reflection = new \ReflectionClass($modelClass);
            $method = $reflection->getMethod($relation['name']);

            if (!$method) {
                return $throughDeps;
            }

            $instance = $reflection->newInstanceWithoutConstructor();
            $throughRelation = $method->invoke($instance);

            if (method_exists($throughRelation, 'getThroughParents')) {
                // Get the intermediate models in the through relationship
                $throughParents = $throughRelation->getThroughParents();
                foreach ($throughParents as $throughParent) {
                    $throughModel = get_class($throughParent);
                    if (in_array($throughModel, $allModels, true)) {
                        $throughDeps[] = $throughModel;
                    }
                }
            }

            // Also add the final related model
            $relatedModel = $relation['related'];
            if ($relatedModel && in_array($relatedModel, $allModels, true)) {
                $throughDeps[] = $relatedModel;
            }
        } catch (\Throwable $e) {
        }

        return $throughDeps;
    }

    /**
     * Find dependencies for MorphToMany and MorphedByMany relationships
     */
    protected function findMorphToManyDependencies(string $modelClass, array $relation, array $allModels): array
    {
        $morphDeps = [];

        try {
            if (!class_exists($modelClass)) {
                return $morphDeps;
            }

            $reflection = new \ReflectionClass($modelClass);
            $method = $reflection->getMethod($relation['name']);

            if (!$method) {
                return $morphDeps;
            }

            $instance = $reflection->newInstanceWithoutConstructor();
            $morphRelation = $method->invoke($instance);

            // For MorphToMany, we need the related model
            $relatedModel = $relation['related'];
            if ($relatedModel && in_array($relatedModel, $allModels, true)) {
                $morphDeps[] = $relatedModel;
            }

            // For MorphedByMany (inverse), we also need to consider morphable models
            if (method_exists($morphRelation, 'getMorphType')) {
                $morphType = $morphRelation->getMorphType();
                // Look for models that might be morphable to this type
                foreach ($allModels as $candidateModel) {
                    if ($candidateModel === $modelClass) {
                        continue;
                    }

                    try {
                        if (class_exists($candidateModel)) {
                            $candidateInstance = new $candidateModel;
                            if (method_exists($candidateInstance, 'getTable')) {
                                $table = $candidateInstance->getTable();
                                // Check if this model has a morph map entry or similar
                                if (strpos($morphType, $table) !== false ||
                                    strpos($table, strtolower(substr($morphType, 0, -5))) !== false) {
                                    $morphDeps[] = $candidateModel;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $morphDeps;
    }

    public function writeSeeder(string $seederCode, string $modelClass, bool $force = false)
    {
    $parts = explode('\\', $modelClass);
    $short = end($parts);
    $className = $short . 'Seeder';
        $file = $this->seedersPath . DIRECTORY_SEPARATOR . $className . '.php';
        if (file_exists($file) && !$force) {
            return false;
        }

        file_put_contents($file, $seederCode);
        return true;
    }

    public function updateDatabaseSeeder(array $orderedModels, bool $force = false)
    {
        $dbSeeder = $this->seedersPath . DIRECTORY_SEPARATOR . 'DatabaseSeeder.php';

        // Check if DatabaseSeeder exists and might have been manually modified
        if (file_exists($dbSeeder)) {
            $existingContent = file_get_contents($dbSeeder);

            // Check for indicators of manual modification
            $manualIndicators = [
                'FIXED:',
                'Correct seeding order',
                'manually modified',
                'custom order',
                '// CUSTOM:',
                '// MANUAL:'
            ];

            $isManuallyModified = false;
            foreach ($manualIndicators as $indicator) {
                if (strpos($existingContent, $indicator) !== false) {
                    $isManuallyModified = true;
                    break;
                }
            }

            // If manually modified, skip updating unless explicitly forced with special flag
            if ($isManuallyModified) {
                return false; // Never overwrite manually modified DatabaseSeeder
            }

            // Check if the existing file has a different structure than auto-generated
            if (strpos($existingContent, 'Two-phase seeding: phase1') === false) {
                // File doesn't match expected auto-generated structure, likely manual
                return false;
            }

            // Check if the existing order is already correct
            $expectedOrder = [];
            foreach ($orderedModels as $m) {
                $parts = explode('\\', $m);
                $short = end($parts);
                $expectedOrder[] = $short . 'Seeder';
            }

            $currentOrder = [];
            if (preg_match_all('/(\w+)Seeder::class/', $existingContent, $matches)) {
                $currentOrder = $matches[1];
                // Add 'Seeder' suffix to match expected format
                $currentOrder = array_map(function($item) { return $item . 'Seeder'; }, $currentOrder);
            }

            // If the order is already correct, don't overwrite
            if (!empty($currentOrder) && $currentOrder === $expectedOrder) {
                return false;
            }
        }

        $pairs = [];
        foreach ($orderedModels as $m) {
            $parts = explode('\\', $m);
            $short = end($parts);
            $seederClass = "\\Database\\Seeders\\" . $short . "Seeder::class";
            $modelClass = $m;
            $pairs[] = "[ 'seeder' => {$seederClass}, 'model' => {$modelClass}::class ]";
        }
        $pairsCode = implode(",\n            ", $pairs);

        // Only skip if file exists and not forcing (original logic)
        if (file_exists($dbSeeder) && !$force) {
            return false;
        }

    $uses = "use Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;";

        $code = <<<PHP
<?php

{$uses}

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // Two-phase seeding: phase1 (create base records) then phase2 (assign relations/polymorphics)
        \$pairs = [
            {$pairsCode}
        ];

        try {
            \$driver = DB::getDriverName();
            if (\$driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            } elseif (\$driver === 'pgsql') {
                // For PostgreSQL, we need to disable triggers that enforce foreign keys
                DB::statement('SET session_replication_role = replica');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            }
        } catch (\\Throwable \$e) {
        }

        // Phase 1: create base records (skip seeders when their model table doesn't exist)
        foreach (\$pairs as \$entry) {
            try {
                \$model = \$entry['model'];
            } catch (\Throwable \$e) { continue; }
            if (!class_exists(\$model)) { continue; }
            try {
                \$mInst = new \$model;
                \$tbl = method_exists(\$mInst, 'getTable') ? \$mInst->getTable() : null;
            } catch (\Throwable \$e) { \$tbl = null; }
            if (\$tbl && !Schema::hasTable(\$tbl)) { continue; }
            try {
                \$sclass = \$entry['seeder'];
                \$inst = new \$sclass;
            } catch (\Throwable \$e) { continue; }
            if (method_exists(\$inst, 'runPhase1')) {
                \$inst->runPhase1();
            } else {
                \$inst->run();
            }
        }

        // Phase 2: assign relations that require existing records (skip when model table missing)
        foreach (\$pairs as \$entry) {
            try {
                \$model = \$entry['model'];
            } catch (\Throwable \$e) { continue; }
            if (!class_exists(\$model)) { continue; }
            try {
                \$mInst = new \$model;
                \$tbl = method_exists(\$mInst, 'getTable') ? \$mInst->getTable() : null;
            } catch (\Throwable \$e) { \$tbl = null; }
            if (\$tbl && !Schema::hasTable(\$tbl)) { continue; }
            try {
                \$sclass = \$entry['seeder'];
                \$inst = new \$sclass;
            } catch (\Throwable \$e) { continue; }
            if (method_exists(\$inst, 'runPhase2')) {
                \$inst->runPhase2();
            }
        }

        try {
            if (isset(\$driver) && \$driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } elseif (isset(\$driver) && \$driver === 'pgsql') {
                // Re-enable triggers for PostgreSQL
                DB::statement('SET session_replication_role = DEFAULT');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (\\Throwable \$e) {
        }
    }
}
PHP;

        file_put_contents($dbSeeder, $code);
        return true;
    }
}
