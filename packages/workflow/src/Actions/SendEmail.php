<?php

namespace Workflow\Actions;

use Illuminate\Support\Facades\Mail;
use Workflow\Registries\WorkflowActionType;

/**
 * Sends a plain Mailable — for when a downstream dev wants full control over
 * the email (attachments, markdown views) rather than the Notification
 * system's mail channel (see SendNotification for that case).
 */
class SendEmail implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        $to = $this->resolveAddress($params, $context);

        if ($to) {
            Mail::to($to)->send(app($params['mailable']));
        }
    }

    public function preview(array $params, array $context): string
    {
        return sprintf('Would email %s using %s', $this->resolveAddress($params, $context) ?? 'unknown', $params['mailable'] ?? 'Mailable');
    }

    protected function resolveAddress(array $params, array $context): ?string
    {
        $to = $params['to'] ?? 'actor';

        if ($to === 'actor') {
            return $context['actor']?->email;
        }

        if ($to === 'workflowable') {
            return $context['instance']->workflowable()?->email;
        }

        return $to;
    }
}
