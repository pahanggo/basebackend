<?php

namespace WorkflowDemo\PurchaseRequest\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Workflow\Models\WorkflowDefinition;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

/**
 * Seeds everything the Purchase Request demo needs to be immediately
 * clickable: one demo user per role in the graph, the workflow definition
 * itself (published directly as graph JSON — a downstream developer could
 * equally build the exact same graph by hand in the visual designer; this
 * just skips that step for a one-command demo install), and a few sample
 * requests already sitting at different points in the flow.
 *
 * graph() is a snapshot of the definition's actual current
 * workflow_definition_version (taken from the live app, where every
 * designer-driven change described in this package's session history —
 * the "draft" node's field_policy/prefix, its row_actions.update override,
 * the definition-level operation_settings, the display_name, the
 * "submit"/row_actions actor_rule switched to the callbackFunctionIsRequester
 * model_callback — was actually made through the UI) rather than the
 * originally hand-written PHP array, so re-running this seeder reproduces
 * the definition exactly as it stands today instead of an older, drifted
 * version of it.
 *
 * The graph itself is still the plan's worked example end to end: a mix of
 * role-based and model_callback-based actor_rule, a human choosing between
 * two manual edges as the branching mechanism (skip feedback vs. request
 * it), a fork into three parallel department-feedback branches with a join
 * waiting on all three, required transition inputs mapped onto real columns
 * via store_as, requires_confirmation, a bulk_action-surfaced edge, a
 * timer-driven escalation ping, and a send_notification action on the
 * terminal edges.
 */
class PurchaseRequestDemoSeeder extends Seeder
{
    /** @var array<int, string> */
    public const ROLES = ['employee', 'hod', 'marketing', 'technical', 'operations', 'finance', 'ceo'];

    public function run(): void
    {
        $roleModel = config('backpack.permissionmanager.models.role');
        $users = [];

        foreach (self::ROLES as $roleName) {
            $roleModel::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $user = User::firstOrCreate(
                ['email' => "{$roleName}@purchase-request-demo.test"],
                ['name' => ucfirst($roleName).' (demo)', 'username' => "{$roleName}-demo", 'password' => bcrypt('password')]
            );
            $user->syncRoles([$roleName]);
            $users[$roleName] = $user;
        }

        $definition = WorkflowDefinition::firstOrCreate(
            ['slug' => 'purchase-request-demo'],
            ['name' => 'Purchase Request', 'description' => 'Demo workflow — see the architecture plan\'s worked example.', 'model' => PurchaseRequest::class]
        );

        $version = $definition->versions()->create(['version' => ($definition->versions()->max('version') ?? 0) + 1, 'graph' => $this->graph(), 'published_at' => now()]);
        $definition->update(['published_version_id' => $version->id]);

        $this->seedSampleRequests($users);
    }

