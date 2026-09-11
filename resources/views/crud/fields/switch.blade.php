{{-- switch: a CoreUI toggle switch storing 0/1 (same contract as the checkbox field).

     Options:
       - color    => 'primary' | 'success' | ... (theme colour) or any CSS colour such as '#232323'
                     (custom colours set the --bg-switch-checked-color variable)
       - onLabel  => '✓'   text shown inside the switch when on
       - offLabel => '✕'   text shown inside the switch when off
       - size     => 'sm' | 'lg'
       - readonly => false  when true, show 'Yes'/'No' as plain text with no form control and no name attribute (not submitted)
--}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? 0;
    $readonly = (bool) ($field['readonly'] ?? false);
    $color = $field['color'] ?? 'primary';
    $themeColors = array_keys(config('backpack.base.theme_colors', []) + ['primary' => 1, 'secondary' => 1, 'success' => 1, 'info' => 1, 'warning' => 1, 'danger' => 1, 'light' => 1, 'dark' => 1]);
    $isThemeColor = in_array($color, $themeColors, true);
    $hasLabels = isset($field['onLabel']) || isset($field['offLabel']);
    $id = 'switch_'.preg_replace('/[^a-z0-9_]/i', '_', $field['name']).'_'.mt_rand();

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'switch';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    @include('crud::fields.inc.translatable_icon')
    @if ($readonly)
        <label class="mb-0 font-weight-normal d-block">{!! $field['label'] !!}</label>
        @include('crud::fields.inc.readonly_value', ['value' => $value ? __('Yes') : __('No')])
    @else
    <div class="d-flex align-items-center">
        <input type="hidden" name="{{ $field['name'] }}" value="{{ $value ? 1 : 0 }}">

        <label class="switch switch-pill mb-0 {{ $hasLabels ? 'switch-label' : '' }} {{ $isThemeColor ? 'switch-'.$color : 'switch-custom' }} {{ isset($field['size']) ? 'switch-'.$field['size'] : '' }}"
               @if (! $isThemeColor) style="--bg-switch-checked-color: {{ $color }};" @endif>
            <input type="checkbox"
                   class="switch-input"
                   id="{{ $id }}"
                   data-init-function="bpFieldInitSwitch"
                   @if ($value) checked="checked" @endif
                   @if (isset($field['attributes']))
                       @foreach ($field['attributes'] as $attribute => $attributeValue)
                           {{ $attribute }}="{{ $attributeValue }}"
                       @endforeach
                   @endif>
            <span class="switch-slider"
                  @if ($hasLabels) data-checked="{{ $field['onLabel'] ?? '' }}" data-unchecked="{{ $field['offLabel'] ?? '' }}" @endif></span>
        </label>

        <label class="ml-2 mb-0 font-weight-normal" for="{{ $id }}">{!! $field['label'] !!}</label>
    </div>
    @endif

    {{-- HINT --}}
    @if (isset($field['hint']))
        <p class="help-block">{!! $field['hint'] !!}</p>
    @endif
@include('crud::fields.inc.wrapper_end')

{{-- ########################################## --}}
{{-- Extra CSS and JS for this particular field --}}
@if ($crud->fieldTypeNotLoaded($field))
    @php
        $crud->markFieldTypeAsLoaded($field);
    @endphp

    @push('crud_fields_scripts')
        <script>
            function bpFieldInitSwitch(element) {
                var hidden = element.closest('[data-field-type=switch]').find('input[type=hidden]');

                // keep the checkbox in step with the hidden value (repeatable clones set the hidden input)
                element.prop('checked', hidden.val() != 0 && hidden.val() !== '');
                if (hidden.val() === '') hidden.val(0);

                element.on('change', function () {
                    hidden.val(element.is(':checked') ? 1 : 0).trigger('change');
                });
            }
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
