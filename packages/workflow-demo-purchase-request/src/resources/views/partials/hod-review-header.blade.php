{{--
    Sample header_view, wired onto the 'pending_hod_review' node in
    PurchaseRequestDemoSeeder::graph() — proves out the node inspector's
    header_view field (rendered above the field-policy column list on the
    "show-workflow" page, see Workflow\Http\Controllers\WorkflowShowController
    and packages/workflow/src/resources/views/show.blade.php). Receives the
    record as $entry, same as footer_view.
--}}
<div class="alert alert-primary mb-3">
    <strong>{{ $entry->requester()?->name ?? 'Unknown requester' }}</strong> is asking to spend
    <strong>RM {{ number_format($entry->amount, 2) }}</strong> on:
    <em>{{ $entry->purpose }}</em>
</div>
