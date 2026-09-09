{{-- "view" column: renders any blade with $entry and $column available --}}
<span class="badge badge-secondary">{{ $entry->status ?? '-' }}</span>
<small class="text-muted">{{ $entry->created_at?->diffForHumans() }}</small>
