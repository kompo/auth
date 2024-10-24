@component('mail::message')

<p>{{ __('messaging.email-welcome').' '.$userName.',' }}</p>

<p>{{ __('translate.with-values.this-team-do-the-following-changes', [
    'teamName' => $teamName,
    'changes' => $changes
]) }}</p>

@endcomponent
