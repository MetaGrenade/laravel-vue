@use('App\Support\Mail\MailBrand')
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="MailBrand::url()">
{{ MailBrand::name() }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if ($note = MailBrand::footer())
{{ $note }}

@endif
© {{ date('Y') }} {{ MailBrand::name() }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
