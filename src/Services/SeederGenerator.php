<?php

namespace Dedsec\LaravelAutoSeeder\Services;

use Faker\Factory as FakerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SeederGenerator
{
    protected $schema;
    protected $relDetector;
    protected $defaultLimit = 10;
    protected $faker;
    protected $currentModel = null;
    protected $currentTable = null;

    public function __construct(SchemaAnalyzer $schema, RelationshipDetector $relDetector, int $limit = 10)
    {
        $this->schema = $schema;
        $this->relDetector = $relDetector;
        $this->defaultLimit = $limit;
        $this->faker = FakerFactory::create();
    }

    public function generateForModel(string $modelClass, array $columns, array $relations, array $allMeta = []): string
    {
    $this->currentModel = $modelClass;
    try { $this->currentTable = (new $modelClass)->getTable(); } catch (\Throwable $e) { $this->currentTable = null; }

    $partsForClass = explode('\\', $modelClass);
    $shortForClass = end($partsForClass);
    $className = $shortForClass . 'Seeder';
        $limit = $this->defaultLimit;

    $belongsToMap = [];
        foreach ($relations as $r) {
            if (stripos($r['type'], 'BelongsTo') !== false || stripos($r['type'], 'belongsTo') !== false) {
                $belongsToMap[$r['name']] = $r['related'];
            }
        }

    $propsAssoc = [];
    $uniqueFkAvailable = []; 
    $uniqueFkSource = [];
    foreach ($columns as $col => $meta) {
            if ($meta['autoincrement'] ?? false) {
                continue;
            }
            if (in_array($col, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            if (substr($col, -5) === '_type') {
                continue;
            }

            $expr = null;
            if (substr($col, -3) === '_id') {
                $relName = substr($col, 0, -3);
                if (isset($columns[$relName . '_type'])) {
                    continue;
                }
                    $relatedClass = null;

                    if (isset($belongsToMap[$relName]) && !empty($belongsToMap[$relName])) {
                        $relatedClass = $belongsToMap[$relName];
                    } else {
                        foreach ($relations as $r2) {
                            if (!empty($r2['related'])) {
                                $parts = explode('\\', $r2['related']);
                                $rShort = end($parts);
                                if (strtolower($rShort) === strtolower($relName)) {
                                    $relatedClass = $r2['related'];
                                    break;
                                }
                            }
                        }
                    }

                    if (!empty($relatedClass)) {
                        $candidate = ltrim($relatedClass, '\\');
                            $testClass = $candidate;
                            if (!class_exists($testClass)) {
                                $guesses = [
                                    'App\\Models\\' . ucfirst($relName),
                                    'App\\Models\\' . ucfirst(Str::singular($relName)),
                                ];
                                foreach ($guesses as $g) {
                                    if (class_exists($g)) { $testClass = $g; break; }
                                }
                            }

                            if (class_exists($testClass)) {
                                $fqOut = '\\' . ltrim($testClass, '\\');
                                        if (!empty($meta['unique']) || (!empty($meta['unique_indexes']) && count($meta['unique_indexes']) === 1 && count($meta['unique_indexes'][0]) === 1)) {
                                            $uniqueFkAvailable[$col] = "\\{$fqOut}::pluck('id')->toArray()";
                                            $uniqueFkSource[$col] = ['type' => 'model', 'class' => '\\' . ltrim($testClass, '\\')];
                                            $nullable = $meta['nullable'] ?? false;
                                            if ($nullable) {
                                                $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : ({$fqOut}::count() > 0 ? {$fqOut}::inRandomOrder()->first()->id : null))";
                                            } else {
                                                $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : ({$fqOut}::count() > 0 ? {$fqOut}::inRandomOrder()->first()->id : 1))";
                                            }
                                        } else {
                                            $nullable = $meta['nullable'] ?? false;
                                            if ($nullable) {
                                                $expr = "({$fqOut}::count() > 0 ? {$fqOut}::inRandomOrder()->first()->id : null)";
                                            } else {
                                                $expr = "({$fqOut}::count() > 0 ? {$fqOut}::inRandomOrder()->first()->id : 1)";
                                            }
                                        }
                            } else {
                                $lower = strtolower($relName);
                                $userAliases = ['owner','user','author','creator','created_by','assigned_to'];
                                if (in_array($lower, $userAliases, true) && class_exists('App\\Models\\User')) {
                                    if (!empty($meta['unique'])) {
                                        $uniqueFkAvailable[$col] = "\\App\\Models\\User::pluck('id')->toArray()";
                                        $uniqueFkSource[$col] = ['type' => 'model', 'class' => '\\App\\Models\\User'];
                                        $nullable = $meta['nullable'] ?? false;
                                        if ($nullable) {
                                            $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : (\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : null))";
                                        } else {
                                            $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : (\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : 1))";
                                        }
                                    } else {
                                        $nullable = $meta['nullable'] ?? false;
                                        if ($nullable) {
                                            $expr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : null)";
                                        } else {
                                            $expr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : 1)";
                                        }
                                    }
                                } else {
                                    $tableGuess = Str::plural($relName);
                                    if (!empty($meta['unique'])) {
                                        $uniqueFkAvailable[$col] = "DB::table('{$tableGuess}')->pluck('id')->toArray()";
                                        $uniqueFkSource[$col] = ['type' => 'table', 'table' => $tableGuess];
                                        $nullable = $meta['nullable'] ?? false;
                                        if ($nullable) {
                                            $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : (DB::table('{$tableGuess}')->count() > 0 ? DB::table('{$tableGuess}')->inRandomOrder()->first()->id : null))";
                                        } else {
                                            $expr = "(!empty(\$__available_{$col}) ? array_splice(\$__available_{$col}, array_rand(\$__available_{$col}), 1)[0] : (DB::table('{$tableGuess}')->count() > 0 ? DB::table('{$tableGuess}')->inRandomOrder()->first()->id : 1))";
                                        }
                                    } else {
                                        $nullable = $meta['nullable'] ?? false;
                                        if ($nullable) {
                                            $expr = "(DB::table('{$tableGuess}')->count() > 0 ? DB::table('{$tableGuess}')->inRandomOrder()->first()->id : null)";
                                        } else {
                                            $expr = "(DB::table('{$tableGuess}')->count() > 0 ? DB::table('{$tableGuess}')->inRandomOrder()->first()->id : 1)";
                                        }
                                    }
                                }
                            }
                    }
            }

            if ($expr === null) {
                $expr = $this->phpValueForColumn($col, $meta);
            }
            $propsAssoc[$col] = "'" . $col . "' => " . $expr;
        }
        $selfRelation = false;
        foreach ($relations as $rcheck) {
            if (!empty($rcheck['related'])) {
                $partsRel = explode('\\', trim($rcheck['related'], '\\'));
                $relatedShort = end($partsRel);
                $partsModel = explode('\\', trim($modelClass, '\\'));
                $modelShort = end($partsModel);
                if (strtolower($relatedShort) === strtolower($modelShort)) {
                    $selfRelation = true;
                    break;
                }
            }
        }

        $skippedSelfColumns = [];
        if ($selfRelation) {
            $partsModel = explode('\\', trim($modelClass, '\\'));
            $modelShort = end($partsModel);
            foreach ($belongsToMap as $relName => $relatedClass) {
                if (empty($relatedClass)) continue;
                $partsRel = explode('\\', ltrim($relatedClass, '\\'));
                $relatedShort = end($partsRel);
                if (strtolower($relatedShort) === strtolower($modelShort)) {
                    $colName = $relName . '_id';
                    if (isset($propsAssoc[$colName])) {
                        $skippedSelfColumns[] = $colName;
                        unset($propsAssoc[$colName]);
                    }
                }
            }
        }

    $polyPrepCode = '';
    foreach ($columns as $col => $meta) {
        if (substr($col, -5) !== '_type') continue;
        $base = substr($col, 0, -5);
        $idcol = $base . '_id';
        if (!isset($columns[$idcol])) continue;

        $candidates = [];
        foreach ($allMeta as $mclass => $mdata) {
            foreach ($mdata['relations'] as $r) {
                $rtype = strtolower($r['type'] ?? '');
                if (strpos($rtype, 'morph') !== false) {
                    $candidates[] = ltrim($mclass, '\\');
                }
            }
        }

        $guess = ucfirst($base);
        foreach (array_keys($allMeta) as $mc) {
            $parts = explode('\\', trim($mc, '\\'));
            $short = end($parts);
            if (strtolower($short) === strtolower($guess) || strtolower($short) === strtolower(Str::singular($guess))) {
                $candidates[] = $mc;
            }
        }

        if (empty($candidates)) {
            $candidates[] = 'App\\Models\\User';
        }

        $candidates = array_values(array_unique($candidates));
        $candidates = array_values(array_filter($candidates, function($c) use ($modelClass) {
            return ltrim($c, '\\') !== ltrim($modelClass, '\\');
        }));

        $varType = '__' . $base . '_type';
        $varId = '__' . $base . '_id';
        $varSkip = '__' . $base . '_type_skip';

    $candArray = implode(', ', array_map(function ($c) { return "'" . addslashes($c) . "'"; }, $candidates));
    $polyPrepCode .= "\n                // prepare polymorphic {$base} candidates (try each candidate safely)\n";
    $polyPrepCode .= "                \$" . $varType . "_c = [{$candArray}];\n";
    $polyPrepCode .= "                \$" . $varType . " = null; \$" . $varId . " = null; \$" . $varSkip . " = true;\n";
    $polyPrepCode .= "                foreach (\$" . $varType . "_c as \$__cand) {\n";
    $polyPrepCode .= "                    try {\n";
    $polyPrepCode .= "                        if (class_exists(\$__cand)) {\n";
    $polyPrepCode .= "                            \$__ids_tmp = \$__cand::pluck('id')->toArray();\n";
    $polyPrepCode .= "                        } else {\n";
    $polyPrepCode .= "                            // treat candidate as class that can provide table via new \$__cand or as table name\n";
    $polyPrepCode .= "                            try { \$inst = new \$__cand; \$tbl = method_exists(\$inst, 'getTable') ? \$inst->getTable() : null; } catch (\\Throwable \$e) { \$tbl = null; }\n";
    $polyPrepCode .= "                            \$__ids_tmp = \$tbl ? DB::table(\$tbl)->pluck('id')->toArray() : [];\n";
    $polyPrepCode .= "                        }\n";
    $polyPrepCode .= "                    } catch (\\Throwable \$e) { \$__ids_tmp = []; }\n";
    $polyPrepCode .= "                    if (!empty(\$__ids_tmp)) { \$" . $varType . " = \$__cand; \$" . $varId . " = \$__ids_tmp[array_rand(\$__ids_tmp)]; \$" . $varSkip . " = false; break; }\n";
    $polyPrepCode .= "                }\n";
    $polyPrepCode .= "                // if no candidate found, fall back to configured placeholder (e.g. App\\Models\\User)\n";
        

        $fallback = '\\App\\Models\\User';
        if (!isset($propsAssoc[$col])) {
            $propsAssoc[$col] = "'{$col}' => '{$fallback}'";
        }
        if (!isset($propsAssoc[$idcol])) {
            $nullable = $columns[$idcol]['nullable'] ?? false;
            if ($nullable) {
                $userExpr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : null)";
            } else {
                $userExpr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : 1)";
            }
            $propsAssoc[$idcol] = "'{$idcol}' => " . $userExpr;
        }

        $polyInLoopUpdate = isset($polyInLoopUpdate) ? $polyInLoopUpdate : '';
        $polyInLoopUpdate .= "                if (isset(\$" . $varSkip . ") && !\$" . $varSkip . ") { try { \$created->update(['{$col}' => \$" . $varType . ", '{$idcol}' => \$" . $varId . "]); } catch (\\Throwable \$e) { } }\n";
    }

    $propsLines = [];
    foreach ($propsAssoc as $col => $codeExpr) {
        $propsLines[] = '                ' . $codeExpr;
    }
    $propsCode = implode(",\n", $propsLines);

    $uniqueCols = [];
    foreach ($columns as $cname => $cmeta) {
        $isUniqueMeta = $cmeta['unique'] ?? false;
        $lc = strtolower($cname);
        $heuristicUnique = $isUniqueMeta || preg_match('/(^|_)email($|_)/i', $cname) || stripos($cname, 'code') !== false || stripos($cname, 'sku') !== false || stripos($cname, 'slug') !== false;
        if ($heuristicUnique) {
            $uniqueCols[] = $cname;
        }
    }

    $compositeUniques = [];
    foreach ($columns as $cname => $cmeta) {
        if (!empty($cmeta['unique_indexes']) && is_array($cmeta['unique_indexes'])) {
            foreach ($cmeta['unique_indexes'] as $uix) {
                if (is_array($uix) && count($uix) > 1) {
                    $keys = $uix;
                    sort($keys);
                    $k = implode('|', $keys);
                    $compositeUniques[$k] = $keys;
                }
            }
        }
    }
    $compositeUniques = array_values($compositeUniques);

        $this->currentModel = null;
        $this->currentTable = null;


        $belongsToMany = array_filter($relations, function ($r) {
            return stripos($r['type'], 'BelongsToMany') !== false || stripos($r['type'], 'belongsToMany') !== false;
        });

        $attachCode = '';
        if (!empty($belongsToMany)) {
            $attachLines = [];
            foreach ($belongsToMany as $rel) {
                if (empty($rel['related']))
                    continue;
                $relatedClass = $rel['related'];
                $method = $rel['name'];

                $rc = ltrim($relatedClass, '\\');
                $attachLines[] = "            // attach {$method}\n" .
                    "            \$relatedIds = \\{$rc}::pluck('id')->toArray();\n" .
                    "            if (!empty(\$relatedIds)) {\n" .
                    "                \$toAttach = [];\n" .
                    "                \$num = rand(1, min(5, max(1, count(\$relatedIds))));\n" .
                    "                shuffle(\$relatedIds);\n" .
                    "                \$toAttach = array_slice(\$relatedIds, 0, \$num);\n" .
                    "                if (!empty(\$toAttach)) {\n" .
                    "                    foreach (array_chunk(\$toAttach, 100) as \$chunk) {\n" .
                    "                        // use syncWithoutDetaching to avoid duplicate pivot row insertions\n" .
                    "                        \$created->{$method}()->syncWithoutDetaching(\$chunk);\n" .
                    "                    }\n" .
                    "                }\n" .
                    "            }";
            }

            if (!empty($attachLines)) {
                $attachCode = "\n" . implode("\n", $attachLines) . "\n";
            }
        }

                                $polySecondPhaseBlocks = [];
                                foreach ($columns as $col => $meta) {
                                    if (substr($col, -5) !== '_type') continue;
                                    $base = substr($col, 0, -5);
                                    $idcol = $base . '_id';
                                    if (!isset($columns[$idcol])) continue;

                                    $candidates = [];
                                    foreach ($allMeta as $mclass => $mdata) {
                                        foreach ($mdata['relations'] as $r) {
                                            $rtype = strtolower($r['type'] ?? '');
                                            if (strpos($rtype, 'morph') !== false) {
                                                $candidates[] = ltrim($mclass, '\\');
                                            }
                                        }
                                    }

                                    $guess = ucfirst($base);
                                    foreach (array_keys($allMeta) as $mc) {
                                        $parts = explode('\\', trim($mc, '\\'));
                                        $short = end($parts);
                                        if (strtolower($short) === strtolower($guess) || strtolower($short) === strtolower(Str::singular($guess))) {
                                            $candidates[] = $mc;
                                        }
                                    }

                                    if (empty($candidates)) {
                                        $candidates[] = 'App\\Models\\User';
                                    }
                                    $candidates = array_values(array_unique($candidates));

                                    $fallback = '\\App\\Models\\User';
                                    $nullable = $columns[$idcol]['nullable'] ?? false;
                                    if ($nullable) {
                                        $userExpr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : null)";
                                    } else {
                                        $userExpr = "(\\App\\Models\\User::count() > 0 ? \\App\\Models\\User::inRandomOrder()->first()->id : 1)";
                                    }
                                    if (!isset($propsAssoc[$col])) {
                                        $propsAssoc[$col] = "'{$col}' => '{$fallback}'";
                                    }
                                    if (!isset($propsAssoc[$idcol])) {
                                        $propsAssoc[$idcol] = "'{$idcol}' => " . $userExpr;
                                    }

                                    $candArray = implode(', ', array_map(function ($c) { return "'" . addslashes($c) . "'"; }, $candidates));
                                    $sp = "                // assign polymorphic {$base} after records exist\n";
                                    $sp .= "                \$cands = [{$candArray}];\n";
                                    $sp .= "                if (!empty(\$cands) && !empty({$shortForClass}::pluck('id')->toArray())) {\n";
                                    $sp .= "                    {$shortForClass}::chunkById(100, function(\$rows) use (\$cands) {\n";
                                    $sp .= "                        foreach (\$rows as \$rec) {\n";
                                    $sp .= "                            \$t = \$cands[array_rand(\$cands)];\n";
                                    $sp .= "                            try {\n";
                                    $sp .= "                                if (class_exists(\$t)) { \$ids = \$t::pluck('id')->toArray(); } else { try { \$inst = new \$t; \$tbl = method_exists(\$inst,'getTable') ? \$inst->getTable() : null; } catch (\\Throwable \$e) { \$tbl = null; } \$ids = \$tbl ? DB::table(\$tbl)->pluck('id')->toArray() : []; }\n";
                                    $sp .= "                                if (!empty(\$ids)) { \$rec->update(['{$col}' => \$t, '{$idcol}' => \$ids[array_rand(\$ids)]]); }\n";
                                    $sp .= "                            } catch (\\Throwable \$e) { }\n";
                                    $sp .= "                        }\n";
                                    $sp .= "                    });\n";
                                    $sp .= "                }";

                                    $polySecondPhaseBlocks[] = $sp;
                                }
    $parts = explode('\\', $modelClass);
    $shortModel = end($parts);

        $uses = [
            'Illuminate\\Database\\Seeder',
            trim($modelClass, '\\'),
            'Illuminate\\Support\\Facades\\DB',
            'Faker\\Factory as FakerFactory',
            'Carbon\\Carbon',
            'Illuminate\\Support\\Str',
        ];

        $usesCode = implode("\n", array_map(function ($u) {
            return "use {$u};";
        }, $uses));


        if (!empty($attachCode)) {
            $attachCode = trim($attachCode, "\n");
            $attachCode = preg_replace('/^/m', '                ', $attachCode);
            $attachCode = PHP_EOL . $attachCode . PHP_EOL;
        }

        $secondPhase = '';
        if (!empty($skippedSelfColumns)) {
            $spLines = [];
            foreach ($skippedSelfColumns as $scol) {
                $spLines[] = "                // assign {$scol} after records exist\n" .
                    "                if (!empty({$shortModel}::pluck('id')->toArray())) {\n" .
                    "                    \$all = {$shortModel}::pluck('id')->toArray();\n" .
                    "                    foreach ({$shortModel}::whereNull('{$scol}')->get() as \$rec) {\n" .
                    "                        \$rec->update(['{$scol}' => !empty(\$all) ? \$all[array_rand(\$all)] : 1]);\n" .
                    "                    }\n" .
                    "                }";
            }

            if (!empty($spLines)) {
                $secondPhase = PHP_EOL . implode("\n", $spLines) . PHP_EOL;
            }
        }

    $phase1 = "";
    $phase1 .= "        \$faker = FakerFactory::create();\n\n";
    if (!empty($uniqueFkAvailable)) {
        foreach ($uniqueFkAvailable as $ucol => $initExpr) {
            $phase1 .= "        \$__available_{$ucol} = {$initExpr} ?: [];\n";
            $src = var_export($uniqueFkSource[$ucol] ?? null, true);
            $phase1 .= "        \$__src_{$ucol} = {$src};\n";
            $phase1 .= "        if (empty(\$__available_{$ucol})) {\n";
            $phase1 .= "            try {\n";
            $phase1 .= "                if (!empty(\$__src_{$ucol}) && is_array(\$__src_{$ucol})) {\n";
            $phase1 .= "                    if (isset(\$__src_{$ucol}['type']) && \$__src_{$ucol}['type'] === 'model' && isset(\$__src_{$ucol}['class']) && class_exists(\$__src_{$ucol}['class'])) {\n";
            $phase1 .= "                        // Try to create a minimal placeholder record with required fields\n";
            $phase1 .= "                        try {\n";
            $phase1 .= "                            \$minimalData = [];\n";
            $phase1 .= "                            // Get the model's fillable or guarded properties to create minimal data\n";
            $phase1 .= "                            \$reflection = new \\ReflectionClass(\$__src_{$ucol}['class']);\n";
            $phase1 .= "                            \$fillable = \$reflection->getProperty('fillable')->getValue(new (\$__src_{$ucol}['class']));\n";
            $phase1 .= "                            if (!empty(\$fillable)) {\n";
            $phase1 .= "                                foreach (\$fillable as \$field) {\n";
            $phase1 .= "                                    if (\$field !== 'id' && \$field !== 'created_at' && \$field !== 'updated_at') {\n";
            $phase1 .= "                                        \$minimalData[\$field] = null; // Use null for minimal placeholder\n";
            $phase1 .= "                                    }\n";
            $phase1 .= "                                }\n";
            $phase1 .= "                            }\n";
            $phase1 .= "                            if (!empty(\$minimalData)) {\n";
            $phase1 .= "                                \$created = (\$__src_{$ucol}['class'])::create(\$minimalData);\n";
            $phase1 .= "                                if (\$created) { \$__available_{$ucol} = [(int)\$created->id]; }\n";
            $phase1 .= "                            }\n";
            $phase1 .= "                        } catch (\\Throwable \$e) { /* ignore */ }\n";
            $phase1 .= "                    } elseif (isset(\$__src_{$ucol}['type']) && \$__src_{$ucol}['type'] === 'table' && isset(\$__src_{$ucol}['table'])) {\n";
            $phase1 .= "                        // For table-based FK, skip placeholder creation as it's complex\n";
            $phase1 .= "                        // The FK fallback will use ID 1 or null instead\n";
            $phase1 .= "                    }\n";
            $phase1 .= "                }\n";
            $phase1 .= "            } catch (\\Throwable \$e) { if (function_exists('fwrite') && defined('STDERR')) { fwrite(STDERR, 'placeholder parent create failed for {$ucol}: ' . \$e->getMessage() . PHP_EOL); } }\n";
            $phase1 .= "            if (empty(\$__available_{$ucol})) { if (function_exists('fwrite') && defined('STDERR')) { fwrite(STDERR, 'unique fk pool empty for {$ucol} after placeholder attempt' . PHP_EOL); } }\n";
            $phase1 .= "        }\n";
        }
        $phase1 .= "\n";
    }
    if (!empty($uniqueCols)) {
        $phase1 .= "        // per-run uniqueness guards (limited to prevent memory issues)\n";
        foreach ($uniqueCols as $uc) {
            $phase1 .= "        \$__used_" . $uc . " = [];\n";
        }
        $phase1 .= "\n";
    }
    $phase1 .= "        for (\$i = 0; \$i < {$limit}; ++\$i) {\n";
    if (!empty($polyPrepCode)) {
        $polyPrepCodeIndented = preg_replace('/^/m', '            ', trim($polyPrepCode));
        $phase1 .= $polyPrepCodeIndented . "\n\n";
    }

    $phase1 .= "            \$tries = 0; \$created = null;\n";
    $phase1 .= "            while (\$tries < 5 && !isset(\$created)) {\n";
    $phase1 .= "                try {\n";
    $phase1 .= "                    \$payload = [\n{$propsCode}\n                    ];\n";
    if (!empty($compositeUniques)) {
        foreach ($compositeUniques as $uix) {
            $conds = [];
            foreach ($uix as $ucol) {
                $conds[] = "->where('" . addslashes($ucol) . "', \$payload['" . addslashes($ucol) . "'])";
            }
            $phase1 .= "                    // composite-unique check for: " . implode(', ', $uix) . "\n";
            $phase1 .= "                    \$tableName = '" . addslashes((new $modelClass)->getTable()) . "';\n";
            $phase1 .= "                    if (DB::table(\$tableName)" . implode('', $conds) . "->exists()) { \$tries++; continue; }\n";
        }
    }
    $phase1 .= "                    \$created = {$shortModel}::create(\$payload);\n";
            if (!empty($uniqueCols)) {
                $precheckLines = [];
                foreach ($uniqueCols as $uc) {
                    $precheckLines[] = "                    if (isset(\$__used_{$uc}) && isset(\$payload['{$uc}']) && in_array(\$payload['{$uc}'], \$__used_{$uc}, true)) { \$tries++; continue; }";
                }
                if (!empty($precheckLines)) {
                    $phase1 = str_replace("                    \$created = {$shortModel}::create(\$payload);\n", implode("\n", $precheckLines) . "\n                    \$created = {$shortModel}::create(\$payload);\n", $phase1);
                }
            }
    $phase1 .= "                } catch (\\Throwable \$e) {\n";
    $phase1 .= "                    \$tries++;\n";
    $phase1 .= "                    if (\$tries >= 5) {\n";
    $phase1 .= "                        if (function_exists('fwrite') && defined('STDERR')) { fwrite(STDERR, \"Seeder error in {$shortModel}: \" . \$e->getMessage() . \"\\n\"); } else { echo \"Seeder error in {$shortModel}: \" . \$e->getMessage() . \"\\n\"; }\n";
    $phase1 .= "                        break;\n";
    $phase1 .= "                    }\n";
    $phase1 .= "                }\n";
    $phase1 .= "            }\n\n";
    $phase1 .= "            if (isset(\$created) && \$created) {\n";
    if (!empty($polyInLoopUpdate)) {
        $phase1 .= $polyInLoopUpdate;
    }
    if (!empty($uniqueCols)) {
        foreach ($uniqueCols as $uc) {
            $phase1 .= "                try { if (isset(\$created->{$uc})) { \$__used_{$uc}[] = \$created->{$uc}; } } catch (\\Throwable \$e) { }\n";
        }
    }
    $phase1 .= "{$attachCode}            }\n";
    $phase1 .= "        }\n";

    $polySecond = '';
    if (!empty($polySecondPhaseBlocks)) {
        $polySecond = PHP_EOL . implode(PHP_EOL . PHP_EOL, $polySecondPhaseBlocks) . PHP_EOL;
    }

    $secondPhaseCombined = '';
    if (!empty($secondPhase)) {
        $secondPhaseCombined .= PHP_EOL . $secondPhase . PHP_EOL;
    }
    $secondPhaseCombined .= $polySecond;

    $phase2 = '';
    if (!empty(trim($secondPhaseCombined))) {
        $phase2 .= "        // second-phase relationship assignments\n";
        $phase2 .= $secondPhaseCombined;
    }

    $code = <<<'PHP'
