<x-mail::message>
# Welcome to {{ $appName }}, {{ $name }}!

An administrator created an account for you on **{{ $appName }}**. Use the credentials below to sign in.

**Email:** {{ $email }}
@if ($plainPassword)
**Temporary password:** `{{ $plainPassword }}`

You'll be asked to set a new password on your first login.
@endif

<x-mail::button :url="$loginUrl">
Sign in to {{ $appName }}
</x-mail::button>

@if ($resetUrl)
If you'd rather set a password without using the temporary one, use this link instead:

<x-mail::button :url="$resetUrl" color="secondary">
Set my password
</x-mail::button>
@endif

If you weren't expecting this email, you can safely ignore it — no further action is needed.

Thanks,
The {{ $appName }} team
</x-mail::message>
