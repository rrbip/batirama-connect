<x-mail::message>
# Nouvelle demande de support

Bonjour {{ $supportAgent->name }},

Une conversation nécessite votre attention sur **{{ $agentName }}**.

## Informations

- **Utilisateur** : {{ $userName }}
- **Raison** : {{ $escalationReason }}
- **Date** : {{ $session->escalated_at?->format('d/m/Y à H:i') }}

@if(count($lastMessages) > 0)
## Derniers messages

@foreach($lastMessages as $msg)
**{{ $msg['role'] === 'user' ? 'Utilisateur' : 'IA' }}** ({{ $msg['created_at'] }}) :
> {{ $msg['content'] }}

@endforeach
@endif

<x-mail::button :url="$takeOverUrl" color="primary">
Prendre en charge
</x-mail::button>

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
