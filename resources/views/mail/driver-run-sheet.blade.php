{{-- The standard notification email, with the round's jobs in a table after the button. --}}
<x-mail::message>
# {{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

<x-mail::job-table :groups="$groups" />

@lang('Regards,')<br>
{{ config('app.name') }}

<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>
