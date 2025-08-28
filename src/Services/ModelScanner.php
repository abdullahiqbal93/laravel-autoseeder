<?php

namespace Dedsec\LaravelAutoSeeder\Services;

class ModelScanner
{
    protected $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function getModels(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path));
        $models = [];

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $namespace = $this->getNamespace($contents);
            $class = $this->getClassName($contents);

            if (!$class) {
                continue;
            }

            $fqcn = $namespace ? $namespace . '\\' . $class : $class;

            if (!class_exists($fqcn)) {
                try {
                    require_once $file->getPathname();
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (!class_exists($fqcn)) {
                continue;
            }

            $ref = new \ReflectionClass($fqcn);
            if ($ref->isAbstract()) {
                continue;
            }

            if ($ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                $models[] = $fqcn;
            }
        }

        return array_values(array_unique($models));
    }

    protected function getNamespace(string $src): ?string
    {
        if (preg_match('#^\s*namespace\s+([^;]+);#m', $src, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function getClassName(string $src): ?string
    {
        if (preg_match('#class\s+([A-Za-z0-9_]+)#m', $src, $m)) {
            return $m[1];
        }
        return null;
    }
}
