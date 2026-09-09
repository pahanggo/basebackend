{{-- tags: renders each tag as a badge. The value may be an array, a JSON string or a comma-separated string.

     Options:
       - limit     => 10    maximum badges shown; the rest collapse into "+N"
       - separator => ' '   text placed between badges
       - escaped   => true
       - prefix / suffix / wrapper --}}
@php
    $value = data_get($entry, $column['name']);
    $column['escaped'] = $column['escaped'] ?? true;
    $column['prefix'] = $column['prefix'] ?? '';
    $column['suffix'] = $column['suffix'] ?? '';
    $column['limit'] = (int) ($column['limit'] ?? 10);
    $column['separator'] = $column['separator'] ?? ' ';

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : explode(',', $value);
    }

    $tags = is_array($value) ? array_values(array_filter(array_map(
        fn ($tag) => is_scalar($tag) ? trim((string) $tag) : '',
        $value
    ), fn ($tag) => $tag !== '')) : [];

    $hidden = $column['limit'] > 0 ? max(0, count($tags) - $column['limit']) : 0;
    $shown = $hidden > 0 ? array_slice($tags, 0, $column['limit']) : $tags;
@endphp

<span>
    @if (count($tags))
        @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_start')
            {{ $column['prefix'] }}
            @foreach ($shown as $tag)
                <span class="badge badge-light">
                    @if ($column['escaped'])
                        {{ $tag }}
                    @else
                        {!! $tag !!}
                    @endif
                </span>@if (! $loop->last || $hidden > 0){{ $column['separator'] }}@endif
            @endforeach
            @if ($hidden > 0)
                <span class="badge badge-light" title="{{ implode(', ', array_slice($tags, $column['limit'])) }}">+{{ $hidden }}</span>
            @endif
            {{ $column['suffix'] }}
        @includeWhen(!empty($column['wrapper']), 'crud::columns.inc.wrapper_end')
    @else
        -
    @endif
</span>