<?php

namespace Database\Seeders;

{USES_CODE}

class {CLASS_NAME} extends Seeder
{
    public function run(): void
    {
        // Backwards-compatible: run phase1 then phase2
        try {
            DB::beginTransaction();
            if (method_exists($this, 'runPhase1')) { $this->runPhase1(); }
            if (method_exists($this, 'runPhase2')) { $this->runPhase2(); }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            if (function_exists('fwrite') && defined('STDERR')) {
                fwrite(STDERR, "Seeder transaction failed in {SHORT_MODEL}: " . $e->getMessage() . "\n");
            }
        }
    }

    public function runPhase1(): void
    {
{PHASE1}
    }

    public function runPhase2(): void
    {
{PHASE2}
    }
}
PHP;

    $replacements = [
        '{USES_CODE}' => $usesCode,
        '{CLASS_NAME}' => $className,
        '{PHASE1}' => $phase1,
        '{PHASE2}' => $phase2,
        '{SHORT_MODEL}' => $shortModel,
    ];

    $code = str_replace(array_keys($replacements), array_values($replacements), $code);

    return $code;
    }

    protected function phpValueForColumn(string $col, array $meta): string
    {
        $type = $meta['type'] ?? 'string';
    $length = $meta['length'] ?? null;
    $precision = $meta['precision'] ?? null;
    $scale = $meta['scale'] ?? null;

        if (array_key_exists('default', $meta) && $meta['default'] !== null) {
            $def = $meta['default'];
            if (is_string($def)) {
                $def = trim($def);
                $def = trim($def, "'\" ");
            }

            $numericNames = ['quantity','qty','price','subtotal','total','tax','amount','usage_limit','value','shipping','paid_amount'];
            $lower = strtolower($col);
            if (in_array($type, ['integer','bigint','smallint'], true) || (in_array($lower, $numericNames, true) && is_numeric($def))) {
                return var_export((int)$def, true);
            }
            if (in_array($type, ['float','double','decimal'], true) || ((strpos($lower, 'price') !== false || strpos($lower, 'total') !== false || strpos($lower, 'tax') !== false || strpos($lower, 'amount') !== false) && is_numeric($def))) {
                return var_export((float)$def, true);
            }
            if ($type === 'boolean' || $lower === 'active' || $lower === 'enabled') {
                return ((bool)$def) ? 'true' : 'false';
            }
            if ($type === 'enum') {
                // Fall through to the switch statement for proper enum handling
            } else {
                return var_export($def, true);
            }
        }

    $nullable = $meta['nullable'] ?? false;
    $isUnique = $meta['unique'] ?? false;

        $lower = strtolower($col);
        $numericNames = ['quantity','qty','price','subtotal','total','tax','amount','usage_limit','value','shipping','paid_amount'];
        if (in_array($lower, $numericNames, true) && !in_array($type, ['json','text','enum'], true)) {
            if (strpos($lower, 'price') !== false || strpos($lower, 'total') !== false || strpos($lower, 'tax') !== false || strpos($lower, 'amount') !== false) {
                $expr = 'round(mt_rand(100,10000)/100, 2)';
            } else {
                $expr = 'rand(1, 10)';
            }
            if ($nullable) {
                return $expr;
            }
            return $expr;
        }

        $expr = null;
    switch ($type) {
            case 'integer':
            case 'bigint':
            case 'smallint':
                $min = isset($meta['unsigned']) && $meta['unsigned'] ? 1 : -1000;
                $max = 10000;
                $expr = "rand({$min}, {$max})";
                if (!empty($meta['foreign']) && is_array($meta['foreign'])) {
                    $ft = $meta['foreign']['table'];
                    $fc = $meta['foreign']['column'] ?? 'id';
                    $guessModel = null;
                    $pos = strrpos($ft, '\\');
                    if ($pos !== false) {
                        $ftShort = substr($ft, $pos + 1);
                    } else {
                        $ftShort = $ft;
                    }
                    $tryA = 'App\\Models\\' . ucfirst($ftShort);
                    if (class_exists($tryA)) { $guessModel = $tryA; }
                        if ($guessModel) {
                        $nullable = $meta['nullable'] ?? false;
                        if ($nullable) {
                            $expr = "({$guessModel}::count() > 0 ? {$guessModel}::inRandomOrder()->first()->{$fc} : null)";
                        } else {
                            $expr = "({$guessModel}::count() > 0 ? {$guessModel}::inRandomOrder()->first()->{$fc} : 1)";
                        }
                    } else {
                        $nullable = $meta['nullable'] ?? false;
                        if ($nullable) {
                            $expr = "(DB::table('{$ft}')->count() > 0 ? DB::table('{$ft}')->inRandomOrder()->first()->{$fc} : null)";
                        } else {
                            $expr = "(DB::table('{$ft}')->count() > 0 ? DB::table('{$ft}')->inRandomOrder()->first()->{$fc} : 1)";
                        }
                    }
                }
                break;
            case 'boolean':
                $expr = '(bool)rand(0,1)';
                break;
            case 'float':
            case 'double':
            case 'decimal':
                if ($scale !== null) {
                    $s = max(0, intval($scale));
                    $factor = pow(10, $s);
                    $expr = "round(mt_rand(100,10000)/100, {$s})";
                } else {
                    $expr = 'round(mt_rand(100,10000)/100, 2)';
                }
                break;
            case 'datetime':
            case 'datetimetz':
            case 'date':
                $expr = 'Carbon::now()->subDays(rand(0, 365))';
                break;
            case 'text':
                $max = $length ?: 200;
                $expr = "Str::limit(\$faker->paragraph, {$max})";
                break;
            case 'json':
                $expr = "json_encode([\$faker->word => \$faker->word])";
                break;
            case 'set':
                if (!empty($meta['set']) && is_array($meta['set'])) {
                    $choices = array_map(function ($v) { return "'" . addslashes($v) . "'"; }, $meta['set']);
                    $list = implode(', ', $choices);
                    $expr = "[{$list}][array_rand([{$list}])]";
                } else {
                    $expr = "FakerFactory::create()->word()";
                }
                break;
            case 'enum':
                if (!empty($meta['enum']) && is_array($meta['enum'])) {
                    $choices = array_map(function ($v) {
                        return var_export($v, true);
                    }, $meta['enum']);
                    $list = implode(', ', $choices);

                    $expr = "[{$list}][array_rand([{$list}])]";
                } else {
                    $expr = "\$faker->word()";
                }
                break;
            case 'binary':
            case 'varbinary':
            case 'blob':
                $maxLen = $length ?: 255;
                $expr = "bin2hex(random_bytes(min({$maxLen}, 32)))";
                break;
            case 'uuid':
                $expr = "Str::uuid()->toString()";
                break;
            case 'ipaddress':
            case 'inet':
                $expr = "\$faker->ipv4()";
                break;
            case 'macaddress':
                $expr = "\$faker->macAddress()";
                break;
            case 'geometry':
            case 'point':
            case 'linestring':
            case 'polygon':
                $expr = "json_encode(['type' => 'Point', 'coordinates' => [\$faker->longitude(), \$faker->latitude()]])";
                break;
            default:
                if (preg_match('/(_at$|_date$|_time$|_on$)/i', $col) || in_array($type, ['datetime','date'])) {
                    $expr = "Carbon::now()->subSeconds(rand(0, 86400 * 365) + \$i + (int)microtime(true) + random_int(0, 1000000))";
                } elseif (preg_match('/(^|_)email($|_)/i', $col) || $col === 'email') {
                    $expr = "\$faker->" . ($isUnique ? 'unique()->' : '') . "safeEmail()";
                } elseif (stripos($col, 'code') !== false) {
                    if ($isUnique) {
                        $expr = "strtoupper(\$faker->bothify('??-#####') . '-' . strtoupper(bin2hex(random_bytes(8))))";
                    } else {
                        $expr = "strtoupper(\$faker->bothify('??-#####'))";
                    }
                } elseif (stripos($col, 'sku') !== false) {
                    if ($isUnique) {
                        $expr = "strtoupper(\$faker->bothify('??-###') . '-' . strtoupper(bin2hex(random_bytes(6))))";
                    } else {
                        $expr = "strtoupper(\$faker->bothify('??-###'))";
                    }
                } elseif (stripos($col, 'name') !== false) {
                    $expr = "\$faker->name()";
                    if ($length) { $expr = "substr({$expr}, 0, {$length})"; }
                } elseif (stripos($col, 'slug') !== false) {
                    $expr = "Str::slug(\$faker->unique()->words(3, true))";
                    if ($length) { $expr = "substr({$expr}, 0, {$length})"; }
                } elseif (stripos($col, 'title') !== false) {
                    $expr = "\$faker->sentence()";
                } else {
                    $expr = "\$faker->word()";
                }
        }

        if ($nullable) {
            $exprWrapped = '(' . $expr . ')';
            $maybe = $exprWrapped;
            if ($isUnique) {
                $tbl = $this->currentTable ?: null;
                $tableRef = $tbl ? $tbl : ($this->currentModel ? (new \ReflectionClass($this->currentModel))->getShortName() : null);
                $tableForCheck = $this->currentTable ? $this->currentTable : null;
                $guard = "(function() use (\$faker, \$i) {\n                    \$v = {$maybe};\n                    \$tries = 0;\n                    while (\$tries < 20 && ";
                if ($tableForCheck) {
                    $guard .= "DB::table('" . addslashes($tableForCheck) . "')->where('" . addslashes($col) . "', \$v)->exists()";
                } else {
                    if ($this->currentModel) {
                        $m = '\\' . ltrim($this->currentModel, '\\');
                        $guard .= "{$m}::where('" . addslashes($col) . "', \$v)->exists()";
                    } else {
                        $guard .= "false";
                    }
                }
                $guard .= ") { \$v = {$maybe}; \$tries++; } return \$v; })()";
                return $guard;
            }
            return $maybe;
        }

        $looksUnique = $isUnique || preg_match('/(^|_)email($|_)/i', $col) || stripos($col, 'code') !== false || stripos($col, 'sku') !== false || stripos($col, 'slug') !== false;
        if ($looksUnique) {
            $tbl = $this->currentTable ?: null;
            $tableForCheck = $tbl;
            $valueExpr = $expr;
            $valueExprWrapped = '(' . $valueExpr . ')';
            $guard = "(function() use (\$faker, \$i) {\n                \$v = {$valueExprWrapped};\n                \$tries = 0;\n                while (\$tries < 50 && ";
            if ($tableForCheck) {
                $guard .= "DB::table('" . addslashes($tableForCheck) . "')->where('" . addslashes($col) . "', \$v)->exists()";
            } else {
                if ($this->currentModel) {
                    $m = '\\' . ltrim($this->currentModel, '\\');
                    $guard .= "{$m}::where('" . addslashes($col) . "', \$v)->exists()";
                } else {
                    $guard .= "false";
                }
            }
            $guard .= ") { \$v = {$valueExpr}; \$tries++; } return \$v; })()";
            return $guard;
        }

        return $expr;
    }
}
