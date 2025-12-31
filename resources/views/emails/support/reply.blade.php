<x-mail::message>
# Réponse de {{ $agentName }}

Bonjour,

{{ $message->content }}

---

{{ $replyInstructions }}

Cordialement,<br>
{{ $agent->name }}<br>
{{ $agentName }}
</x-mail::message>
