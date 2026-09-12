<?php

namespace WorkflowDemo\PurchaseRequest\Models;

use App\Models\User;
use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Workflow\HasWorkflow;

/**
 * The worked example proving the workflow engine end-to-end against a real
 * downstream model — see the architecture plan's "Demo workflow: Purchase
 * Request" section. Ships in its own package/database, same as the engine
 * itself, so this demo can be installed (or skipped) independently of any
 * downstream project's own models.
 */
class PurchaseRequest extends Model
{
    use CrudTrait;
    use HasWorkflow;
    use Notifiable;

    protected $connection = 'purchase_request_demo';

    protected $fillable = [
        'requester_id',
        'amount',
        'purpose',
        'hod_remarks',
        'marketing_feedback',
        'technical_feedback',
        'operations_feedback',
        'finance_remarks',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function workflowDefinitionSlug(): string
    {
        return 'purchase-request-demo';
    }

    /**
     * Deliberately a plain lookup, not a real Eloquent relation: a belongsTo()
     * here would have Eloquent construct the related User query by inheriting
     * *this* model's connection (`newRelatedInstance()`'s documented behavior
     * for a related model with no connection of its own explicitly set) —
     * silently querying "users" on the `purchase_request_demo` SQLite
     * connection instead of the main app's, and failing with "no such
     * table". Same cross-connection approach WorkflowInstance::workflowable()
     * already uses for the same reason.
     */
    public function requester(): ?User
    {
        return User::find($this->requester_id);
    }

    /**
     * Lets `send_notification` actions target `'to' => 'workflowable'` (the
     * showcase on the approve/reject/return edges) even though this record
     * itself has no email column — routes to the requester's own address.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->requester()?->email;
    }
}
