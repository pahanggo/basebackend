{{-- Shared markup for ajax_upload (single) and ajax_multi_upload (multiple).
     Expects $field, $multiple (bool), $initialFiles (array of ['path','url','name']). --}}
@php
    $disk = $field['disk'] ?? config('ajax_upload.default_disk');
    $maxSize = (int) ($field['max_size'] ?? config('ajax_upload.max_size_kb'));
@endphp

<div class="ajax-upload"
     x-ignore
     x-data="bpAjaxUpload({
         files: @js($initialFiles),
         multiple: @js($multiple),
         uploadUrl: @js($field['upload_url'] ?? route('ajax-upload')),
         disk: @js($disk),
         path: @js($field['path'] ?? ''),
         maxSizeKb: {{ $maxSize }},
         messages: @js([
             'tooLarge' => trans('backpack::crud.ajax_upload_too_large', ['max' => round($maxSize / 1024, 1)]),
             'failed' => trans('backpack::crud.ajax_upload_failed'),
             'sessionExpired' => trans('backpack::crud.ajax_upload_session_expired'),
         ]),
     })"
     @dragover.prevent="dragOverZone = true"
     @dragleave.prevent="dragOverZone = false"
     @drop.prevent="dragOverZone = false; addFiles($event.dataTransfer.files)">

    @if ($multiple)
        <input type="hidden" name="{{ $field['name'] }}" :value="JSON.stringify(files.map(f => f.path))">
    @else
        <input type="hidden" name="{{ $field['name'] }}" :value="files.length ? files[0].path : ''">
    @endif

    <input type="file" class="d-none" x-ref="input" @if ($multiple) multiple @endif
           @if (isset($field['accept'])) accept="{{ $field['accept'] }}" @endif
           @change="addFiles($event.target.files); $event.target.value = ''">

    <ul class="list-unstyled ajax-upload-files mb-0" x-show="files.length || uploads.length">
        <template x-for="(file, index) in files" :key="file.path">
            <li class="ajax-upload-file"
                :class="{ 'is-dragging': dragging === index }"
                :draggable="multiple && dragging === index"
                @dragstart.stop="startDrag($event, index)"
                @dragover.prevent.stop="dragOver(index)"
                @dragend="dragging = null">
                @if ($multiple)
                    <span class="ajax-upload-handle" @mousedown="dragging = index" title="{{ trans('backpack::crud.reorder') }}"><i class="la la-sort"></i></span>
                @endif
                <a :href="file.url" target="_blank" class="ajax-upload-thumb">
                    <img x-show="isImage(file.path)" :src="file.url" alt="">
                    <i x-show="!isImage(file.path)" class="la la-file-alt"></i>
                </a>
                <a :href="file.url" target="_blank" class="ajax-upload-name" x-text="file.name || file.path"></a>
                <button type="button" class="btn btn-sm btn-light" @click="remove(index)" title="{{ trans('backpack::crud.delete') }}"><i class="la la-trash"></i></button>
            </li>
        </template>
        <template x-for="upload in uploads" :key="upload.id">
            <li class="ajax-upload-file is-uploading">
                <span class="ajax-upload-thumb"><i class="la la-spinner la-spin"></i></span>
                <span class="ajax-upload-name">
                    <span x-text="upload.name"></span>
                    <span class="text-danger small" x-show="upload.error" x-text="upload.error"></span>
                    <div class="progress" x-show="!upload.error"><div class="progress-bar" :style="{ width: upload.progress + '%' }"></div></div>
                </span>
                <button type="button" class="btn btn-sm btn-light" x-show="upload.error" @click="dismiss(upload.id)"><i class="la la-times"></i></button>
            </li>
        </template>
    </ul>

    <div class="ajax-upload-zone" :class="{ 'is-over': dragOverZone }" x-show="multiple || !files.length">
        <button type="button" class="btn btn-sm btn-light" @click="$refs.input.click()">
            <i class="la la-cloud-upload"></i> {{ trans($multiple ? 'backpack::crud.ajax_upload_choose_files' : 'backpack::crud.choose_file') }}
        </button>
        <span class="text-muted small">{{ trans('backpack::crud.ajax_upload_drop') }}</span>
    </div>
</div>
