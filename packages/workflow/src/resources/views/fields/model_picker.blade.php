{{--
    model_picker: an ajax-searchable select2 storing a plain fully-qualified
    class name string (not an Eloquent relationship, so the vendor
    select2_from_ajax field doesn't fit — it always hydrates the selected
    value via a related model). Backed by Workflow's EloquentModelFinder,
    listing every concrete model under app/Models.

    This is a "simple interaction" per this project's convention (jQuery +
    select2), not something that needs Alpine.
--}}
@php
    $oldValue = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? null;
    $readonly = (bool) ($field['readonly'] ?? false);
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => $oldValue ? class_basename($oldValue).' ('.$oldValue.')' : ''])
    @else
    <select
        name="{{ $field['name'] }}"
        style="width: 100%"
        data-init-function="bpFieldInitModelPickerElement"
        data-data-source="{{ $field['data_source'] ?? route('workflow.models.search') }}"
        @include('crud::fields.inc.attributes', ['default_class' => 'form-control'])
    >
        @if ($oldValue)
            <option value="{{ $oldValue }}" selected>{{ class_basename($oldValue) }} ({{ $oldValue }})</option>
        @endif
    </select>
    @endif

    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

@if ($crud->fieldTypeNotLoaded($field))
    @php $crud->markFieldTypeAsLoaded($field); @endphp

    @push('crud_fields_styles')
    <link href="{{ asset('packages/select2/dist/css/select2.min.css') }}" rel="stylesheet" type="text/css" />
    <link href="{{ asset('packages/select2-bootstrap-theme/dist/select2-bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    @endpush

    @push('crud_fields_scripts')
    <script src="{{ asset('packages/select2/dist/js/select2.full.min.js') }}"></script>
    @endpush
@endif

@push('crud_fields_scripts')
<script>
    if (typeof bpFieldInitModelPickerElement != 'function') {
        function bpFieldInitModelPickerElement(element) {
            if ($(element).hasClass('select2-hidden-accessible')) {
                return;
            }

            $(element).select2({
                theme: 'bootstrap',
                placeholder: 'Type to search models…',
                minimumInputLength: 0,
                allowClear: true,
                ajax: {
                    url: element.data('dataSource'),
                    dataType: 'json',
                    delay: 300,
                    data: params => ({ q: params.term }),
                    processResults: data => ({
                        results: data.data.map(item => ({ id: item.value, text: item.label })),
                    }),
                    cache: true,
                },
            });
        }
    }
</script>
@endpush
