<x-mail::message>
    # Verify your email, {{ $name }}

    To finish signing in to **{{ $appName }}**, please confirm your email address by clicking the button below.

    <x-mail::button :url="$verifyUrl">
        Verify email
    </x-mail::button>

    This link expires in 60 minutes. If you didn't request this, you can safely ignore the email.

    Thanks,
    The {{ $appName }} team
</x-mail::message>
