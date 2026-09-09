{{-- money: a number formatted as currency, right-aligned; null renders "-".

     Options:
       - prefix        => 'RM '
       - suffix        => ''
       - decimals      => 2
       - thousands_sep => ','
       - dec_point     => '.'
       - escaped       => true
       - wrapper       => [] (element defaults to span; the text-right class is always added) --}}
@php
    $value = data_get($entry, $column['name']);
    $column['escaped'] = $column['escaped'] ?? true;
    $column['prefix'] = $column['prefix'] ?? 'RM ';
    $column['suffix'] = $column['suffix'] ?? '';
    $column['decimals'] = $column['decimals'] ?? 2;
    $column['dec_point'] = $column['dec_point'] ?? '.';
    $column['thousands_sep'] = $column['thousands_sep'] ?? ',';
    $column['wrapper'] = $column['wrapper'] ?? [];
    $column['wrapper']['element'] = $column['wrapper']['element'] ?? 'span';
    $column['wrapper']['class'] = trim(($column['wrapper']['class'] ?? '').' d-block text-right text-nowrap');
    $column['text'] = '-';

    if (! is_null($value) && $value !== '' && is_numeric($value)) {
        $column['text'] = $column['prefix'].number_format((float) $value, $column['decimals'], $column['dec_point'], $column['thousands_sep']).$column['suffix'];
    }
@endphp

<span>
    @include('crud::columns.inc.wrapper_start')
        @if($column['escaped'])
            {{ $column['text'] }}
        @else
            {!! $column['text'] !!}
        @endif
    @include('crud::columns.inc.wrapper_end')
</span>
