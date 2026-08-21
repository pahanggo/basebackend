<div wire:init="loadCount" class="d-flex h-100 w-100">
    <div class="card mb-0 flex-grow-1 h-100">
        <div class="card-body p-0 d-flex align-items-center h-100">
            <i class="la la-users bg-primary p-4 font-2xl mr-3 d-flex align-items-center justify-content-center h-100"></i>
            <div>
                <div class="text-value-sm text-primary">
                    {{$count}}
                </div>
                <div class="text-muted text-uppercase font-weight-bold small">Users</div>
            </div>
        </div>
    </div>
</div>