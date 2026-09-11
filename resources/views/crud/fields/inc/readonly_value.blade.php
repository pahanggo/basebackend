{{-- readonly_value: shared plaintext display used by every field's `readonly` state.
     Renders the current value with no form control and no `name` attribute at all,
     so the field is entirely excluded from the submitted request.

     Usage: @include('crud::fields.inc.readonly_value', ['value' => $someDisplayString])
     Pass `raw' => true when $value is already-safe HTML (e.g. rendered wysiwyg content). --}}
<p class="form-control-plaintext readonly-field-value">@if($raw ?? false){!! $value !!}@else{{ $value }}@endif</p>
