{{-- "Registrado por Fulano": só dentro do perfil de uma pessoa acompanhada, onde vários irmãos mexem no mesmo cadastro e precisam saber quem fez o quê.
     Usa o usuário que gravou (HasActorUser); quem não tem esse dado (registro antigo) não mostra nada. --}}
@props(['model'])

@php($ctx = app(\App\Support\ProfileContext::class))

@if (($ctx->isCaregiver() || $ctx->profile()?->isCare()) && $model->createdByUser)
    <span class="text-xs text-slate-400">· registrado por {{ $model->createdByUser->name }}</span>
@endif
