<x-mail::message>
# Votre demande a bien été reçue

{{ $userName }},

Nous avons bien reçu votre demande d'assistance. Un conseiller de **{{ $agentName }}** vous répondra dans les plus brefs délais.

## Que se passe-t-il ensuite ?

Notre équipe de support va examiner votre demande et vous répondre directement par email à cette adresse.

Si vous avez des informations complémentaires à nous communiquer, vous pouvez simplement répondre à cet email.

---

Cordialement,<br>
L'équipe **{{ $agentName }}**

<x-mail::subcopy>
Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet email.
</x-mail::subcopy>
</x-mail::message>
