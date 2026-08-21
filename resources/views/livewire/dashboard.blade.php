<div>
    <div class="d-flex justify-content-between mb-3 pt-4">
        <div class="d-sm-flex d-block text-center text-sm-left">
            <div class="welcome-avatar">
                <img style="height: 50px" class="img-avatar" src="{{ backpack_avatar_url(backpack_auth()->user()) }}" alt="{{ backpack_auth()->user()->name }}">
            </div>
            <div class="ml-sm-2">
                <h1 class="mb-0 d-none d-sm-block">
                    {{ __('Welcome') }} {{ user()->name }}
                </h1>
                <h3 class="mb-0 d-block d-sm-none mt-2">
                    {{ __('Welcome') }} {{ user()->name }}
                </h3>
                <div>
                    <small>
                        <em>
                            {{ user()->email }} @if(user()->roles->count())- {{ implode(', ', user()->roles->pluck('name')->toArray()) }} @endif
                        </em>
                    </small>
                </div>
            </div>
        </div>
        <div class="input-group date align-self-start" style="max-width: 320px;">
        @if($editing)
            <select class="add-widget form-control" wire:change="addWidget($event.target.value)">
                <option value="">Tambah Widget</option>
                @foreach($available as $aWidget)
                    <option value="{{ $aWidget['path'] }}">{{ $aWidget['name'] }}</option>
                @endforeach
            </select>
            <div class="input-group-append">
                <button class="input-group-btn btn btn-default" wire:click="toggleEdit">
                    <i class="la la-save"></i>
                </button>
            </div>
        @else
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="la la-calendar"></i></span>
            </div>
            <input type="text" class="form-control" id="dashboard-date-range" readonly
                data-start-at="{{ $startAt }}" data-end-at="{{ $endAt }}">
            <div class="input-group-append">
                <button class="input-group-btn btn btn-default" wire:click="toggleEdit">
                    @if($editing)
                        <i class="la la-save"></i>
                    @else
                        <i class="la la-pencil"></i>
                    @endif
                </button>
            </div>
        @endif
        </div>
    </div>


    {{-- GridStack takes full ownership of this subtree's DOM once painted: Livewire's
         own re-renders (triggered by toggleEdit/updateLayout/addWidget/etc.) would
         otherwise reset the classes GridStack's CSS positioning depends on (see
         Dashboard::addWidget()/removeWidget() docblock). Widget add/remove is synced
         explicitly via dispatched browser events instead - see the @script block. --}}
    <div class="grid-stack mb-2" id="dashboard-grid" wire:ignore>
        @foreach($widgets as $item)
            @include('livewire.partials.grid-item', ['item' => $item])
        @endforeach
    </div>
</div>

@assets
    <link rel="stylesheet" type="text/css" href="{{ asset('packages/gridstack/gridstack.min.css') }}">
    <script type="text/javascript" src="{{ asset('packages/gridstack/gridstack-all.js') }}"></script>
@endassets

@script
<script>
    const gridEl = document.getElementById('dashboard-grid');

    const grid = GridStack.init({
        column: 12,
        cellHeight: 50,
        margin: 10,
        float: true,
        animate: false,
        staticGrid: !$wire.editing,
        // GridStack positions each .grid-stack-item's row (gs-y/gs-h) via CSS
        // rules injected into a <style> tag at runtime. By default that tag
        // is inserted as a sibling of .grid-stack, inside the Livewire
        // component's own root <div> - which is NOT wire:ignore'd (only
        // .grid-stack itself is). Every Livewire commit that re-renders the
        // root (toggleEdit, updateLayout, add/remove widget - i.e. all of
        // them) morphs that root's children against the server-rendered
        // HTML, and since the injected <style> tag was never part of that
        // HTML, morphdom strips it as an unrecognized node - collapsing
        // every item to top/height 0 until something reinjects it. Root
        // cause, not a timing issue: fix it by moving the tag into <head>,
        // permanently outside anything Livewire ever morphs.
        styleInHead: true,
    }, gridEl);

    gridEl.classList.toggle('is-editing', $wire.editing);

    grid.on('change', function (event, items) {
        if (!items) {
            return;
        }

        $wire.updateLayout(items.map(function (item) {
            return { id: item.id, x: item.x, y: item.y, w: item.w, h: item.h };
        }));
    });

    // The grid container is wire:ignore'd (see the Blade template) so it
    // never gets touched by Livewire's own re-renders for its own children/
    // attributes. Editing mode is instead reflected purely client-side.
    $wire.$watch('editing', function (value) {
        grid.setStatic(!value);
        gridEl.classList.toggle('is-editing', value);

        // #dashboard-date-range only exists in the DOM while NOT editing
        // (see the editing-conditional toggle in the toolbar markup) - it's
        // a brand new element each time editing turns off, so the
        // daterangepicker plugin bound to the previous node needs rebinding.
        // Deferred one microtask so this runs after Livewire's morph has
        // actually inserted the new input.
        if (!value) {
            queueMicrotask(function () {
                if (window.initDashboardDatePicker) {
                    window.initDashboardDatePicker();
                }
            });
        }
    });

    // ...and add/remove widget - which DO change the item set - are synced
    // explicitly via dispatched events instead of relying on a Blade re-render.
    $wire.$on('widget-added', function (payload) {
        const template = document.createElement('div');
        template.innerHTML = payload.html.trim();
        grid.addWidget(template.firstElementChild);
    });

    $wire.$on('widget-removed', function (payload) {
        const el = gridEl.querySelector('[gs-id="' + payload.id + '"]');
        if (el) {
            grid.removeWidget(el);
        }
    });

    // Same new Noty({type, text}).show() convention used throughout
    // resources/views/crud/buttons/*.blade.php - defaults (theme, position,
    // timeout) come from Noty.overrideDefaults() in base/inc/alerts.blade.php.
    $wire.$on('notify', function (payload) {
        new Noty({ type: payload.type || 'success', text: payload.text }).show();
    });
