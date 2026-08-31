@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ asset('icons/icon-192.png') }}" class="logo" alt="" width="32" height="32">
<span class="logo-text">{!! $slot !!}</span>
</a>
</td>
</tr>
