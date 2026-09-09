{{-- ajax_multi_upload: uploads each file immediately via the "ajax-upload" route and submits a JSON
     array of stored paths (cast the attribute to array). Files can be reordered by drag and drop.
     Options: same as ajax_upload. --}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? [];
    if (is_string($value)) {
        $value = json_decode($value, true) ?: [];
    }
    $disk = $field['disk'] ?? config('ajax_upload.default_disk');
    $initialFiles = collect((array) $value)
        ->filter(fn ($path) => is_string($path) && $path !== '')
        ->map(fn (string $path) => ['path' => $path, 'url' => Storage::disk($disk)->url($path), 'name' => basename($path)])
        ->values()
        ->all();

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'ajax_multi_upload';
    $field['wrapper']['data-field-name'] = $field['name'];
    $field['wrapper']['data-init-function'] = 'bpFieldInitAjaxUploadElement';
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @include('crud::fields.inc.ajax_upload_markup', ['multiple' => true, 'initialFiles' => $initialFiles])

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

@if ($crud->fieldTypeNotLoaded($field))
    @php
        // both ajax upload fields share one script/style block
        $crud->markFieldTypeAsLoaded($field);
        $crud->markFieldTypeAsLoaded(['type' => 'ajax_upload']);
    @endphp
    @include('crud::fields.inc.ajax_upload_script')
@endif