    /**
     * Snapshot of workflow_definition_version #19 (the live definition's
     * current published version, as of when this seeder was last synced —
     * see this class's own docblock). Regenerate by copying
     * WorkflowDefinition::where('model', PurchaseRequest::class)->first()
     * ->latestVersion()->graph straight from the running app any time the
     * live definition is deliberately changed again and the seeder should
     * follow.
     *
     * @return array<string, mixed>
     */
    protected function graph(): array
    {
        $confirmReturnOrReject = true;

        // Identical on both 'draft' and 'pending_hod_review' in the live
        // definition — kept as one variable here for the same reason: a
        // change to one should almost certainly apply to the other too.
        $requestFieldPolicy = [
            ['field' => 'amount', 'visible' => true, 'label' => 'Amount', 'type' => 'money', 'readonly' => true, 'custom_field_definition' => "[\n\"prefix\" => \"$\",\n]"],
            ['field' => 'purpose', 'visible' => true, 'label' => 'Purpose', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'requester_id', 'visible' => false, 'label' => 'Requester Id', 'type' => 'number', 'readonly' => true],
            ['field' => 'hod_remarks', 'visible' => false, 'label' => 'Hod Remarks', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'marketing_feedback', 'visible' => false, 'label' => 'Marketing Feedback', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'technical_feedback', 'visible' => false, 'label' => 'Technical Feedback', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'operations_feedback', 'visible' => false, 'label' => 'Operations Feedback', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'finance_remarks', 'visible' => false, 'label' => 'Finance Remarks', 'type' => 'textarea', 'readonly' => true],
            ['field' => 'rejection_reason', 'visible' => false, 'label' => 'Rejection Reason', 'type' => 'textarea', 'readonly' => true],
        ];

        // Any employee could once submit for review; the live definition
        // has since tightened this to only the request's own requester, via
        // the model_callback escape hatch rather than a role — see
        // PurchaseRequest::callbackFunctionIsRequester().
        $isRequesterOnly = ['roles' => [], 'permissions' => [], 'users' => [], 'model_callback' => 'callbackFunctionIsRequester', 'match' => 'any'];

        return [
            'start' => 'draft',
            // Shown on the show-workflow page header instead of the raw
            // model class name — see WorkflowDefinition{,Version}::displayName().
            'display_name' => 'Purchase Request',
            // Gates the model's own Backpack show/update/delete operations
            // (independent of which node a record is on) — see
            // WorkflowOperation::applyWorkflowOperationAccess(). Update/delete
            // are disabled definition-wide here; the 'draft' node's own
            // row_actions override below re-enables Update just for the
            // request's own requester while it's still in draft.
            'operation_settings' => [
                'show' => ['enabled' => true],
                'update' => ['enabled' => false, 'actor_rule' => ['roles' => ['employee'], 'permissions' => [], 'users' => [], 'match' => 'any', 'model_callback' => '']],
                'delete' => ['enabled' => false, 'actor_rule' => ['roles' => ['employee'], 'permissions' => [], 'users' => [], 'model_callback' => '']],
            ],
            'nodes' => [
                [
                    'id' => 'draft', 'name' => 'Draft', 'type' => 'state',
                    'field_policy' => $requestFieldPolicy,
                    'row_actions' => [
                        'update' => ['enabled' => true, 'actor_rule' => ['roles' => [], 'permissions' => [], 'users' => [], 'model_callback' => 'callbackFunctionIsRequester']],
                    ],
                ],
                [
                    'id' => 'pending_hod_review', 'name' => 'Pending HOD review', 'type' => 'state',
                    'field_policy' => $requestFieldPolicy,
                    // Sample header_view/footer_view — proves out the node
                    // inspector's Form header/footer fields on the
                    // "show-workflow" page (see WorkflowShowController).
                    'header_view' => 'purchase-request-demo::partials.hod-review-header',
                    'footer_view' => 'purchase-request-demo::partials.hod-review-footer',
                ],
                ['id' => 'department_feedback_fork', 'name' => 'Department feedback (fork)', 'type' => 'fork'],
                ['id' => 'marketing_feedback', 'name' => 'Marketing feedback', 'type' => 'state'],
                ['id' => 'technical_feedback', 'name' => 'Technical feedback', 'type' => 'state'],
                ['id' => 'operations_feedback', 'name' => 'Operations feedback', 'type' => 'state'],
                ['id' => 'department_feedback_join', 'name' => 'Department feedback (join)', 'type' => 'join'],
                ['id' => 'pending_finance_review', 'name' => 'Pending finance review', 'type' => 'state'],
                ['id' => 'pending_ceo_review', 'name' => 'Pending CEO review', 'type' => 'state'],
                ['id' => 'approved', 'name' => 'Approved', 'type' => 'state'],
                ['id' => 'rejected', 'name' => 'Rejected', 'type' => 'state'],
            ],
            'edges' => [
                [
                    'id' => 'submit', 'name' => 'Submit for HOD review', 'from' => 'draft', 'to' => 'pending_hod_review',
                    'trigger' => 'manual', 'actor_rule' => $isRequesterOnly, 'surfaces' => ['record_button'],
                    // The escalation showcase: if the HOD hasn't acted within 3 days,
                    // ping them via the timer-triggered edge below.
                    'actions' => [['type' => 'start_timer', 'after' => '3d', 'edge' => 'hod_review_escalate']],
                ],
                [
                    // No longer a self-loop in the live definition — an
                    // overdue HOD review now bounces the request back to
                    // 'draft' (alongside pinging the HOD's manager) rather
                    // than leaving it sitting in the same reviewing state.
                    'id' => 'hod_review_escalate', 'name' => 'HOD review overdue', 'from' => 'pending_hod_review', 'to' => 'draft',
                    'trigger' => 'timer',
                    'actions' => [['type' => 'send_notification', 'to' => 'hod-manager@purchase-request-demo.test', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'hod_skip_feedback', 'name' => 'Approve (skip feedback)', 'from' => 'pending_hod_review', 'to' => 'pending_finance_review',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                ],
                [
                    'id' => 'hod_request_feedback', 'name' => 'Request department feedback', 'from' => 'pending_hod_review', 'to' => 'department_feedback_fork',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                ],
                [
                    'id' => 'hod_return', 'name' => 'Return for revision', 'from' => 'pending_hod_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'hod_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'hod_remarks']],
                ],
                [
                    'id' => 'hod_reject', 'name' => 'Reject', 'from' => 'pending_hod_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'hod_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'hod_remarks']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                ['id' => 'fork_marketing', 'name' => 'fork_marketing', 'from' => 'department_feedback_fork', 'to' => 'marketing_feedback', 'trigger' => 'automatic'],
                ['id' => 'fork_technical', 'name' => 'fork_technical', 'from' => 'department_feedback_fork', 'to' => 'technical_feedback', 'trigger' => 'automatic'],
                ['id' => 'fork_operations', 'name' => 'fork_operations', 'from' => 'department_feedback_fork', 'to' => 'operations_feedback', 'trigger' => 'automatic'],
                [
                    'id' => 'marketing_done', 'name' => 'Submit marketing feedback', 'from' => 'marketing_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['marketing'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'marketing_feedback']],
                ],
                [
                    'id' => 'technical_done', 'name' => 'Submit technical feedback', 'from' => 'technical_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['technical'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'technical_feedback']],
                ],
                [
                    'id' => 'operations_done', 'name' => 'Submit operations feedback', 'from' => 'operations_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['operations'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'operations_feedback']],
                ],
                ['id' => 'feedback_joined', 'name' => 'feedback_joined', 'from' => 'department_feedback_join', 'to' => 'pending_finance_review', 'trigger' => 'automatic'],
                [
                    'id' => 'finance_approve', 'name' => 'Approve', 'from' => 'pending_finance_review', 'to' => 'pending_ceo_review',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    // The bulk-action showcase: finance can approve several small requests at once.
                    'surfaces' => ['record_button', 'bulk_action'],
                ],
                [
                    'id' => 'finance_reject', 'name' => 'Reject', 'from' => 'pending_finance_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'finance_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'finance_remarks']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'finance_return', 'name' => 'Return for revision', 'from' => 'pending_finance_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'finance_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'finance_remarks']],
                ],
                [
                    'id' => 'ceo_approve', 'name' => 'Approve', 'from' => 'pending_ceo_review', 'to' => 'approved',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'], 'surfaces' => ['record_button'],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'ceo_reject', 'name' => 'Reject', 'from' => 'pending_ceo_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'rejection_reason', 'type' => 'textarea', 'required' => true, 'store_as' => 'rejection_reason']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'ceo_return', 'name' => 'Return for revision', 'from' => 'pending_ceo_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'permissions' => [], 'users' => [], 'model_callback' => '', 'match' => 'any'],
                    'surfaces' => ['record_button'], 'requires_confirmation' => $confirmReturnOrReject,
                ],
            ],
        ];
    }

    /** @param array<string, User> $users */
    protected function seedSampleRequests(array $users): void
    {
        $samples = [
            ['requester' => 'employee', 'amount' => 450.00, 'purpose' => 'Replacement laptop charger'],
            ['requester' => 'employee', 'amount' => 12500.00, 'purpose' => 'Annual marketing conference sponsorship'],
        ];

        foreach ($samples as $sample) {
            $exists = PurchaseRequest::where('purpose', $sample['purpose'])->exists();

            if ($exists) {
                continue;
            }

            // Starts its own workflow instance on creation — see
            // HasWorkflow::bootHasWorkflow().
            PurchaseRequest::create([
                'requester_id' => $users[$sample['requester']]->id,
                'amount' => $sample['amount'],
                'purpose' => $sample['purpose'],
            ]);
        }
    }
}
