<!-- textarea -->
@php
    $readonly = (bool) ($field['readonly'] ?? false);
@endphp
@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? ''])
    @else
        <textarea
        	name="{{ $field['name'] }}"
            @include('crud::fields.inc.attributes')

        	>{{ old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '' }}</textarea>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')