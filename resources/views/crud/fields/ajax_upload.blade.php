{{-- ajax_upload: uploads the file immediately via the "ajax-upload" route and submits only the stored path.

     Options:
       - disk      => 'public'          (must be in config('ajax_upload.disks'))
       - path      => 'invoices'        sub-folder under config('ajax_upload.base_path')
       - accept    => 'image/*,.pdf'    passed to the file input
       - max_size  => 2048              kilobytes, capped by config('ajax_upload.max_size_kb')
       - upload_url                     override the endpoint
       - readonly  => false             when true, show the file as a plain link with no form control and no name attribute (not submitted)
--}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $disk = $field['disk'] ?? config('ajax_upload.default_disk');
    $readonly = (bool) ($field['readonly'] ?? false);
    $initialFiles = [];
    if (is_string($value) && $value !== '') {
        $initialFiles[] = ['path' => $value, 'url' => Storage::disk($disk)->url($value), 'name' => basename($value)];
    }

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'ajax_upload';
    $field['wrapper']['data-field-name'] = $field['name'];
    if (! $readonly) {
        $field['wrapper']['data-init-function'] = 'bpFieldInitAjaxUploadElement';
    }
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['raw' => true, 'value' => count($initialFiles) ? '<a href="'.e($initialFiles[0]['url']).'" target="_blank">'.e($initialFiles[0]['name']).'</a>' : ''])
    @else
    @include('crud::fields.inc.ajax_upload_markup', ['multiple' => false, 'initialFiles' => $initialFiles])
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

@if ($crud->fieldTypeNotLoaded($field))
    @php
        // both ajax upload fields share one script/style block
        $crud->markFieldTypeAsLoaded($field);
        $crud->markFieldTypeAsLoaded(['type' => 'ajax_multi_upload']);
    @endphp
    @include('crud::fields.inc.ajax_upload_script')
@endif
