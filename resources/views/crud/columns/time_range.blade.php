{{-- time_range: renders a start and an end time as "09:00 – 17:30".

     Options:
       - name      => ['opens_from', 'opens_to']   or a single attribute name plus 'end_name'
       - end_name  => null                         end attribute when 'name' is a string
       - format    => 'H:i'                        PHP date format for each time
       - separator => ' – '                        text between the two times (en dash)
       - prefix / suffix / escaped / wrapper as for other columns

     Accepts 'H:i' / 'H:i:s' strings and Carbon/DateTime instances; shows '-' when both are empty. --}}
@php
    $names = is_array($column['name']) ? array_values($column['name']) : [$column['name'], $column['end_name'] ?? $column['name'].'_end'];
    $column['escaped'] = $column['escaped'] ?? true;
    $column['prefix'] = $column['prefix'] ?? '';
    $column['suffix'] = $column['suffix'] ?? '';
    $column['format'] = $column['format'] ?? 'H:i';
    $column['separator'] = $column['separator'] ?? ' – ';
    $column['text'] = '';

    $formatTimeRangeValue = static function ($value) use ($column): string {
        if ($value === null || $value === '') {
            return '';
        }

        if (! $value instanceof \DateTimeInterface) {
            try {
                $value = \Carbon\Carbon::parse((string) $value);
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return $value->format($column['format']);
    };

    $times = array_map(static fn (string $name): string => $formatTimeRangeValue(data_get($entry, $name)), $names);

    $presentTimes = array_filter($times, static fn (string $time): bool => $time !== '');

    if ($presentTimes !== []) {
        $column['text'] = $column['prefix'].implode($column['separator'], $presentTimes).$column['suffix'];
    }
@endphp

<span data-order="{{ $times[0] ?? '' }}">
    @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_start')
        @if($column['text'] === '')
            -
        @elseif($column['escaped'])
            {{ $column['text'] }}
        @else
            {!! $column['text'] !!}
        @endif
    @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_end')
</span>
