<?php

namespace Workflow\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Workflow\Support\EloquentModelFinder;

/**
 * Feeds the node inspector's field-policy editor: every column on the
 * workflow's target model, plus every column on models reachable through
 * one of its own relation methods (so a policy row can target e.g.
 * "requester.department"). Only ever reflects a class the app's own
 * EloquentModelFinder already recognizes as a concrete model under
 * app/Models — never an arbitrary attacker-supplied class name.
 */
class WorkflowModelFieldsController
{
    /**
     * @var array<string, string>
     */
    protected const TYPE_MAP = [
        'string' => 'text',
        'text' => 'textarea',
        'mediumtext' => 'textarea',
        'longtext' => 'textarea',
        'integer' => 'number',
        'bigint' => 'number',
        'smallint' => 'number',
        'tinyint' => 'number',
        'decimal' => 'number',
        'float' => 'number',
        'double' => 'number',
        'boolean' => 'switch',
        'date' => 'date',
        'datetime' => 'datetime',
        'datetimetz' => 'datetime',
        'time' => 'time',
        'json' => 'textarea',
    ];

    protected const SKIP_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public function __invoke(Request $request, EloquentModelFinder $finder): JsonResponse
    {
        $model = (string) $request->query('model');

        if ($model === '' || ! in_array($model, $finder->all(), true)) {
            return response()->json(['fields' => []]);
        }

        $instance = new $model;
        $fields = $this->columnsFor($instance, '');

        foreach ($this->relatedModels($instance) as $relationName => $relatedModel) {
            $fields = array_merge($fields, $this->columnsFor($relatedModel, $relationName.'.'));
        }

        return response()->json([
            'model_table' => $instance->getTable(),
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<int, array{field: string, label: string, type: string, table: string}>
     */
    protected function columnsFor(Model $instance, string $prefix): array
    {
        try {
            $columns = Schema::connection($instance->getConnectionName())->getColumnListing($instance->getTable());
        } catch (Throwable $e) {
            return [];
        }

        return collect($columns)
            ->reject(fn (string $column) => in_array($column, self::SKIP_COLUMNS, true))
            ->map(function (string $column) use ($instance, $prefix) {
                try {
                    $type = Schema::connection($instance->getConnectionName())->getColumnType($instance->getTable(), $column);
                } catch (Throwable $e) {
                    $type = null;
                }

                return [
                    'field' => $prefix.$column,
                    'label' => ucwords(str_replace('_', ' ', $column)),
                    'type' => self::TYPE_MAP[$type] ?? 'text',
                    'table' => $instance->getTable(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Public, zero-argument methods declared directly on the model (not
     * inherited from Model itself) whose return type is a Relation — the
     * same "only reflect what the model actually declares" convention used
     * by WorkflowModelCallbackSearchController.
     *
     * @return array<string, Model>
     */
    protected function relatedModels(Model $instance): array
    {
        $related = [];

        foreach ((new ReflectionClass($instance))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() > 0 || $method->class !== get_class($instance)) {
                continue;
            }

            $returnType = $method->getReturnType();
            if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
                continue;
            }
            if (! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }

            try {
                $related[$method->getName()] = $instance->{$method->getName()}()->getRelated();
            } catch (Throwable $e) {
                continue;
            }
        }

        return $related;
    }
}
