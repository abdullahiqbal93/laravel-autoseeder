<?php

namespace Dedsec\LaravelAutoSeeder\Services;

use ReflectionMethod;

class RelationshipDetector
{
    public function relationsForModel(string $modelClass): array
    {
        $ret = [];
        if (!class_exists($modelClass)) {
            return $ret;
        }

        $ref = new \ReflectionClass($modelClass);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $modelClass) {
                continue;
            }

            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            try {
                $instance = $ref->newInstanceWithoutConstructor();
                $res = $method->invoke($instance);
                if (!is_object($res)) {
                    continue;
                }

                $class = get_class($res);
                $type = null;
                if (is_subclass_of($class, '\\Illuminate\\Database\\Eloquent\\Relations\\Relation')) {
                    $type = (new \ReflectionClass($class))->getShortName();
                }

                if ($type) {
                    $related = null;
                    if (method_exists($res, 'getRelated')) {
                        $related = get_class($res->getRelated());
                    }

                    $ret[] = [
                        'name' => $method->getName(),
                        'type' => $type,
                        'related' => $related,
                    ];
                }
            } catch (\Throwable $e) {
                if (function_exists('error_log')) {
                    error_log("Relationship detection failed for {$modelClass}::{$method->getName()}: " . $e->getMessage());
                }
            }
        }

        return $ret;
    }
}
