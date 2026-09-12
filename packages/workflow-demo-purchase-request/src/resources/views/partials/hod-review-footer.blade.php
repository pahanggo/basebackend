{{--
    Sample footer_view, wired onto the 'pending_hod_review' node in
    PurchaseRequestDemoSeeder::graph() — proves out the node inspector's
    footer_view field (rendered below the field-policy column list, above
    the available transitions, on the "show-workflow" page). Receives the
    record as $entry, same as header_view.
--}}
<p class="text-muted small mb-2 mt-2">
    Tip: use "Request department feedback" instead of approving outright for large or ambiguous requests
    ({{ $entry->amount >= 5000 ? 'this one qualifies' : 'this one is small enough to skip that' }}).
</p>
