<!-- Backpack Table Field Type (Alpine.js) -->

<?php
    $max = isset($field['max']) && (int) $field['max'] > 0 ? $field['max'] : -1;
    $min = isset($field['min']) && (int) $field['min'] > 0 ? $field['min'] : -1;
    $item_name = strtolower(isset($field['entity_singular']) && ! empty($field['entity_singular']) ? $field['entity_singular'] : $field['label']);

    $items = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';

    // make sure no matter the attribute casting
    // the $items variable contains a properly defined JSON string
    if (is_array($items)) {
        if (count($items)) {
            $items = json_encode($items);
        } else {
            $items = '[]';
        }
    } elseif (is_string($items) && ! is_array(json_decode($items))) {
        $items = '[]';
    }

    // make sure columns are defined
    if (! isset($field['columns'])) {
        $field['columns'] = ['value' => 'Value'];
    }

    $readonly = (bool) ($field['readonly'] ?? false);

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'table';
    $field['wrapper']['data-field-name'] = $field['name'];

    if ($readonly) {
        $readonlyRows = json_decode($items, true) ?: [];
    }
?>
@include('crud::fields.inc.wrapper_start')

    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        <table class="table table-sm table-striped m-b-0">
            <thead>
                <tr>
                    @foreach ($field['columns'] as $column)
                        <th style="font-weight: 600!important;">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($readonlyRows as $row)
                    <tr>
                        @foreach ($field['columns'] as $key => $label)
                            <td>{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
    {{-- The container starts as x-ignore so Alpine does not initialise it on its own;
         bpFieldInitTableElement (called by Backpack's field init pipeline, including
         for repeatable clones) lifts the ignore and initialises the tree. --}}
    <div class="array-container form-group"
         x-ignore
         x-data="bpTableField({
             rows: {{ $items }},
             columns: @js(array_keys($field['columns'])),
             min: {{ $min }},
             max: {{ $max }},
             maxErrorTitle: @js(trans('backpack::crud.table_cant_add', ['entity' => $item_name])),
             maxErrorMessage: @js(trans('backpack::crud.table_max_reached', ['max' => $max])),
         })">

        <input class="array-json"
               type="hidden"
               name="{{ $field['name'] }}"
               value="{{ $items === '[]' ? '' : $items }}"
               data-init-function="bpFieldInitTableElement"
               :value="serialized">

        <table class="table table-sm table-striped m-b-0">

            <thead>
                <tr>
                    @foreach( $field['columns'] as $column )
                    <th style="font-weight: 600!important;">
                        {{ $column }}
                    </th>
                    @endforeach
                    <th class="text-center"></th>
                    <th class="text-center"></th>
                </tr>
            </thead>

            <tbody class="table-striped items">
                <template x-for="(row, index) in rows" :key="row._id">
                    <tr class="array-row"
                        :class="{ 'table-active': dragging === index }"
                        :draggable="dragging === index"
                        @dragstart="dragging = index"
                        @dragover.prevent="moveTo(index)"
                        @dragend="dragging = null">
                        @foreach( $field['columns'] as $column => $label)
                        <td>
                            <input class="form-control form-control-sm" type="text" x-model="row[@js($column)]">
                        </td>
                        @endforeach
                        <td>
                            <span class="btn btn-sm btn-light sort-handle pull-right" @mousedown="dragging = index"><span class="sr-only">sort item</span><i class="la la-sort" role="presentation" aria-hidden="true"></i></span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-light removeItem" type="button" @click="removeRow(index)"><span class="sr-only">delete item</span><i class="la la-trash" role="presentation" aria-hidden="true"></i></button>
                        </td>
                    </tr>
                </template>
            </tbody>

        </table>

        <div class="array-controls btn-group m-t-10">
            <button class="btn btn-sm btn-light" type="button" @click="addRow()"><i class="la la-plus"></i> {{trans('backpack::crud.add')}} {{ $item_name }}</button>
        </div>

    </div>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
{{-- If a field type is shown multiple times on a form, the CSS and JS will only be loaded once --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    {{-- FIELD JS - will be loaded in the after_scripts section --}}
    @push('crud_fields_scripts')
        <script>
            (function () {
                var nextRowId = 0;

                Alpine.data('bpTableField', function (config) {
                    return {
                        rows: [],
                        columns: config.columns,
                        min: Number(config.min),
                        max: Number(config.max),
                        dragging: null,

                        init: function () {
                            var self = this;
                            var initial = this.currentValue();

                            this.rows = initial.map(function (row) {
                                return self.withId(Object.assign(self.blankRow(), row));
                            });

                            while (this.min > 0 && this.rows.length < this.min) {
                                this.rows.push(this.withId(this.blankRow()));
                            }
                        },

                        get serialized() {
                            if (! this.rows.length) {
                                return '';
                            }

                            return JSON.stringify(this.rows.map(function (row) {
                                var clean = {};
                                Object.keys(row).forEach(function (key) {
                                    if (key !== '_id' && String(row[key]).length > 0) {
                                        clean[key] = row[key];
                                    }
                                });
                                return clean;
                            }));
                        },

                        /**
                         * Rows to start from. The hidden input wins over the rendered config
                         * because the repeatable field writes restored values into it before
                         * calling the init function on a cloned group.
                         */
                        currentValue: function () {
                            var hidden = this.$root.querySelector('input.array-json');
                            var raw = hidden ? hidden.value : '';

                            if (typeof raw === 'string' && raw.length) {
                                try {
                                    var parsed = JSON.parse(raw);
                                    if (Array.isArray(parsed)) {
                                        return parsed;
                                    }
                                } catch (e) {}
                            }

                            return Array.isArray(config.rows) ? config.rows : [];
                        },

                        blankRow: function () {
                            var row = {};
                            this.columns.forEach(function (column) {
                                row[column] = '';
                            });
                            return row;
                        },

                        withId: function (row) {
                            row._id = ++nextRowId;
                            return row;
                        },

                        addRow: function () {
                            if (this.max > -1 && this.rows.length >= this.max) {
                                new Noty({
                                    type: 'warning',
                                    text: '<strong>' + config.maxErrorTitle + '</strong><br>' + config.maxErrorMessage
                                }).show();
                                return;
                            }

                            this.rows.push(this.withId(this.blankRow()));
                        },

                        removeRow: function (index) {
                            if (this.rows.length > this.min) {
                                this.rows.splice(index, 1);
                            }
                        },

                        moveTo: function (index) {
                            if (this.dragging === null || this.dragging === index) {
                                return;
                            }

                            var moved = this.rows.splice(this.dragging, 1)[0];
                            this.rows.splice(index, 0, moved);
                            this.dragging = index;
                        }
                    };
                });

                window.bpFieldInitTableElement = function (element) {
                    var container = element.closest('.array-container')[0];

                    if (! container || container._x_dataStack) {
                        return;
                    }

                    delete container._x_ignore;
                    container.removeAttribute('x-ignore');
                    Alpine.initTree(container);
                };
            })();
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
