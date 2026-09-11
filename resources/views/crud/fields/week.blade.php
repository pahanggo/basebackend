<!-- html5 week input -->
@php
    $readonly = (bool) ($field['readonly'] ?? false);
@endphp
@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? ''])
    @else
    <input
        type="week"
        name="{{ $field['name'] }}"
        value="{{ old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '' }}"
        @include('crud::fields.inc.attributes')
        >
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')