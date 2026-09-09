<?php

namespace Workflow\Actions;

use Illuminate\Notifications\AnonymousNotifiable;
use Workflow\Registries\WorkflowActionType;

/**
 * Fires an ordinary Laravel notification (mail/database/broadcast channels —
 * whatever the target Notification class declares) at a resolved recipient.
 * `params['to']` may be 'actor', 'workflowable' (if it exposes a notifiable
 * relation via a `notifiable()` accessor), or an explicit email string.
 */
class SendNotification implements WorkflowActionType
{
    public function execute(array $params, array $context): void
    {
        $this->recipient($params, $context)?->notify(app($params['notification']));
    }

    public function preview(array $params, array $context): string
    {
        $recipient = $this->recipient($params, $context);

        return sprintf(
            'Would send %s to %s',
            $params['notification'] ?? 'notification',
            $recipient instanceof AnonymousNotifiable ? ($params['to'] ?? 'unknown') : ($recipient?->email ?? 'unknown recipient')
        );
    }

    protected function recipient(array $params, array $context)
    {
        $to = $params['to'] ?? 'actor';

        if ($to === 'actor') {
            return $context['actor'];
        }

        if ($to === 'workflowable') {
            return $context['instance']->workflowable();
        }

        return (new AnonymousNotifiable)->route('mail', $to);
    }
}
