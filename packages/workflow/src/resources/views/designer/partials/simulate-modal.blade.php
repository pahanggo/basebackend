{{-- "Test with a sample record" dry-run modal — lets a designer pick a real
     record and walk it through the CURRENT in-editor graph (published or
     not, saved or not) without ever creating a real instance/token row or
     firing a real action. All behavior lives in scripts/simulate.blade.php,
     backed by WorkflowSimulateController/WorkflowSimulator. --}}
<div class="modal" id="wf-simulate-modal" tabindex="-1" role="dialog">
    <div class="modal-lg modal-dialog modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Test with a sample record</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Runs a real record through this graph as currently drawn (including unsaved changes) —
                    nothing here is persisted, and no email/notification/webhook/timer actually fires.
                </p>

                <div class="form-group">
                    <label class="mb-0 small">Sample record</label>
                    <select class="wf-simulate-record-select2" style="width:100%"></select>
                </div>

                <div class="form-group">
                    <label class="mb-0 small">Simulate as</label>
                    <select class="wf-simulate-actor-select2" style="width:100%"></select>
                    <small class="form-text text-muted">Defaults to you — most transitions are gated by actor_rule (role/permission/model_callback), so testing as yourself alone will often show everything as unavailable unless you hold every role the graph checks.</small>
                </div>

                <div id="wf-simulate-body" style="display:none">
                    <hr>
                    <p class="mb-1">
                        Currently at: <strong id="wf-simulate-current-node"></strong>
                    </p>
                    <div id="wf-simulate-edges"></div>

                    <div id="wf-simulate-log-wrap" class="mt-3" style="display:none">
                        <label class="mb-1 d-block small">Trail</label>
                        <ol id="wf-simulate-log" class="small mb-0"></ol>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" onclick="Alpine.$data(document.querySelector('[x-data]')).resetSimulate()">Reset</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
