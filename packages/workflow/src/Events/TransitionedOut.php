<?php

namespace Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Workflow\Models\WorkflowInstance;
use Workflow\Models\WorkflowInstanceHistory;
use Workflow\Models\WorkflowInstanceToken;

/**
 * Dispatched right after a token is consumed leaving its node via an edge
 * (before the destination side has necessarily landed anywhere, e.g. a join
 * still waiting on siblings). Pair with TransitionedIn to know when the
 * instance actually arrives somewhere.
 */
class TransitionedOut
{
    use Dispatchable;

    public function __construct(
        public WorkflowInstance $instance,
        public WorkflowInstanceToken $token,
        public array $edge,
        public Model $workflowable,
        public WorkflowInstanceHistory $history,
        public ?Authenticatable $actor = null,
        public array $inputs = [],
    ) {
    }
}
