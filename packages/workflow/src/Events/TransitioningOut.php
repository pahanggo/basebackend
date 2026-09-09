<?php

namespace Workflow\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Workflow\Models\WorkflowInstance;
use Workflow\Models\WorkflowInstanceToken;

/**
 * Dispatched before a token leaves its current node via an edge — the token
 * has not yet been consumed. A listener may cancel the whole transition by
 * calling $event->cancel(), since nothing has committed yet.
 */
class TransitioningOut
{
    use Dispatchable;

    protected bool $cancelled = false;

    public function __construct(
        public WorkflowInstance $instance,
        public WorkflowInstanceToken $token,
        public array $edge,
        public Model $workflowable,
        public ?Authenticatable $actor = null,
        public array $inputs = [],
    ) {
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
