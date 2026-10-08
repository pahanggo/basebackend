<!-- Tiny MCE -->
@php
$defaultOptions = [
    'selector' => 'textarea.tinymce',
    'plugins' => 'image,link,media,anchor',
    //these two options allow tinymce to save the path of images "/upload/image.jpg" instead of the relative server path "../../../uploads/image.jpg"
    'relative_urls' =>  false,
    'remove_script_host' => true,
];

$field['options'] = array_merge($defaultOptions, $field['options'] ?? []);
$readonly = (bool) ($field['readonly'] ?? false);
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['raw' => true, 'value' => old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? ''])
    @else
    <textarea
        name="{{ $field['name'] }}"
        data-init-function="bpFieldInitTinyMceElement"
        data-options='{!! trim(json_encode($field['options'])) !!}'
        @include('crud::fields.inc.attributes', ['default_class' =>  'form-control tinymce'])
        >{{ old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '' }}</textarea>
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
    <!-- include tinymce js-->
    <script src="{{ asset('packages/tinymce/tinymce.min.js') }}"></script>

    <script type="text/javascript">
    function bpFieldInitTinyMceElement(element) {
        // grab the configuration defined in PHP
        var configuration = element.data('options');

        // the target should be the element the function has been called on
        configuration['target'] = element;
        // Only resolve a file picker when the field actually supplies one. This base ships
        // no file manager, so there is no default callback to eval.
        if (configuration['file_picker_callback']) {
            configuration['file_picker_callback'] = eval(configuration['file_picker_callback']);
        }

        // automatically update the textarea value on focusout
        configuration['setup'] = (function (editor) {
            editor.on('change', function () {
                tinymce.triggerSave();
            });
        });

        // initialize the TinyMCE editor
        tinymce.init(element.data('options'));
    }

    </script>
    @endpush

@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
