<?php

namespace Workflow\Registries;

interface WorkflowActionType
{
    /**
     * Run the action for real (send the email, call the webhook, etc).
     *
     * @param  array<string, mixed>  $params  The params declared on the edge's `actions` entry.
     * @param  array<string, mixed>  $context  Keys: instance, token, edge, actor, inputs.
     */
    public function execute(array $params, array $context): void;

    /**
     * Dry-run mode: describe what execute() would have done, without doing it.
     * Return a short human-readable string (e.g. "Would email jane@x.com: ...").
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    public function preview(array $params, array $context): string;
}
