<form method="POST" action="{{ route('profile.switch', $profileId) }}" class="shrink-0">
    @csrf
    <input type="hidden" name="destino" value="acompanhamento">
    <button type="submit" class="{{ $classe }}">{{ $rotulo }}</button>
</form>
