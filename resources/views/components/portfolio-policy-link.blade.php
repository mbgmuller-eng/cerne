@props(['profile', 'policy' => null, 'acao' => null, 'expandir' => false, 'label', 'primary' => false, 'voltar' => null])

{{--
    Atalho da carteira para dentro do cliente: abre o perfil direto na tela de Seguros, já na apólice (e, com
    acao="editar", com o formulário de edição aberto). É um POST puro, como o "Abrir perfil" (ver ProfileSwitchController):
    a navegação não depende de JavaScript, e quem autoriza é a policy do perfil.
--}}
<form method="POST" action="{{ route('profile.switch', $profile) }}" class="inline">
    @csrf
    <input type="hidden" name="destino" value="seguros">
    @if ($policy)
        <input type="hidden" name="apolice" value="{{ $policy }}">
    @endif
    @if ($acao)
        <input type="hidden" name="acao" value="{{ $acao }}">
    @endif
    @if ($voltar)
        <input type="hidden" name="voltar" value="{{ $voltar }}">
    @endif
    @if ($expandir)
        <input type="hidden" name="expandir" value="1">
    @endif
    <button type="submit" {{ $attributes->class([$primary ? 'btn-secondary px-3 py-1.5 whitespace-nowrap' : 'btn-ghost px-2 py-1 text-xs whitespace-nowrap']) }}>{{ $label }}</button>
</form>
