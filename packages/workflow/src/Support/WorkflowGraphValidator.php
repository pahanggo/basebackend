<?php

namespace Workflow\Support;

/**
 * Structural sanity-checks for a hand-written (or agent-written) graph JSON
 * — dangling edge references, duplicate ids, an unreachable join, a missing
 * start node — the same class of mistake the visual designer's own UI
 * simply can't produce (it only ever lets you pick an existing node from a
 * dropdown), but a raw JSON file has no such guardrail. Used by both
 * `workflow:validate` (standalone) and `workflow:import` (before writing
 * anything, unless --force). Deliberately NOT a full semantic check —
 * it doesn't know whether an actor_rule's role actually exists, or whether
 * a field_policy's field is real; those need a database and a real
 * WorkflowSimulator run (see docs/authoring-as-code.md) to catch.
 */
class WorkflowGraphValidator
{
    /**
     * @return array<int, string> Human-readable problems, in no particular
     *                             order. Empty means the graph is
     *                             structurally sound.
     */
    public function validate(array $graph): array
    {
        $errors = [];

        $nodes = $graph['nodes'] ?? null;
        $edges = $graph['edges'] ?? null;

        if (! is_array($nodes)) {
            return ["'nodes' must be an array."];
        }

        if (! is_array($edges)) {
            return ["'edges' must be an array."];
        }

        $nodeIds = [];

        foreach ($nodes as $i => $node) {
            if (empty($node['id'])) {
                $errors[] = "Node at index {$i} is missing an 'id'.";

                continue;
            }

            if (isset($nodeIds[$node['id']])) {
                $errors[] = "Duplicate node id '{$node['id']}'.";
            }

            $nodeIds[$node['id']] = true;

            if (! in_array($node['type'] ?? null, ['state', 'fork', 'join'], true)) {
                $errors[] = "Node '{$node['id']}' has an invalid or missing 'type' (must be state, fork, or join).";
            }
        }

        $edgeIds = [];

        foreach ($edges as $i => $edge) {
            if (empty($edge['id'])) {
                $errors[] = "Edge at index {$i} is missing an 'id'.";

                continue;
            }

            if (isset($edgeIds[$edge['id']])) {
                $errors[] = "Duplicate edge id '{$edge['id']}'.";
            }

            $edgeIds[$edge['id']] = true;

            foreach (['from', 'to'] as $end) {
                if (empty($edge[$end])) {
                    $errors[] = "Edge '{$edge['id']}' is missing '{$end}'.";
                } elseif (! isset($nodeIds[$edge[$end]])) {
                    $errors[] = "Edge '{$edge['id']}' references unknown node '{$edge[$end]}' via '{$end}'.";
                }
            }

            if (! in_array($edge['trigger'] ?? null, ['manual', 'automatic', 'webhook', 'timer'], true)) {
                $errors[] = "Edge '{$edge['id']}' has an invalid or missing 'trigger' (must be manual, automatic, webhook, or timer).";
            }
        }

        if (empty($graph['start'])) {
            $errors[] = "'start' is required.";
        } elseif (! isset($nodeIds[$graph['start']])) {
            $errors[] = "'start' references unknown node '{$graph['start']}'.";
        }

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'join' && ! empty($node['id'])) {
                $incoming = collect($edges)->where('to', $node['id'])->count();

                if ($incoming < 1) {
                    $errors[] = "Join node '{$node['id']}' has no incoming edges — it can never fire.";
                }
            }
        }

        return $errors;
    }
}
