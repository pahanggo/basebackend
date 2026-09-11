<!-- password -->

@php
    // autocomplete off, if not otherwise specified
    if (!isset($field['attributes']['autocomplete'])) {
        $field['attributes']['autocomplete'] = "off";
    }
    $readonly = (bool) ($field['readonly'] ?? false);
    $hasValue = (old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '') !== '';
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        {{-- the raw password is never shown, even in readonly mode --}}
        @include('crud::fields.inc.readonly_value', ['value' => $hasValue ? '••••••••' : ''])
    @else
    <input
    	type="password"
    	name="{{ $field['name'] }}"
        @include('crud::fields.inc.attributes')
    	>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')
