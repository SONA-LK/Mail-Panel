{!! $headline !!}

@foreach($facts as $label => $value)
{!! str_pad($label.':', 9) !!} {!! $value !!}
@endforeach

If this was you, no action is needed.
If it was not you: sign in, open Security, sign out other devices and make new recovery codes, then ask a super admin to review the audit log:
{!! $auditUrl !!}
