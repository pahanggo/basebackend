{{--
    Top-of-list bulk action for any CrudController that `use
    WorkflowBulkTransitionOperation` on a model `use HasWorkflow`. Lists every
    manual edge in the model's published workflow graph whose `surfaces`
    declares 'bulk_action' (independent of which node any given selected row
    is actually on — the endpoint checks that per row) in a dropdown, then
    POSTs the current bulk selection + chosen edge, same shape as
    crud/buttons/bulk_delete.blade.php.
--}}
@php
    $workflowDefinition = \Workflow\Models\WorkflowDefinition::where('model', get_class($crud->model))->first();
    $bulkEdges = $workflowDefinition?->publishedVersion
        ? $workflowDefinition->publishedVersion->edgesWithSurface('bulk_action')
        : [];
@endphp

@if ($crud->hasAccess('workflowBulkTransition') && $crud->get('list.bulkActions') && count($bulkEdges))
    <div class="btn-group bulk-button">
        <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="la la-share"></i> Transition
        </button>
        <div class="dropdown-menu">
            @foreach ($bulkEdges as $edge)
                <a class="dropdown-item" href="javascript:void(0)"
                   onclick="workflowBulkTransitionEntries('{{ $edge['id'] }}', '{{ $edge['button_label'] ?? $edge['name'] ?? $edge['id'] }}', '{{ collect($edge['inputs'] ?? [])->pluck('name')->implode(',') }}')">
                    {{ $edge['button_label'] ?? $edge['name'] ?? $edge['id'] }}
                </a>
            @endforeach
        </div>
    </div>
@endif

@push('after_scripts')
<script>
    if (typeof workflowBulkTransitionEntries != 'function') {
        function workflowBulkTransitionEntries(edgeId, edgeLabel, inputNamesCsv) {
            if (typeof crud.checkedItems === 'undefined' || crud.checkedItems.length === 0) {
                new Noty({ type: 'warning', text: 'Please select at least one entry.' }).show();

                return;
            }

            swal({
                title: "{!! trans('backpack::base.warning') !!}",
                text: 'Fire "' + edgeLabel + '" on ' + crud.checkedItems.length + ' selected entries?',
                icon: 'warning',
                buttons: {
                    cancel: { text: "{!! trans('backpack::crud.cancel') !!}", value: null, visible: true, className: 'bg-secondary', closeModal: true },
                    confirm: { text: 'Transition', value: true, visible: true, className: 'bg-primary' },
                },
            }).then((confirmed) => {
                if (! confirmed) {
                    return;
                }

                var inputs = {};
                var inputNames = (inputNamesCsv || '').split(',').filter(Boolean);
                for (var i = 0; i < inputNames.length; i++) {
                    var value = window.prompt(inputNames[i] + ':');
                    if (value === null) {
                        return;
                    }
                    inputs[inputNames[i]] = value;
                }

                $.ajax({
                    url: "{{ url($crud->route) }}/workflow-bulk-transition",
                    type: 'POST',
                    data: { entries: crud.checkedItems, edge_id: edgeId, inputs: inputs },
                    success: function (result) {
                        var succeeded = (result.succeeded || []).length;
                        var skipped = result.skipped || [];

                        if (succeeded) {
                            new Noty({ type: 'success', text: succeeded + ' entr' + (succeeded === 1 ? 'y' : 'ies') + ' transitioned.' }).show();
                        }
                        skipped.forEach(function (row) {
                            new Noty({ type: 'warning', text: 'Entry #' + row.id + ': ' + row.reason }).show();
                        });

                        crud.checkedItems = [];
                        crud.table.draw(false);
                    },
                    error: function () {
                        new Noty({ type: 'warning', text: 'The bulk transition could not be completed.' }).show();
                    },
                });
            });
        }
    }
</script>
@endpush
