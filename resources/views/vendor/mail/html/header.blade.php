@use('App\Support\Mail\MailBrand')
@props(['url'])
<tr>
<td class="header">
@if ($logo = MailBrand::logo())
<a href="{{ $url }}" style="display: inline-block;"><img src="{{ $logo }}" class="logo" alt="{{ MailBrand::name() }}"></a>
@else
{{-- A link cannot hold a table (the renderer moves the table out of it), so the badge and the name are a link each. --}}
<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="mark" width="36" height="36" align="center" valign="middle"><a href="{{ $url }}">{{ MailBrand::initial() }}</a></td>
<td class="wordmark"><a href="{{ $url }}">{{ MailBrand::name() }}</a></td>
</tr>
</table>
@endif
</td>
</tr>
