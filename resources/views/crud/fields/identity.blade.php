{{-- identity: a Malaysian MyKad number or passport number with a type selector.

     Options:
       - types        => ['mykad', 'passport']   which types the user may pick: ['ic'] / ['mykad'] for MyKad only,
                                                 ['passport'] for passports only, both for a selector ('ic' = 'mykad')
       - name         => 'identity_number' or ['identity_number', 'identity_type']; the array form declares the
                                                 type column as part of the field so Backpack saves it (preferred)
       - type_field   => null                    legacy: name of the type input; NOT saved by Backpack unless it is
                                                 also a declared field, so prefer the array 'name' form
                                                 ('mykad' | 'passport'); when null the type is not submitted
       - default_type => 'mykad'                 type pre-selected when nothing else decides it
       - mask         => true                    format MyKad as 000000-00-0000 while typing
       - hint / attributes as for the text field

     The number is submitted in $field['name']: MyKad WITH dashes (YYMMDD-PB-####), passport as
     uppercase alphanumerics (6-12 chars). When a value is loaded, the type is detected from the value
     (12 digits with or without dashes = MyKad) unless the type field already holds a type.
     MyKad numbers are checked client-side (real YYMMDD date, known place-of-birth code); an invalid
     number shows an inline warning without blocking the submit. --}}
@php
    $allTypes = ['mykad' => __('MyKad'), 'passport' => __('Passport')];
    // 'ic' is accepted as an alias of 'mykad' in both `types` and `default_type`
    $aliasType = fn (string $type) => $type === 'ic' ? 'mykad' : $type;
    $field['default_type'] = isset($field['default_type']) ? $aliasType($field['default_type']) : null;
    $types = array_values(array_unique(array_intersect(array_map($aliasType, (array) ($field['types'] ?? array_keys($allTypes))), array_keys($allTypes))));
    if (empty($types)) {
        $types = array_keys($allTypes);
    }
    // 'name' => ['identity_number', 'identity_type'] declares the type input as part of this field, which is
    // what makes Backpack keep it in the save request (a bare 'type_field' is stripped on save).
    $names = array_values((array) $field['name']);
    $field['name'] = $names[0];
    $typeField = $names[1] ?? $field['type_field'] ?? null;
    $defaultType = in_array($field['default_type'] ?? 'mykad', $types, true) ? ($field['default_type'] ?? 'mykad') : $types[0];
    $mask = (bool) ($field['mask'] ?? true);
    $selectDisabled = array_key_exists('disabled', $field['attributes'] ?? []);
    $selectReadonly = ! $selectDisabled && array_key_exists('readonly', $field['attributes'] ?? []);

    // with an array 'name' Backpack's own value lookup only yields the last column, so read both
    // columns from the entry directly (same approach as the date_range field)
    $storedType = null;
    if (count($names) > 1) {
        $field['value'] = isset($entry) ? data_get($entry, $names[0]) : null;
        $storedType = isset($entry) ? data_get($entry, $names[1]) : null;
    } elseif (isset($field['value']) && is_array($field['value'])) {
        $field['value'] = $field['value'][0] ?? null;
    }
    $value = old(square_brackets_to_dots($field['name'])) ?? $field['value'] ?? $field['default'] ?? '';
    $value = is_scalar($value) ? (string) $value : '';

    $typeValue = null;
    if ($typeField) {
        $typeValue = old(square_brackets_to_dots($typeField)) ?? $storedType ?? (isset($entry) ? data_get($entry, $typeField) : null);
        $typeValue = $typeValue instanceof \BackedEnum ? $typeValue->value : $typeValue;
    }
    if (! in_array($typeValue, $types, true)) {
        $typeValue = null;
        if ($value !== '') {
            $detected = preg_match('/^\d{6}-?\d{2}-?\d{4}$/', $value) ? 'mykad' : 'passport';
            $typeValue = in_array($detected, $types, true) ? $detected : null;
        }
    }
    // a stored type that contradicts the number (e.g. MyKad with a passport number) must not win,
    // otherwise the MyKad mask would destroy the value on the next keystroke
    if ($typeValue === 'mykad' && $value !== '' && ! preg_match('/^\d{6}-?\d{2}-?\d{4}$/', $value) && in_array('passport', $types, true)) {
        $typeValue = 'passport';
    }
    $typeValue = $typeValue ?? $defaultType;

    $field['wrapper'] = $field['wrapper'] ?? $field['wrapperAttributes'] ?? [];
    $field['wrapper']['data-field-type'] = 'identity';
    $field['wrapper']['data-field-name'] = $field['name'];
@endphp

@include('crud::fields.inc.wrapper_start')
    <label>{!! $field['label'] !!}</label>
    @include('crud::fields.inc.translatable_icon')

    <div class="input-group">
        <div class="input-group-prepend identity-type-wrapper {{ count($types) > 1 ? '' : 'd-none' }}">
            <select class="custom-select identity-type"
                    @if ($typeField) name="{{ $typeField }}" @endif
                    @if ($selectDisabled) disabled @endif
                    @if ($selectReadonly) tabindex="-1" style="pointer-events: none; background-color: #e9ecef;" @endif
                    aria-label="{{ __('Identity type') }}">
                @foreach ($types as $type)
                    <option value="{{ $type }}" @if ($type === $typeValue) selected @endif>{{ $allTypes[$type] }}</option>
                @endforeach
            </select>
        </div>
        <input
            type="text"
            name="{{ $field['name'] }}"
            value="{{ $value }}"
            data-init-function="bpFieldInitIdentityElement"
            data-types="{{ implode(',', $types) }}"
            data-default-type="{{ $defaultType }}"
            data-mask="{{ $mask ? 1 : 0 }}"
            autocomplete="off"
            @include('crud::fields.inc.attributes')
        >
    </div>
    <small class="identity-feedback form-text text-danger"
           data-mykad="{{ __('Invalid MyKad number') }}"
           data-passport="{{ __('Invalid passport number') }}"
           hidden></small>

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
            (function () {
                /**
                 * Place-of-birth codes (positions 7-8) issued by JPN. Accepts 01-16, 21-68, 71-72,
                 * 74-79 and 82-99; rejects 00, 17-20, 69-70, 73 and 80-81.
                 */
                function bpIdentityValidPlaceCode(code) {
                    var n = Number(code);
                    return (n >= 1 && n <= 16) || (n >= 21 && n <= 68) || n === 71 || n === 72
                        || (n >= 74 && n <= 79) || (n >= 82 && n <= 99);
                }

                function bpIdentityValidDate(yy, mm, dd) {
                    var month = Number(mm), day = Number(dd), year = Number(yy);
                    if (month < 1 || month > 12 || day < 1) return false;
                    var daysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                    // the century is unknown, so February 29 is fine if either candidate year is a leap year
                    var leap = [1900 + year, 2000 + year].some(function (y) { return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0; });
                    return day <= daysInMonth[month - 1] + (month === 2 && leap ? 1 : 0);
                }

                window.bpIdentityFormatMykad = function (raw) {
                    var digits = String(raw || '').replace(/\D/g, '').slice(0, 12);
                    var out = digits.slice(0, 6);
                    if (digits.length > 6) out += '-' + digits.slice(6, 8);
                    if (digits.length > 8) out += '-' + digits.slice(8);
                    return out;
                };

                window.bpIdentityIsValidMykad = function (value) {
                    var digits = String(value || '').replace(/\D/g, '');
                    return digits.length === 12
                        && bpIdentityValidDate(digits.slice(0, 2), digits.slice(2, 4), digits.slice(4, 6))
                        && bpIdentityValidPlaceCode(digits.slice(6, 8));
                };

                window.bpIdentityIsValidPassport = function (value) {
                    return /^[A-Z0-9]{6,12}$/.test(String(value || ''));
                };

                window.bpIdentityDetectType = function (value) {
                    return /^\d{6}-?\d{2}-?\d{4}$/.test(String(value || '').trim()) ? 'mykad' : 'passport';
                };

                window.bpFieldInitIdentityElement = function (element) {
                    var container = element.closest('[data-field-type=identity]');
                    var select = container.find('select.identity-type');
                    var feedback = container.find('.identity-feedback');
                    var types = String(element.data('types') || 'mykad,passport').split(',');
                    var mask = String(element.data('mask')) !== '0';

                    function currentType() {
                        var type = select.val();
                        return types.indexOf(type) > -1 ? type : types[0];
                    }

                    function clean(type, raw) {
                        if (type === 'mykad') {
                            return mask ? bpIdentityFormatMykad(raw) : String(raw || '').replace(/[^\d-]/g, '').slice(0, 14);
                        }
                        return String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
                    }

                    function validate() {
                        var type = currentType(), value = element.val();
                        var valid = value === ''
                            || (type === 'mykad' ? bpIdentityIsValidMykad(value) : bpIdentityIsValidPassport(value));
                        feedback.text(feedback.data(type) || '').prop('hidden', valid);
                        element.toggleClass('is-invalid', ! valid);
                    }

                    function applyType() {
                        var type = currentType();
                        element.attr('placeholder', type === 'mykad' ? '900101-06-5000' : 'A12345678')
                            .attr('inputmode', type === 'mykad' ? 'numeric' : 'text');
                        element.val(clean(type, element.val()));
                        validate();
                    }

                    // a loaded value decides the type unless the type input already holds one.
                    // Repeatable clones set both inputs before this runs, but only when the select
                    // is submitted (type_field set); an unnamed select still shows the server default,
                    // so the value has to decide there too.
                    var value = element.val();
                    var typeIsSubmitted = !! (select.attr('name') || select.attr('data-repeatable-input-name'));
                    var contradicts = select.val() === 'mykad' && value !== '' && ! /^\d{6}-?\d{2}-?\d{4}$/.test(value);
                    if (value !== '' && (! typeIsSubmitted || types.indexOf(select.val()) === -1 || contradicts)) {
                        var detected = bpIdentityDetectType(value);
                        select.val(types.indexOf(detected) > -1 ? detected : element.data('default-type'));
                    } else if (types.indexOf(select.val()) === -1) {
                        select.val(element.data('default-type'));
                    }

                    // only react to a real type switch; repeatable re-triggers "change" on every input
                    // when a row is moved and that must not wipe the number
                    var lastType = currentType();
                    select.on('change', function () {
                        var type = currentType();
                        if (type === lastType) {
                            return;
                        }
                        lastType = type;
                        element.val('');
                        applyType();
                        element.trigger('change');
                    });

                    element.on('input', function () {
                        var raw = element.val();
                        var cleaned = clean(currentType(), raw);
                        if (cleaned !== raw) {
                            // keep the caret after the same number of significant characters
                            var caret = element[0].selectionStart;
                            var significant = typeof caret === 'number' ? clean(currentType(), raw.slice(0, caret)).replace(/-/g, '').length : null;
                            element.val(cleaned);
                            if (significant !== null && document.activeElement === element[0]) {
                                var pos = 0, seen = 0;
                                while (pos < cleaned.length && seen < significant) {
                                    if (cleaned.charAt(pos) !== '-') seen++;
                                    pos++;
                                }
                                try { element[0].setSelectionRange(pos, pos); } catch (e) {}
                            }
                        }
                        validate();
                    });

                    element.on('blur change', validate);

                    applyType();
                };
            })();
        </script>
    @endpush
@endif
{{-- End of Extra CSS and JS --}}
{{-- ########################################## --}}
