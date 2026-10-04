@props(['groups'])
{{-- The run sheet's jobs (DriverRunSheet::groups()) in one table, so every group shares its columns; styled in themes/kotak.css. It sits inside Markdown as an HTML block, which a blank line would end. --}}
<table class="jobs" width="100%" cellpadding="0" cellspacing="0">
<thead>
<tr class="jobs-head">
<th class="jobs-tracking" scope="col">Tracking no.</th>
<th scope="col">Area</th>
</tr>
</thead>
@foreach ($groups as $group)
<tbody>
<tr class="jobs-group{{ $loop->first ? ' jobs-group-first' : '' }}">
<th colspan="2" scope="rowgroup">{{ $group['title'] }}<br><span class="jobs-address">{{ $group['parcels'] }}@if ($group['address']) · {{ $group['address'] }}@endif</span></th>
</tr>
@foreach ($group['jobs'] as $job)
<tr>
<td class="jobs-tracking">{{ $job['tracking_number'] }}</td>
<td class="jobs-area">{{ $job['area'] }}<br><span class="jobs-status">{{ $job['status'] }}</span>@if ($job['overdue'])<br><span class="jobs-overdue">{{ $job['overdue'] }}</span>@endif</td>
</tr>
@endforeach
</tbody>
@endforeach
</table>
