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
    | Versioning
    |--------------------------------------------------------------------------
    |
    | When true (the default), publishing a new graph version never affects
    | an in-flight instance — it stays pinned to whichever version it started
    | on (see WorkflowDefinitionVersion, WorkflowInstance::effectiveVersion()).
    |
    | When false, EVERY instance — in-flight ones included, with no data
    | migration needed — always runs against its definition's current
    | published version instead of the one it started on. A specific model
    | can override this default via HasWorkflow::setEnableVersioning() on
    | its own class.
    |
    */
    'enable_versioning' => true,

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
    ],

    'preconditions' => [
        'field_equals' => \Workflow\Preconditions\FieldEquals::class,
        'field_in' => \Workflow\Preconditions\FieldIn::class,
        'field_compare' => \Workflow\Preconditions\FieldCompare::class,
        'model_callback' => \Workflow\Preconditions\ModelCallback::class,
    ],
];
