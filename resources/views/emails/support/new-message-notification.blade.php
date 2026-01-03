<x-mail::message>
# Nouveau message support

Bonjour,

Un nouveau message a été reçu sur une session **{{ $agentName }}** :

**De :** {{ $senderName }}

<x-mail::panel>
{{ \Illuminate\Support\Str::limit($messagePreview, 200) }}
</x-mail::panel>

<x-mail::button :url="$backofficeUrl" color="primary">
Voir la conversation
</x-mail::button>

---

*Vous recevez cet email car vous êtes agent support pour {{ $agentName }} et n'étiez pas connecté au backoffice.*

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
