{{-- identity: a Malaysian MyKad number or passport number.

     Options:
       - mask           => false   show MyKad numbers as ******-**-#### (last four digits only)
       - type_attribute => null    model attribute holding 'mykad' | 'passport'; when set a small
                                   badge with the type is shown next to the number
       - prefix / suffix / escaped / wrapper as for the text column

     Without type_attribute the type is detected from the value (12 digits with or without dashes = MyKad). --}}
@php
    $value = data_get($entry, $column['name']);
    $value = is_scalar($value) ? trim((string) $value) : '';
    $column['escaped'] = $column['escaped'] ?? true;
    $column['prefix'] = $column['prefix'] ?? '';
    $column['suffix'] = $column['suffix'] ?? '';
    $column['mask'] = $column['mask'] ?? false;
    $column['type_attribute'] = $column['type_attribute'] ?? null;
    $column['text'] = '';

    $typeLabels = ['mykad' => __('MyKad'), 'passport' => __('Passport')];
    $type = $column['type_attribute'] ? data_get($entry, $column['type_attribute']) : null;
    $type = $type instanceof \BackedEnum ? $type->value : $type;
    if (! is_string($type) || ! isset($typeLabels[$type])) {
        $type = preg_match('/^\d{6}-?\d{2}-?\d{4}$/', $value) ? 'mykad' : 'passport';
    }

    if ($value !== '') {
        $text = $value;
        if ($type === 'mykad' && preg_match('/^\d{6}-?\d{2}-?\d{4}$/', $value)) {
            $digits = preg_replace('/\D/', '', $value);
            $text = $column['mask']
                ? '******-**-'.substr($digits, 8)
                : substr($digits, 0, 6).'-'.substr($digits, 6, 2).'-'.substr($digits, 8);
        }
        $column['text'] = $column['prefix'].$text.$column['suffix'];
    }
@endphp

<span>
    @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_start')
        @if($column['escaped'])
            {{ $column['text'] }}
        @else
            {!! $column['text'] !!}
        @endif
    @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_end')
    @if($column['type_attribute'] && $value !== '')
        <span class="badge badge-light ml-1">{{ $typeLabels[$type] }}</span>
    @endif
</span>
