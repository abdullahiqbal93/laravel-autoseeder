<?php

namespace Dedsec\LaravelAutoSeeder\Services;

use Illuminate\Support\Facades\DB;

class SchemaAnalyzer
{
    public function columnsForModel(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model {$modelClass} not found");
        }

        try {
            $model = new $modelClass;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Cannot instantiate model {$modelClass}: " . $e->getMessage());
        }

        if (!method_exists($model, 'getTable')) {
            throw new \InvalidArgumentException("Model {$modelClass} is not a valid Eloquent model");
        }

        $table = $model->getTable();

        $columns = [];

        try {
            if (method_exists(DB::getPdo(), 'getAttribute')) {
                $schemaManager = DB::getDoctrineSchemaManager();
                $cols = $schemaManager->listTableColumns($table);

                foreach ($cols as $col) {
                    $rawType = (string) $col->getType()->getName();
                    $norm = $this->normalizeColumnType($rawType);
                    $length = null; $precision = null; $scale = null;
                    try { $length = $col->getLength(); } catch (\Throwable $_) { $length = null; }
                    try { $precision = $col->getPrecision(); } catch (\Throwable $_) { $precision = null; }
                    try { $scale = $col->getScale(); } catch (\Throwable $_) { $scale = null; }
                    $columns[$col->getName()] = [
                        'type' => $norm['type'],
                        'enum' => $norm['enum'] ?? null,
                        'nullable' => !$col->getNotnull(),
                        'default' => $col->getDefault(),
                        'autoincrement' => $col->getAutoincrement(),
                        'unsigned' => $norm['unsigned'] ?? false,
                        'length' => $length ?? ($norm['length'] ?? null),
                        'precision' => $precision ?? ($norm['precision'] ?? null),
                        'scale' => $scale ?? ($norm['scale'] ?? null),
                    ];
                }
                return $this->augmentWithForeignAndUnique($table, $columns);
            }
        } catch (\Throwable $e) {
        }

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA table_info('" . $table . "')");
            foreach ($rows as $r) {
                $raw = $r->type;
                $norm = $this->normalizeColumnType($raw);
                $length = $norm['length'] ?? null;
                $precision = $norm['precision'] ?? null;
                $scale = $norm['scale'] ?? null;
                $columns[$r->name] = [
                    'type' => $norm['type'],
                    'enum' => $norm['enum'] ?? null,
                    'nullable' => ($r->notnull == 0),
                    'default' => $r->dflt_value,
                    'autoincrement' => stripos($r->pk ?? '', '1') !== false,
                    'unsigned' => $norm['unsigned'] ?? false,
                    'length' => $length,
                    'precision' => $precision,
                    'scale' => $scale,
                ];
            }
            return $this->augmentWithForeignAndUnique($table, $columns);
        }

        try {
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'maria') {
                $rows = DB::select('DESCRIBE ' . $table);
            } elseif ($driver === 'pgsql') {
                $rows = DB::select("SELECT column_name as Field, data_type as Type, is_nullable as Null, column_default as Default, '' as Extra FROM information_schema.columns WHERE table_name = ? AND table_schema = 'public'", [$table]);
            } elseif ($driver === 'sqlite') {
                $rows = DB::select("PRAGMA table_info('" . $table . "')");
            } else {
                throw new \RuntimeException('Unsupported database driver: ' . $driver);
            }
                foreach ($rows as $r) {
                $name = $r->Field ?? $r->field ?? null;
                if (!$name) continue;
                $raw = $r->Type ?? $r->type ?? 'string';
                $norm = $this->normalizeColumnType($raw);
                $columns[$name] = [
                    'type' => $norm['type'],
                    'enum' => $norm['enum'] ?? null,
                    'nullable' => stripos(($r->Null ?? ''), 'YES') !== false,
                    'default' => $r->Default ?? $r->default ?? null,
                    'autoincrement' => stripos(($r->Extra ?? ''), 'auto_increment') !== false,
                    'unsigned' => $norm['unsigned'] ?? false,
                    'length' => $norm['length'] ?? null,
                    'precision' => $norm['precision'] ?? null,
                    'scale' => $norm['scale'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to introspect table ' . $table . ': ' . $e->getMessage());
        }

    return $this->augmentWithForeignAndUnique($table, $columns);
    }

    protected function augmentWithForeignAndUnique(string $table, array $columns): array
    {
        try {
            $sm = null;
            if (method_exists(DB::getPdo(), 'getAttribute')) {
                $sm = DB::getDoctrineSchemaManager();
            }
        } catch (\Throwable $e) {
            $sm = null;
        }

        foreach ($columns as $cname => &$cmeta) {
            $cmeta['foreign'] = null;
            $cmeta['unique'] = $cmeta['autoincrement'] ?? false;
            $cmeta['unique_indexes'] = [];
        }
        $uniqueIndexes = [];

        if ($sm) {
            try {
                $fks = $sm->listTableForeignKeys($table);
                foreach ($fks as $fk) {
                    $local = $fk->getLocalColumns();
                    $foreignTable = $fk->getForeignTableName();
                    $foreignCols = $fk->getForeignColumns();
                    if (!empty($local)) {
                        $lname = $local[0];
                        if (isset($columns[$lname])) {
                            $columns[$lname]['foreign'] = ['table' => $foreignTable, 'column' => $foreignCols[0] ?? 'id'];
                        }
                    }
                }
                $idxs = $sm->listTableIndexes($table);
                foreach ($idxs as $idx) {
                    if ($idx->isUnique()) {
                        $colsInIdx = $idx->getColumns();
                        if (count($colsInIdx) > 1) { $uniqueIndexes[] = $colsInIdx; }
                        foreach ($colsInIdx as $col) {
                            if (isset($columns[$col])) { $columns[$col]['unique'] = true; }
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            foreach ($uniqueIndexes as $uix) {
                foreach ($uix as $col) {
                    if (isset($columns[$col])) {
                        $columns[$col]['unique_indexes'][] = $uix;
                    }
                }
            }
            return $columns;
        }

        try {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                $rows = DB::select("PRAGMA foreign_key_list('" . $table . "')");
                foreach ($rows as $r) {
                    $from = property_exists($r, 'from') ? $r->from : null;
                    $refTable = $r->table ?? null;
                    $to = $r->to ?? null;
                    if ($from && isset($columns[$from])) {
                        $columns[$from]['foreign'] = ['table' => $refTable, 'column' => $to ?: 'id'];
                    }
                }
                $idxs = DB::select("PRAGMA index_list('" . $table . "')");
                foreach ($idxs as $idx) {
                    if (!empty($idx->unique)) {
                        $iname = $idx->name ?? $idx->idxname ?? null;
                        if ($iname) {
                            $info = DB::select("PRAGMA index_info('" . $iname . "')");
                            $cols = [];
                            foreach ($info as $inf) {
                                $col = $inf->name ?? null;
                                if ($col) {
                                    $cols[] = $col;
                                    if (isset($columns[$col])) { $columns[$col]['unique'] = true; }
                                }
                            }
                            if (count($cols) > 1) { $uniqueIndexes[] = $cols; }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'maria') {
                $dbName = DB::getDatabaseName();
                $fkRows = DB::select("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$dbName, $table]);
                foreach ($fkRows as $r) {
                    $col = $r->COLUMN_NAME ?? $r->column_name ?? null;
                    if ($col && isset($columns[$col])) {
                        $columns[$col]['foreign'] = ['table' => $r->REFERENCED_TABLE_NAME, 'column' => $r->REFERENCED_COLUMN_NAME];
                    }
                }
                $idxRows = DB::select("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$dbName, $table]);
                $idxMap = [];
                foreach ($idxRows as $r) {
                    $col = $r->COLUMN_NAME ?? $r->column_name ?? null;
                    $nonUnique = $r->NON_UNIQUE ?? $r->non_unique ?? 1;
                    $keyName = $r->INDEX_NAME ?? $r->index_name ?? uniqid('idx_');
                    if ($col) {
                        if (!isset($idxMap[$keyName])) { $idxMap[$keyName] = ['non_unique' => intval($nonUnique), 'cols' => []]; }
                        $idxMap[$keyName]['cols'][] = $col;
                    }
                }
                foreach ($idxMap as $idata) {
                    if ($idata['non_unique'] === 0) {
                        if (count($idata['cols']) > 1) { $uniqueIndexes[] = $idata['cols']; }
                        foreach ($idata['cols'] as $col) { if (isset($columns[$col])) { $columns[$col]['unique'] = true; } }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        foreach ($uniqueIndexes as $uix) {
            foreach ($uix as $col) {
                if (isset($columns[$col])) { $columns[$col]['unique_indexes'][] = $uix; }
            }
        }

        return $columns;
    }

    protected function normalizeColumnType(string $raw): array
    {
        $raw = strtolower($raw);
    $out = ['type' => 'string', 'enum' => null, 'unsigned' => false, 'length' => null, 'precision' => null, 'scale' => null];

        if (strpos($raw, 'unsigned') !== false) {
            $out['unsigned'] = true;
            $raw = str_replace('unsigned', '', $raw);
        }

        if (strpos($raw, 'int') !== false) {
            $out['type'] = 'integer';
            if (preg_match('/\((\d+)\)/', $raw, $m)) { $out['length'] = intval($m[1]); }
            return $out;
        }
        if (strpos($raw, 'tinyint(1)') !== false || preg_match('/^tinyint\(1\)/', $raw)) {
            $out['type'] = 'boolean';
            return $out;
        }
        if (strpos($raw, 'tinyint') !== false || strpos($raw, 'smallint') !== false || strpos($raw, 'bigint') !== false) {
            $out['type'] = 'integer';
            return $out;
        }
        if (strpos($raw, 'float') !== false || strpos($raw, 'double') !== false || strpos($raw, 'decimal') !== false || strpos($raw, 'numeric') !== false) {
            $out['type'] = 'float';
            if (preg_match('/\((\d+),(\d+)\)/', $raw, $m)) { $out['precision'] = intval($m[1]); $out['scale'] = intval($m[2]); }
            elseif (preg_match('/\((\d+)\)/', $raw, $m)) { $out['precision'] = intval($m[1]); }
            return $out;
        }
        if (strpos($raw, 'datetime') !== false || strpos($raw, 'timestamp') !== false) {
            $out['type'] = 'datetime';
            return $out;
        }
        if (strpos($raw, 'date') !== false && strpos($raw, 'time') === false) {
            $out['type'] = 'date';
            return $out;
        }
        if (strpos($raw, 'time') !== false && strpos($raw, 'date') === false) {
            $out['type'] = 'time';
            return $out;
        }
        if (strpos($raw, 'json') !== false || strpos($raw, 'jsonb') !== false) {
            $out['type'] = 'json';
            return $out;
        }
        if (strpos($raw, 'text') !== false || strpos($raw, 'mediumtext') !== false || strpos($raw, 'longtext') !== false) {
            $out['type'] = 'text';
            if (preg_match('/\((\d+)\)/', $raw, $m)) { $out['length'] = intval($m[1]); }
            return $out;
        }
        if (strpos($raw, 'varchar') !== false || strpos($raw, 'char') !== false) {
            $out['type'] = 'string';
            if (preg_match('/\((\d+)\)/', $raw, $m)) { $out['length'] = intval($m[1]); }
            return $out;
        }
        if (strpos($raw, 'binary') !== false || strpos($raw, 'varbinary') !== false || strpos($raw, 'blob') !== false) {
            $out['type'] = 'binary';
            if (preg_match('/\((\d+)\)/', $raw, $m)) { $out['length'] = intval($m[1]); }
            return $out;
        }
        if (strpos($raw, 'enum') !== false) {
            $out['type'] = 'enum';
            if (preg_match('/enum\((.*)\)/', $raw, $m)) {
                $vals = array_map(function ($v) { return trim($v, "'\""); }, explode(',', $m[1]));
                $out['enum'] = $vals;
                $maxLen = 0; foreach ($vals as $v) { $maxLen = max($maxLen, strlen($v)); }
                $out['length'] = $maxLen ?: null;
            }
            return $out;
        }
        if (strpos($raw, 'set') !== false) {
            $out['type'] = 'set';
            if (preg_match('/set\((.*)\)/', $raw, $m)) {
                $vals = array_map(function ($v) { return trim($v, "'\""); }, explode(',', $m[1]));
                $out['set'] = $vals;
            }
            return $out;
        }
        if (strpos($raw, 'uuid') !== false) {
            $out['type'] = 'uuid';
            return $out;
        }
        if (strpos($raw, 'ipaddress') !== false || strpos($raw, 'inet') !== false) {
            $out['type'] = 'ipaddress';
            return $out;
        }
        if (strpos($raw, 'macaddr') !== false) {
            $out['type'] = 'macaddress';
            return $out;
        }
        if (strpos($raw, 'geometry') !== false || strpos($raw, 'point') !== false || strpos($raw, 'linestring') !== false || strpos($raw, 'polygon') !== false) {
            $out['type'] = 'geometry';
            return $out;
        }

        return $out;
    }
}
