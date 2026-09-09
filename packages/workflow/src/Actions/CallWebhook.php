<?php

namespace Workflow\Actions;

use Illuminate\Support\Facades\Http;
use Workflow\Registries\WorkflowActionType;

class CallWebhook implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        Http::post($params['url'], [
            'instance_id' => $context['instance']->id,
            'edge_id' => $context['edge']['id'],
            'inputs' => $context['inputs'],
        ]);
    }

    public function preview(array $params, array $context): string
    {
        return sprintf('Would POST to %s', $params['url'] ?? 'unknown URL');
    }
}
