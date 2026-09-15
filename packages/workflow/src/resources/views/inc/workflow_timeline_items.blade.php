{{--
    The actual `.wf-timeline-item` rows — split out of workflow_timeline.blade.php
    so both the initial page render (first 3 steps) and the "load the rest"
    AJAX response (Workflow\Http\Controllers\WorkflowTimelineController,
    appended into the same `.wf-timeline` container) share one markup source
    instead of drifting apart. Takes `$items` — a list in WorkflowTimeline::build()'s
    per-step shape.
--}}
@foreach ($items as $step)
    <div class="wf-timeline-item">
        <span class="wf-timeline-dot"></span>
        <div class="wf-timeline-time">{{ $step['recorded_at']->format('d M Y, H:i') }}</div>
        <div class="wf-timeline-title">
            @if ($step['is_edit'] ?? false)
                {{ $step['to_label'] }}
            @else
                {{ $step['from_label'] }} &rarr; {{ $step['to_label'] }}
            @endif
        </div>
        <div class="wf-timeline-meta">{{ $step['edge_label'] }} &middot; {{ $step['trigger'] }}</div>
        <div class="wf-timeline-meta">
            <i class="la la-user"></i> {{ $step['actor'] }}
            @unless ($step['is_edit'] ?? false)
                &nbsp;&middot;&nbsp;
                <i class="la la-clock-o"></i> {{ $step['time_spent'] }}
            @endunless
        </div>
        @if (! empty($step['inputs']))
            <dl class="wf-timeline-inputs mb-0 mt-1">
                @foreach ($step['inputs'] as $input)
                    <dt>{{ $input['label'] }}</dt>
                    <dd>{{ is_scalar($input['value']) || is_null($input['value']) ? $input['value'] : json_encode($input['value']) }}</dd>
                @endforeach
            </dl>
        @endif
    </div>
@endforeach
