{{-- slug: a text input that is filled from another field as the user types.

     Options:
       - target    => 'title'   name of the field to slugify (required)
       - separator => '-'
       - readonly  => false     when true, show the value as plain text with no form control and no name attribute (not submitted)
       - hint / prefix / suffix / attributes as for the text field

     Typing into the slug itself stops the automatic sync; clearing it resumes the sync. --}}
@php
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $readonly = (bool) ($field['readonly'] ?? false);

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'slug';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    @if ($readonly)
        @include('crud::fields.inc.readonly_value', ['value' => $value])
    @else
        @if(isset($field['prefix']) || isset($field['suffix'])) <div class="input-group"> @endif
            @if(isset($field['prefix'])) <div class="input-group-prepend"><span class="input-group-text">{!! $field['prefix'] !!}</span></div> @endif
            <input
                type="text"
                name="{{ $field['name'] }}"
                value="{{ $value }}"
                data-init-function="bpFieldInitSlugElement"
                data-target="{{ $field['target'] }}"
                data-separator="{{ $field['separator'] ?? '-' }}"
                @include('crud::fields.inc.attributes')
            >
            @if(isset($field['suffix'])) <div class="input-group-append"><span class="input-group-text">{!! $field['suffix'] !!}</span></div> @endif
        @if(isset($field['prefix']) || isset($field['suffix'])) </div> @endif
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
            function bpSlugify(text, separator) {
                return String(text || '')
                    .normalize('NFD').replace(/[̀-ͯ]/g, '')   // strip accents
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, separator)                   // anything else becomes the separator
                    .replace(new RegExp('^' + separator + '+|' + separator + '+$', 'g'), '');
            }

            function bpFieldInitSlugElement(element) {
                var separator = element.data('separator') || '-';
                var targetName = element.data('target');

                // look for the target in the same repeatable group first, then in the whole form
                var $scope = element.closest('.repeatable-element');
                var $target = ($scope.length ? $scope : element.closest('form'))
                    .find('[name="' + targetName + '"], [data-repeatable-input-name="' + targetName + '"]').first();

                if (! $target.length) {
                    return;
                }

                // keep syncing while the slug is empty or still equal to the slug of the target
                var synced = element.val() === '' || element.val() === bpSlugify($target.val(), separator);

                $target.on('input keyup change', function () {
                    if (synced) {
                        element.val(bpSlugify($target.val(), separator)).trigger('change');
                    }
                });

                element.on('input', function () {
                    synced = element.val() === '';
                });

                // tidy up whatever the user typed by hand when they leave the field
                element.on('blur', function () {
                    if (element.val() !== '') {
                        element.val(bpSlugify(element.val(), separator)).trigger('change');
                    }
                });
            }
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
