@props(['groups'])
{{-- The run sheet's jobs (DriverRunSheet::groups()) for the plain-text part of the email. --}}
@foreach ($groups as $group)
{{ $group['title'] }}
{{ $group['parcels'] }}{{ $group['address'] ? ' · '.$group['address'] : '' }}
@foreach ($group['jobs'] as $job)
- {{ $job['tracking_number'] }}: {{ $job['area'] }}, {{ $job['status'] }}{{ $job['overdue'] ? ' ('.$job['overdue'].')' : '' }}
@endforeach

@endforeach
