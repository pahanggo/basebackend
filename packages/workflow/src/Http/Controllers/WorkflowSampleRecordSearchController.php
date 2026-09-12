<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Workflow\Models\WorkflowDefinition;

/**
 * Ajax search backing the designer's "Test with a sample record" picker —
 * searches real records of the definition's own target model (not a class
 * name, unlike WorkflowModelSearchController), so a dry run can be run
 * against actual data.
 */
class WorkflowSampleRecordSearchController
{
    public function __invoke(Request $request, WorkflowDefinition $workflowDefinition): JsonResponse
    {
        $modelClass = $workflowDefinition->model;

        if (! class_exists($modelClass)) {
            return response()->json(['results' => []]);
        }

        $term = trim((string) $request->query('q', ''));
        $instance = new $modelClass;
        $table = $instance->getTable();

        $searchableColumn = collect(['name', 'title', 'label', 'email'])
            ->first(fn ($column) => Schema::hasColumn($table, $column));

        $results = $modelClass::query()
            ->when($term !== '' && $searchableColumn, fn ($q) => $q->where($searchableColumn, 'like', "%{$term}%"))
            ->when($term !== '' && ! $searchableColumn, fn ($q) => $q->where($instance->getKeyName(), $term))
            ->limit(20)
            ->get()
            ->map(fn ($record) => [
                'id' => $record->getKey(),
                'text' => $searchableColumn ? "#{$record->getKey()} — {$record->{$searchableColumn}}" : "#{$record->getKey()}",
            ]);

        return response()->json(['results' => $results]);
    }
}
