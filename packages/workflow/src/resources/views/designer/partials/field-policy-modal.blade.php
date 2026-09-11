{{-- The node inspector's "Edit field policy" modal — table markup only; all
     behavior (loading fields, drag-reorder, import-from, validation-on-save)
     lives in scripts/field-policy.blade.php. --}}
<div class="modal" id="wf-field-policy-modal" tabindex="-1" role="dialog">
    <div class="modal-xl modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Field policy &mdash; <span id="wf-field-policy-node-name"></span></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" id="wf-field-policy-table">
                    <thead>
                        <tr>
                            <th style="width: 24px"></th>
                            <th>Column</th>
                            <th style="width: 56px">Show</th>
                            <th>Label</th>
                            <th>Type</th>
                            <th style="width: 70px">Readonly</th>
                            <th style="width: 600px">Custom field definition</th>
                        </tr>
                    </thead>
                    <tbody id="wf-field-policy-rows"></tbody>
                </table>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div class="form-inline mr-3">
                    <label class="mr-2 mb-0">Import from</label>
                    <select class="form-control mr-2" id="wf-field-policy-import-select" style="width: auto"></select>
                    <button type="button" class="btn btn-outline-secondary" onclick="Alpine.$data(document.querySelector('[x-data]')).importFieldPolicy()">Import</button>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="Alpine.$data(document.querySelector('[x-data]')).saveFieldPolicyModal()">Save</button>
                </div>
            </div>
        </div>
    </div>
</div>
