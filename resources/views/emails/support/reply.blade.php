<x-mail::message>
# Réponse de {{ $brandName }}

Bonjour,

{{ $message->content }}

---

@if($chatUrl)
<x-mail::button :url="$chatUrl" color="primary">
Accéder au chat en direct
</x-mail::button>

Vous pouvez également suivre votre conversation en temps réel sur notre chat.

@endif
{{ $replyInstructions }}

Cordialement,<br>
{{ $agent->name }}<br>
{{ $brandName }}

@if($footerText)
<x-mail::subcopy>
{{ $footerText }}
</x-mail::subcopy>
@endif
</x-mail::message>
