<?php

namespace Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Workflow\Models\WorkflowInstance;

/**
 * Dispatched just before a new token is created at a destination node
 * (normal transitions, each fork branch, and a completed join). NOT
 * cancellable — by this point the originating edge has already committed
 * (its token was consumed), so refusing arrival would strand the instance
 * with no active token. Use TransitioningOut to veto a transition instead.
 */
class TransitioningIn
{
    use Dispatchable;

    public function __construct(
        public WorkflowInstance $instance,
        public string $nodeId,
        public ?array $edge,
        public Model $workflowable,
        public ?Authenticatable $actor = null,
        public array $inputs = [],
    ) {
    }
}