</script>
@endscript

@push('after_styles')
    <link rel="stylesheet" type="text/css" href="{{ asset('packages/bootstrap-daterangepicker/daterangepicker.css') }}">
@endpush

@push('after_scripts')
    <script type="text/javascript" src="{{ asset('packages/moment/min/moment-with-locales.min.js') }}"></script>
    <script type="text/javascript" src="{{ asset('packages/bootstrap-daterangepicker/daterangepicker.js') }}"></script>

    <script>
        // Exposed on window (rather than only inside the jQuery ready
        // callback below) so the Livewire script block above can rebuild it
        // whenever #dashboard-date-range reappears in the DOM after toggling edit
        // mode off - see the $wire.$watch('editing', ...) handler.
        //
        // window.dashboardDateRange tracks the currently-selected range
        // client-side. $startAt/$endAt on the Dashboard component are only
        // ever set once in mount() (picking a range dispatches
        // 'date-range-updated' straight to the widgets, it never updates the
        // Dashboard component itself) - so the input's data-start-at/
        // data-end-at attributes are always the original defaults, and a
        // rebuilt input would otherwise reset the visible selection instead
        // of keeping what the user picked.
        window.initDashboardDatePicker = function () {
            var input = jQuery('#dashboard-date-range');

            if (!input.length) {
                return;
            }

            if (input.data('daterangepicker')) {
                input.data('daterangepicker').remove();
            }

            var current = window.dashboardDateRange || {
                start: input.data('start-at'),
                end: input.data('end-at'),
            };

            input.daterangepicker({
                startDate: moment(current.start),
                endDate: moment(current.end),
                alwaysShowCalendars: true,
                autoUpdateInput: true,
                opens: 'left',
                ranges: {
                    'Hari Ini': [moment(), moment()],
                    'Semalam': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    '7 Hari Lepas': [moment().subtract(6, 'days'), moment()],
                    '30 Hari Lepas': [moment().subtract(29, 'days'), moment()],
                    'Bulan Ini': [moment().startOf('month'), moment().endOf('month')],
                    'Bulan Lepas': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                },
                locale: {
                    format: 'DD/MM/YYYY',
                    applyLabel: 'Guna',
                    cancelLabel: 'Batal',
                    customRangeLabel: 'Julat Tersuai',
                    daysOfWeek: ['Ah', 'Is', 'Se', 'Ra', 'Kh', 'Ju', 'Sa'],
                    monthNames: ['Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'],
                },
            });

            input.on('apply.daterangepicker', function (ev, picker) {
                window.dashboardDateRange = {
                    start: picker.startDate.format('YYYY-MM-DD'),
                    end: picker.endDate.format('YYYY-MM-DD'),
                };

                Livewire.dispatch('date-range-updated', {
                    startAt: picker.startDate.format('YYYY-MM-DD'),
                    endAt: picker.endDate.format('YYYY-MM-DD'),
                });
            });
        };

        jQuery(document).ready(function () {
            window.initDashboardDatePicker();
        });
    </script>
@endpush
