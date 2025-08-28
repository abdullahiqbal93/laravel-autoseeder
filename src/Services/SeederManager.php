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

        foreach ($models as $m) {
            $deps = [];
            foreach ($meta[$m]['relations'] as $rel) {
                if (in_array($rel['type'], ['BelongsTo', 'belongsTo'], true) || stripos($rel['type'], 'BelongsTo') !== false) {
                    if (!empty($rel['related'])) {
                        $deps[] = $rel['related'];
                    }
                }
            }

            if (!empty($meta[$m]['columns']) && is_array($meta[$m]['columns'])) {
                foreach ($meta[$m]['columns'] as $colName => $colMeta) {
                    if (substr($colName, -3) === '_id') {
                        $prefix = substr($colName, 0, -3);
                        foreach ($models as $candidate) {
                            $candidateShort = (strpos($candidate, '\\') !== false) ? substr($candidate, strrpos($candidate, '\\') + 1) : $candidate;
                            if (strtolower($candidateShort) === strtolower($prefix)) {
                                $deps[] = $candidate;
                                break;
                            }

                            try {
                                if (class_exists($candidate)) {
                                    $inst = new $candidate;
                                    if (method_exists($inst, 'getTable')) {
                                        $table = $inst->getTable();
                                        if (strtolower($table) === strtolower($prefix) || strtolower($table) === strtolower($prefix) . 's' || strtolower($table) === strtolower($prefix . 's')) {
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

            foreach ($deps as $dep) {
                if (in_array($dep, $models, true)) {
                    $adj[$m][] = $dep; 
                }
            }
        }

        $inDegree = [];
        foreach ($adj as $u => $neighbors) {
            if (!isset($inDegree[$u])) $inDegree[$u] = 0;
            foreach ($neighbors as $v) {
                if (!isset($inDegree[$v])) $inDegree[$v] = 0;
                $inDegree[$v]++;
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
            $order = array_merge($order, $remaining);
        }

        $index = array_flip($order);
        foreach ($order as $pos => $m) {
            if (empty($meta[$m]['columns']) || !is_array($meta[$m]['columns'])) {
                continue;
            }
            foreach ($meta[$m]['columns'] as $colName => $colMeta) {
                if (substr($colName, -3) !== '_id') continue;
                $prefix = substr($colName, 0, -3);
                foreach ($models as $candidate) {
                    $parts = explode('\\', $candidate);
                    $short = end($parts);
                    if (strtolower($short) === strtolower($prefix)) {
                        if (isset($index[$candidate]) && $index[$candidate] > $pos) {
                            array_splice($order, $index[$candidate], 1);
                            array_splice($order, $pos, 0, [$candidate]);
                            $index = array_flip($order);
                        }
                        break;
                    }
                    try {
                        if (class_exists($candidate)) {
                            $inst = new $candidate;
                            if (method_exists($inst, 'getTable')) {
                                $table = $inst->getTable();
                                if (strtolower($table) === strtolower($prefix) || strtolower($table) === strtolower($prefix . 's')) {
                                    if (isset($index[$candidate]) && $index[$candidate] > $pos) {
                                        array_splice($order, $index[$candidate], 1);
                                        array_splice($order, $pos, 0, [$candidate]);
                                        $index = array_flip($order);
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
        $pairs = [];
        foreach ($orderedModels as $m) {
            $parts = explode('\\', $m);
            $short = end($parts);
            $seederClass = "\\Database\\Seeders\\" . $short . "Seeder::class";
            $modelClass = $m;
            $pairs[] = "[ 'seeder' => {$seederClass}, 'model' => {$modelClass}::class ]";
        }
        $pairsCode = implode(",\n            ", $pairs);

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
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            }
        } catch (\\Throwable \$e) {
            // ignore
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
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (\\Throwable \$e) {
            // ignore
        }
    }
}
PHP;

        file_put_contents($dbSeeder, $code);
        return true;
    }
}
