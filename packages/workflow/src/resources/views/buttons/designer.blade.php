{{--
    Opens the standalone canvas/inspector editor for this workflow
    definition — a real action-column button (the 'line' stack), alongside
    show/edit/delete, rather than its own dedicated list column. Registered
    in WorkflowDefinitionCrudController::setupListOperation() (also used for
    show, via that operation's own setupShowOperation()).
--}}
<a href="{{ route('workflow.designer.edit', $entry) }}" class="btn btn-sm btn-link" data-toggle="tooltip" title="Design">
    <i class="la la-project-diagram"></i><span class="sr-only">Design</span>
</a>
