<?php

namespace WorkflowDemo\PurchaseRequest\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Workflow\Models\WorkflowDefinition;
use WorkflowDemo\PurchaseRequest\Models\PurchaseRequest;

/**
 * Seeds everything the Purchase Request demo needs to be immediately
 * clickable: one demo user per role in the graph, the workflow definition
 * itself (built and published directly as graph JSON — a downstream
 * developer could equally build the exact same graph by hand in the visual
 * designer; this just skips that step for a one-command demo install), and
 * a few sample requests already sitting at different points in the flow.
 *
 * The graph itself is the plan's worked example end to end: role-based
 * actor_rule, a human choosing between two manual edges as the branching
 * mechanism (skip feedback vs. request it), a fork into three parallel
 * department-feedback branches with a join waiting on all three, required
 * transition inputs mapped onto real columns via store_as, requires_confirmation,
 * a bulk_action-surfaced edge, a timer-driven escalation ping, and a
 * send_notification action on the terminal edges.
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

        $this->seedSampleRequests($definition, $users);
    }

    /** @return array<string, mixed> */
    protected function graph(): array
    {
        $confirmReturnOrReject = true;

        return [
            'start' => 'draft',
            'nodes' => [
                ['id' => 'draft', 'name' => 'Draft', 'type' => 'state'],
                ['id' => 'pending_hod_review', 'name' => 'Pending HOD review', 'type' => 'state'],
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
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['employee'], 'match' => 'any'],
                    // The escalation showcase: if the HOD hasn't acted within 3 days,
                    // ping them via the timer-triggered self-loop edge below.
                    'actions' => [['type' => 'start_timer', 'after' => '3d', 'edge' => 'hod_review_escalate']],
                ],
                [
                    'id' => 'hod_review_escalate', 'name' => 'HOD review overdue', 'from' => 'pending_hod_review', 'to' => 'pending_hod_review',
                    'trigger' => 'timer',
                    'actions' => [['type' => 'send_notification', 'to' => 'hod-manager@purchase-request-demo.test', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'hod_skip_feedback', 'name' => 'Approve (skip feedback)', 'from' => 'pending_hod_review', 'to' => 'pending_finance_review',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'match' => 'any'],
                ],
                [
                    'id' => 'hod_request_feedback', 'name' => 'Request department feedback', 'from' => 'pending_hod_review', 'to' => 'department_feedback_fork',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'match' => 'any'],
                ],
                [
                    'id' => 'hod_return', 'name' => 'Return for revision', 'from' => 'pending_hod_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'hod_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'hod_remarks']],
                ],
                [
                    'id' => 'hod_reject', 'name' => 'Reject', 'from' => 'pending_hod_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['hod'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'hod_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'hod_remarks']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                ['id' => 'fork_marketing', 'from' => 'department_feedback_fork', 'to' => 'marketing_feedback', 'trigger' => 'automatic'],
                ['id' => 'fork_technical', 'from' => 'department_feedback_fork', 'to' => 'technical_feedback', 'trigger' => 'automatic'],
                ['id' => 'fork_operations', 'from' => 'department_feedback_fork', 'to' => 'operations_feedback', 'trigger' => 'automatic'],
                [
                    'id' => 'marketing_done', 'name' => 'Submit marketing feedback', 'from' => 'marketing_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['marketing'], 'match' => 'any'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'marketing_feedback']],
                ],
                [
                    'id' => 'technical_done', 'name' => 'Submit technical feedback', 'from' => 'technical_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['technical'], 'match' => 'any'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'technical_feedback']],
                ],
                [
                    'id' => 'operations_done', 'name' => 'Submit operations feedback', 'from' => 'operations_feedback', 'to' => 'department_feedback_join',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['operations'], 'match' => 'any'],
                    'inputs' => [['name' => 'feedback', 'type' => 'textarea', 'required' => true, 'store_as' => 'operations_feedback']],
                ],
                ['id' => 'feedback_joined', 'from' => 'department_feedback_join', 'to' => 'pending_finance_review', 'trigger' => 'automatic'],
                [
                    'id' => 'finance_approve', 'name' => 'Approve', 'from' => 'pending_finance_review', 'to' => 'pending_ceo_review',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'match' => 'any'],
                    // The bulk-action showcase: finance can approve several small requests at once.
                    'surfaces' => ['record_button', 'bulk_action'],
                ],
                [
                    'id' => 'finance_reject', 'name' => 'Reject', 'from' => 'pending_finance_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'finance_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'finance_remarks']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'finance_return', 'name' => 'Return for revision', 'from' => 'pending_finance_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['finance'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'finance_remarks', 'type' => 'textarea', 'required' => true, 'store_as' => 'finance_remarks']],
                ],
                [
                    'id' => 'ceo_approve', 'name' => 'Approve', 'from' => 'pending_ceo_review', 'to' => 'approved',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'match' => 'any'],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'ceo_reject', 'name' => 'Reject', 'from' => 'pending_ceo_review', 'to' => 'rejected',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                    'inputs' => [['name' => 'rejection_reason', 'type' => 'textarea', 'required' => true, 'store_as' => 'rejection_reason']],
                    'actions' => [['type' => 'send_notification', 'to' => 'workflowable', 'notification' => 'App\\Notifications\\PasswordChangedNotification']],
                ],
                [
                    'id' => 'ceo_return', 'name' => 'Return for revision', 'from' => 'pending_ceo_review', 'to' => 'draft',
                    'trigger' => 'manual', 'actor_rule' => ['roles' => ['ceo'], 'match' => 'any'], 'requires_confirmation' => $confirmReturnOrReject,
                ],
            ],
        ];
    }

    /** @param array<string, User> $users */
    protected function seedSampleRequests(WorkflowDefinition $definition, array $users): void
    {
        $engine = app(\Workflow\Support\TransitionEngine::class);

        $samples = [
            ['requester' => 'employee', 'amount' => 450.00, 'purpose' => 'Replacement laptop charger'],
            ['requester' => 'employee', 'amount' => 12500.00, 'purpose' => 'Annual marketing conference sponsorship'],
        ];

        foreach ($samples as $sample) {
            $exists = PurchaseRequest::where('purpose', $sample['purpose'])->exists();

            if ($exists) {
                continue;
            }

            $request = PurchaseRequest::create([
                'requester_id' => $users[$sample['requester']]->id,
                'amount' => $sample['amount'],
                'purpose' => $sample['purpose'],
            ]);

            $engine->start($request, $definition);
        }
    }
}
