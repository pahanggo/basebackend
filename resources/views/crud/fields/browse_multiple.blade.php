@php
$multiple = Arr::get($field, 'multiple', true);
$sortable = Arr::get($field, 'sortable', false);
$value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';

if (!$multiple && is_array($value)) {
    $value = Arr::first($value);
}

$field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
$field['wrapper']['data-init-function'] = $field['wrapper']['data-init-function'] ?? 'bpFieldInitBrowseMultipleElement';
$field['wrapper']['data-elfinder-trigger-url'] = $field['wrapper']['data-elfinder-trigger-url'] ?? url(config('elfinder.route.prefix').'/popup/'.$field['name'].'?multiple=1');

if (isset($field['mime_types'])) {
    $field['wrapper']['data-elfinder-trigger-url'] .= '&mimes='.urlencode(serialize($field['mime_types']));
}

if ($multiple) {
    $field['wrapper']['data-multiple'] = "true";
} else {
    $field['wrapper']['data-multiple'] = "false";
}

if($sortable){
    $field['wrapper']['sortable'] = "true";
}
@endphp

@include('crud::fields.inc.wrapper_start')

    <div><label>{!! $field['label'] !!}</label></div>
    @include('crud::fields.inc.translatable_icon')
    @if ($multiple)
        {{-- x-ignore until Backpack's init pipeline calls bpFieldInitBrowseMultipleElement (keeps repeatable clones clean) --}}
        <div class="list" data-field-name="{{ $field['name'] }}"
             x-ignore
             x-data="bpBrowseMultiple({ paths: @js(array_values(array_filter((array) $value, fn ($path) => $path !== '' && $path !== null))), sortable: @js((bool) $sortable) })">
            <input type="hidden" data-marker="multipleBrowseInput" name="{{ $field['name'] }}" :value="JSON.stringify(paths)">
            <template x-for="(path, index) in paths" :key="path + '-' + index">
                <div class="input-group input-group-sm"
                     :class="{ 'is-dragging': dragging === index }"
                     :draggable="sortable && dragging === index"
                     @dragstart="startDrag($event, index)"
                     @dragover.prevent="dragOver($event, index)"
                     @dragend="endDrag()">
                    <input type="text" :value="path" @include('crud::fields.inc.attributes') readonly>
                    <div class="input-group-btn">
                        <button type="button" class="browse remove btn btn-sm btn-light" @click="remove(index)">
                            <i class="la la-trash"></i>
                        </button>
                        @if($sortable)
                            <button type="button" class="browse move btn btn-sm btn-light" @mousedown="armDrag(index)" style="cursor: grab;"><span class="la la-sort"></span></button>
                        @endif
                    </div>
                </div>
            </template>
        </div>
    @else
        <div class="list" data-field-name="{{ $field['name'] }}">
            <input type="text" data-marker="multipleBrowseInput" name="{{ $field['name'] }}" value="{{ $value }}" @include('crud::fields.inc.attributes') readonly>
        </div>
    @endif
    <div class="btn-group" role="group" aria-label="..." style="margin-top: 3px;">
        <button type="button" class="browse popup btn btn-sm btn-light">
            <i class="la la-cloud-upload"></i>
            {{ trans('backpack::crud.browse_uploads') }}
        </button>
        <button type="button" class="browse clear btn btn-sm btn-light">
            <i class="la la-eraser"></i>
            {{ trans('backpack::crud.clear') }}
        </button>
    </div>

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

    {{-- FIELD CSS - will be loaded in the after_styles section --}}
    @push('crud_fields_styles')        
        <link href="{{ asset('packages/jquery-colorbox/example2/colorbox.css') }}" rel="stylesheet" type="text/css" />
        <style>
            #cboxContent, #cboxLoadedContent, .cboxIframe {
                background: transparent;
            }
            [data-field-type] .list .input-group.is-dragging {
                opacity: .5;
            }
        </style>
    @endpush

    @push('crud_fields_scripts')
        
        <script src="{{ asset('packages/jquery-colorbox/jquery.colorbox-min.js') }}"></script>
        <script>
            // this global variable is used to remember what input to update with the file path
            // because elfinder is actually loaded in an iframe by colorbox
            var elfinderTarget = false;

            // function to use the files selected inside elfinder
            function processSelectedMultipleFiles(files, requestingField) {
                elfinderTarget.trigger('createInputsForItemsSelectedWithElfinder', [files]);
                elfinderTarget = false;
            }

            Alpine.data('bpBrowseMultiple', function (config) {
                return {
                    paths: Array.isArray(config.paths) ? config.paths : [],
                    sortable: !! config.sortable,
                    dragging: null,

                    add: function (path) { this.paths.push(path); },
                    remove: function (index) { this.paths.splice(index, 1); },
                    clear: function () { this.paths = []; },

                    armDrag: function (index) { this.dragging = index; },
                    startDrag: function (event, index) {
                        this.dragging = index;
                        event.dataTransfer.effectAllowed = 'move';
                    },
                    dragOver: function (event, index) {
                        if (this.dragging === null || this.dragging === index) return;
                        var moved = this.paths.splice(this.dragging, 1)[0];
                        this.paths.splice(index, 0, moved);
                        this.dragging = index;
                    },
                    endDrag: function () { this.dragging = null; }
                };
            });

            function bpFieldInitBrowseMultipleElement(element) {
                var $triggerUrl = element.data('elfinder-trigger-url');
                var $list = element.find(".list");
                var $input = element.find('input[data-marker=multipleBrowseInput]');
                var $multiple = element.attr('data-multiple');
                var list = $list[0];

                // multiple: the list is an Alpine component that owns the paths and the hidden JSON input
                if ($multiple === 'true' && list && ! list._x_dataStack) {
                    delete list._x_ignore;
                    list.removeAttribute('x-ignore');
                    Alpine.initTree(list);
                }
                var state = function () { return Alpine.$data(list); };

                element.on('click', 'button.popup', function (event) {
                    event.preventDefault();

                    // remember which element the elFinder was triggered by
                    elfinderTarget = element;

                    // trigger the elFinder modal
                    $.colorbox({
                        href: $triggerUrl,
                        fastIframe: true,
                        iframe: true,
                        width: '80%',
                        height: '80%'
                    });
                });

                if ($multiple === 'true') {
                    element.on('click', 'button.clear', function (event) {
                        event.preventDefault();
                        state().clear();
                    });

                    // called after one or more items are selected in the elFinder window
                    element.on('createInputsForItemsSelectedWithElfinder', element, function(event, files) {
                        files.forEach(function (file) { state().add(file.path); });
                    });
                } else {
                    element.on('click', 'button.clear', function (event) {
                        $input.val('');
                    });

                    // called after an item has been selected in the elFinder window
                    element.on('createInputsForItemsSelectedWithElfinder', element, function(event, files) {
                        $input.val(files[0].path);
                    });
                }
            }
        </script>
    @endpush
@endif

{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
