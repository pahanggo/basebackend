<?php

namespace Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Workflow\Models\WorkflowInstance;
use Workflow\Models\WorkflowInstanceToken;

/**
 * Dispatched right after a new token is created and active at a destination
 * node. This is usually the most useful hook for a downstream dev — "notify
 * the reviewer when a request lands in pending_review" — since it fires once
 * per node arrival, including each fork branch and a completed join.
 */
class TransitionedIn
{
    use Dispatchable;

    public function __construct(
        public WorkflowInstance $instance,
        public WorkflowInstanceToken $token,
        public ?array $edge,
        public Model $workflowable,
        public ?Authenticatable $actor = null,
        public array $inputs = [],
    ) {
    }
}
