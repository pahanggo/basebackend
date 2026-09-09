<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default field policy
    |--------------------------------------------------------------------------
    |
    | A node's field policy only needs to list the fields it wants to
    | override; anything not listed falls back to this mode.
    |
    */
    'default_field_mode' => 'readonly',

    /*
    |--------------------------------------------------------------------------
    | Registered action & precondition types
    |--------------------------------------------------------------------------
    |
    | Keyed by the string used in graph JSON. Downstream apps add their own
    | entries here (or call WorkflowActionRegistry::register()/
    | WorkflowPreconditionRegistry::register() from their own provider)
    | without touching this file.
    |
    */
    'actions' => [
        'send_email' => \Workflow\Actions\SendEmail::class,
        'send_notification' => \Workflow\Actions\SendNotification::class,
        'start_timer' => \Workflow\Actions\StartTimer::class,
        'call_webhook' => \Workflow\Actions\CallWebhook::class,
        'custom_callback' => \Workflow\Actions\CustomCallback::class,
    ],

    'preconditions' => [
        'field_equals' => \Workflow\Preconditions\FieldEquals::class,
        'field_in' => \Workflow\Preconditions\FieldIn::class,
        'field_compare' => \Workflow\Preconditions\FieldCompare::class,
        'custom_callback' => \Workflow\Preconditions\CustomCallback::class,
    ],
];
