{{-- Definition-level settings modal, opened from the wf-float-toolbar's gear
     icon. Content is rendered into #wf-settings-body by
     scripts/settings.blade.php's renderSettingsModal() (same raw-innerHTML
     convention as the node/edge inspector), not real Alpine bindings — see
     that mixin's own docblock for why. --}}
<div class="modal" id="wf-settings-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Workflow settings</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="wf-settings-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
