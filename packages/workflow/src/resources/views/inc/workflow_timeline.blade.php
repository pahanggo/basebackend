{{--
    "Who did it / time spent / time recorded" audit trail for a HasWorkflow
    record — shared by the app-level crud/show.blade.php (any CrudController)
    and the workflow package's own show-workflow page
    (packages/workflow/src/resources/views/show.blade.php), so the two never
    drift apart. Guarded internally (Workflow\Support\WorkflowTimeline
    returns an empty array for a non-HasWorkflow $entry, or one with no
    workflow instance at all) so including this partial unconditionally from
    the shared show.blade.php is safe for every other CrudController's
    entries too — it simply renders nothing for them.

    Rendered as a vertical timeline (dot + connecting line, colored via the
    theme's --primary CSS var) rather than a plain table — meant to sit in a
    right-hand sidebar column alongside the main show content, see both
    show.blade.php callers. Only the 3 most recent steps (WorkflowTimeline::build()
    returns newest-first) render up front; anything older sits behind a
    "... N more" trigger that fetches the rest via AJAX
    (Workflow\Http\Controllers\WorkflowTimelineController) rather than
    padding out every show page with a record's full history.
--}}
@php
    $workflowTimeline = app(\Workflow\Support\WorkflowTimeline::class)->build($entry);
    $wfTimelineVisibleCount = 3;
    $wfTimelineVisible = array_slice($workflowTimeline, 0, $wfTimelineVisibleCount);
    $wfTimelineRemaining = count($workflowTimeline) - count($wfTimelineVisible);
@endphp

@if (! empty($workflowTimeline))
    <div class="card wf-timeline-card">
        <div class="card-header bg-white">
            <strong><i class="la la-history"></i> Workflow timeline</strong>
        </div>
        <div class="card-body">
            <div class="wf-timeline">
                @include('workflow::inc.workflow_timeline_items', ['items' => $wfTimelineVisible])
            </div>

            @if ($wfTimelineRemaining > 0)
                <a href="#" class="wf-timeline-more"
                   data-workflowable-type="{{ get_class($entry) }}"
                   data-workflowable-id="{{ $entry->getKey() }}"
                   data-offset="{{ $wfTimelineVisibleCount }}">
                    &hellip; {{ $wfTimelineRemaining }} more
                </a>
            @endif
        </div>
    </div>

    @once
        @push('after_styles')
            <style>
                .wf-timeline { position: relative; padding-left: 26px; }
                .wf-timeline-item { position: relative; padding-bottom: 22px; }
                .wf-timeline-item:last-child { padding-bottom: 0; }
                .wf-timeline-item::before {
                    content: '';
                    position: absolute;
                    left: -19px;
                    top: 18px;
                    bottom: -4px;
                    width: 2px;
                    background: #e4e7ea;
                }
                .wf-timeline-item:last-child::before { display: none; }
                .wf-timeline-dot {
                    position: absolute;
                    left: -26px;
                    top: 2px;
                    width: 14px;
                    height: 14px;
                    border-radius: 50%;
                    background: #fff;
                    border: 3px solid var(--primary);
                }
                .wf-timeline-time {
                    font-weight: 700;
                    font-size: .75rem;
                    letter-spacing: .02em;
                    text-transform: uppercase;
                    color: var(--primary);
                }
                .wf-timeline-title { font-weight: 600; font-size: .9rem; margin: 2px 0 3px; }
                .wf-timeline-meta { font-size: .8rem; color: #73818f; }
                .wf-timeline-inputs { font-size: .8rem; }
                .wf-timeline-inputs dt { font-weight: 600; color: #73818f; }
                .wf-timeline-inputs dd { margin-bottom: 4px; word-break: break-word; }
                .wf-timeline-more { display: inline-block; margin-top: 10px; font-size: .8rem; }
            </style>
        @endpush

        @push('after_scripts')
            <script>
                // Event-delegated (rather than bound per-link) so this
                // still works if the partial is ever included more than
                // one time on a page — matches the guarded style block
                // above, only ever registered a single time.
                document.addEventListener('click', function (e) {
                    const link = e.target.closest('.wf-timeline-more');
                    if (! link) return;

                    e.preventDefault();

                    const container = link.closest('.card-body').querySelector('.wf-timeline');
                    const params = new URLSearchParams({
                        workflowable_type: link.dataset.workflowableType,
                        workflowable_id: link.dataset.workflowableId,
                        offset: link.dataset.offset,
                    });

                    link.textContent = 'Loading…';

                    fetch('{{ route('workflow.timeline') }}?' + params.toString())
                        .then(response => response.text())
                        .then(html => {
                            container.insertAdjacentHTML('beforeend', html);
                            link.remove();
                        })
                        .catch(() => {
                            link.textContent = 'Failed to load — try again';
                        });
                });
            </script>
        @endpush
    @endonce
@endif
