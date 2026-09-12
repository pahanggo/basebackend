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

    public static function versioningEnabled(): bool
    {
        return false;
    }

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

    /**
     * A model_callback actor_rule check — Workflow\Support\ActorRuleResolver
     * calls this with the actor actually being checked (the user attempting
     * the transition, or the row-action request), not the currently
     * web-authenticated session, so this must use the passed-in `$user`
     * rather than the `user()`/backpack_user() helper — that helper reflects
     * whoever is logged into the browser right now, which is only ever
     * correct by coincidence (e.g. it silently broke a programmatic
     * transitionTo() call made with a different/no web session at all).
     */
    public function callbackFunctionIsRequester(?User $user = null): bool
    {
        return $user !== null && $user->id == $this->requester_id;
    }

    /**
     * A `visibility_rules` model_callback scope — see
     * Workflow\Support\WorkflowVisibilityScope and
     * PurchaseRequestDemoSeeder::DEPARTMENTS. "Department" is deliberately
     * not a column on this model (or on the app's own shared `users`
     * table): it's derived purely from a `department_{name}` role on the
     * requester, the same schema-free, role-driven approach the rest of
     * this demo already uses for everything else. Sees nothing at all if
     * the actor isn't tagged with a department role themselves — an HOD
     * with no department can't fall back to seeing everyone's.
     */
    public function visibleToDepartment($query, ?User $actor): void
    {
        $departmentRoles = collect(\WorkflowDemo\PurchaseRequest\Database\Seeders\PurchaseRequestDemoSeeder::DEPARTMENTS)
            ->map(fn (string $department) => "department_{$department}");

        $actorDepartments = $actor ? collect($actor->getRoleNames())->intersect($departmentRoles) : collect();

        if ($actorDepartments->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $requesterIds = User::role($actorDepartments->values()->all())->pluck('id');

        $query->whereIn('requester_id', $requesterIds);
    }
}
