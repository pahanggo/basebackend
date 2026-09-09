<!-- select_and_order (Alpine.js) -->
@php
    $values = old($field['name']) ?? $field['value'] ?? $field['default'] ?? [];
    $values = array_values(array_filter((array) $values, fn ($value) => $value !== '' && $value !== null && $value !== ' '));
    $options = collect($field['options'])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values();
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    {{-- x-ignore until Backpack's init pipeline calls bpFieldInitSelectAndOrderElement, so repeatable clones
         are taken from an un-rendered template (same approach as the table field) --}}
    <div class="row select-and-order"
         x-ignore
         x-data="bpSelectAndOrder({ options: @js($options), selected: @js(array_map('strval', $values)) })"
         data-init-function="bpFieldInitSelectAndOrderElement"
         data-field-name="{{ $field['name'] }}">
        <div class="col-md-12">
            <ul class="select_and_order_selected float-left"
                @dragover.prevent="dragOverList('selected', $event)"
                @drop.prevent="endDrag()">
                <template x-for="(item, index) in selectedItems" :key="item.value">
                    <li :draggable="dragging && dragging.value === item.value"
                        :class="{ 'is-dragging': dragging && dragging.value === item.value }"
                        @mousedown="armDrag(item)"
                        @dragstart="startDrag($event, item)"
                        @dragover.prevent.stop="dragOverItem(index, $event)"
                        @dragend="endDrag()">
                        <i class="la la-arrows"></i> <span x-text="item.label"></span>
                        <a href="#" class="float-right text-muted" @click.prevent="deselect(item.value)" title="{{ trans('backpack::crud.delete') }}"><i class="la la-times"></i></a>
                    </li>
                </template>
            </ul>
            <ul class="select_and_order_all float-right"
                @dragover.prevent="dragOverList('available', $event)"
                @drop.prevent="endDrag()">
                <template x-for="item in availableItems" :key="item.value">
                    <li :draggable="dragging && dragging.value === item.value"
                        :class="{ 'is-dragging': dragging && dragging.value === item.value }"
                        @mousedown="armDrag(item)"
                        @dragstart="startDrag($event, item)"
                        @dragend="endDrag()"
                        @dblclick="select(item.value)">
                        <i class="la la-arrows"></i> <span x-text="item.label"></span>
                        <a href="#" class="float-right text-muted" @click.prevent="select(item.value)" title="{{ trans('backpack::crud.add') }}"><i class="la la-plus"></i></a>
                    </li>
                </template>
            </ul>

            {{-- The results are stored here, in the order they were selected --}}
            <select class="d-none" name="{{ $field['name'] }}[]" multiple>
                <template x-for="value in selected" :key="value">
                    <option :value="value" selected></option>
                </template>
            </select>
        </div>

        {{-- HINT --}}
        @if (isset($field['hint']))
            <p class="help-block">{!! $field['hint'] !!}</p>
        @endif
    </div>
@include('crud::fields.inc.wrapper_end')


{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
{{-- If a field type is shown multiple times on a form, the CSS and JS will only be loaded once --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    {{-- FIELD CSS - will be loaded in the after_styles section --}}
    @push('crud_fields_styles')
    <style>
        .select_and_order_all,
        .select_and_order_selected {
            min-height: 120px;
            list-style-type: none;
            max-height: 220px;
            overflow: scroll;
            overflow-x: hidden;
            padding: 0px 5px 5px 5px;
            border: 1px solid #e6e6e6;
            width: 48%;
        }
        .select_and_order_all {
            border: none;
        }
        .select_and_order_all li,
        .select_and_order_selected li {
            border: 1px solid #eee;
            margin-top: 5px;
            padding: 5px;
            font-size: 1em;
            overflow: hidden;
            cursor: grab;
            border-style: dashed;
            user-select: none;
        }
        .select_and_order_all li {
            background: #fbfbfb;
            color: grey;
        }
        .select_and_order_selected li {
            border-style: solid;
        }
        .select_and_order_all li.is-dragging,
        .select_and_order_selected li.is-dragging {
            color: #3c8dbc;
            border: 1px dashed #3c8dbc;
            opacity: .6;
        }
    </style>
    @endpush

    {{-- FIELD JS - will be loaded in the after_scripts section --}}
    @push('crud_fields_scripts')
    <script>
        Alpine.data('bpSelectAndOrder', function (config) {
            return {
                options: config.options,
                selected: Array.isArray(config.selected) ? config.selected.map(String) : String(config.selected || '').split(',').filter(Boolean),
                dragging: null,

                get selectedItems() {
                    var self = this;
                    return this.selected.map(function (value) {
                        return self.options.find(function (option) { return option.value === value; }) || { value: value, label: value };
                    });
                },

                get availableItems() {
                    var self = this;
                    return this.options.filter(function (option) { return self.selected.indexOf(option.value) === -1; });
                },

                select: function (value, index) {
                    var current = this.selected.indexOf(value);
                    if (current !== -1) {
                        this.selected.splice(current, 1);
                        if (index !== undefined && index > current) index--;
                    }
                    this.selected.splice(index === undefined ? this.selected.length : index, 0, value);
                },

                deselect: function (value) {
                    var current = this.selected.indexOf(value);
                    if (current !== -1) this.selected.splice(current, 1);
                },

                armDrag: function (item) { this.dragging = item; },

                startDrag: function (event, item) {
                    this.dragging = item;
                    event.dataTransfer.effectAllowed = 'move';
                },

                // hovering a selected item drops the dragged one before or after it
                dragOverItem: function (index, event) {
                    if (! this.dragging) return;
                    var rect = event.currentTarget.getBoundingClientRect();
                    var target = index + (event.clientY > rect.top + rect.height / 2 ? 1 : 0);
                    var current = this.selected.indexOf(this.dragging.value);
                    if (current === index || (current !== -1 && current + 1 === target)) return;
                    this.select(this.dragging.value, target);
                },

                // hovering empty list space: append to "selected" or move back to "available"
                dragOverList: function (list, event) {
                    if (! this.dragging || event.target.closest('li')) return;
                    if (list === 'selected' && this.selected.indexOf(this.dragging.value) === -1) {
                        this.select(this.dragging.value);
                    } else if (list === 'available') {
                        this.deselect(this.dragging.value);
                    }
                },

                endDrag: function () { this.dragging = null; }
            };
        });

        function bpFieldInitSelectAndOrderElement(element) {
            var container = element[0];

            if (! container || container._x_dataStack) {
                return;
            }

            delete container._x_ignore;
            container.removeAttribute('x-ignore');
            Alpine.initTree(container);
        }
    </script>
    @endpush
@endif

{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
