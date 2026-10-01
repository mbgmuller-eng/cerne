<?php
    // Vem do botão específico de cada área na landing (?papel=consultant
    // ou ?papel=broker) — já entra marcado como profissional, na
    // especialidade certa, sem a pessoa precisar escolher de novo.
    $papelPreSelecionado = in_array(request('papel'), ['consultant', 'broker'], true) ? request('papel') : null;
    $tipoPadrao = old('tipo_conta', $papelPreSelecionado ? 'profissional' : 'cliente');
    $papelPadrao = old('papel', $papelPreSelecionado);
?>
<x-layouts.guest title="Criar conta · Cerne">
    <div x-data="{ tipo: '{{ $tipoPadrao }}' }">
        <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Criar conta</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Sem convite: sua conta, sua assinatura.</p>

        <form method="POST" action="{{ route('register.store') }}" class="mt-8 space-y-5">
            @csrf

            <div class="grid grid-cols-2 gap-2">
                <label @class(['cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-medium', 'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-200' => $tipoPadrao === 'cliente', 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-400' => $tipoPadrao !== 'cliente'])>
                    <input type="radio" name="tipo_conta" value="cliente" x-model="tipo" class="sr-only">
                    Sou cliente
                </label>
                <label @class(['cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-medium', 'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-200' => $tipoPadrao === 'profissional', 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-400' => $tipoPadrao !== 'profissional'])>
                    <input type="radio" name="tipo_conta" value="profissional" x-model="tipo" class="sr-only">
                    Sou profissional
                </label>
            </div>
            @error('tipo_conta')
                <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div x-show="tipo === 'profissional'" x-cloak>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Você é</label>
                <select name="papel" class="input mt-1.5">
                    <option value="consultant" @selected($papelPadrao === 'consultant')>Consultor financeiro</option>
                    <option value="broker" @selected($papelPadrao === 'broker')>Corretor de seguros</option>
                </select>
                @error('papel')
                    <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="nome" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Nome</label>
                <input id="nome" name="nome" type="text" value="{{ old('nome') }}" autocomplete="name" required autofocus class="input mt-1.5">
                @error('nome')
                    <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required class="input mt-1.5">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required class="input mt-1.5">
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mínimo de 8 caracteres, com letras e números.</p>
                @error('password')
                    <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Confirme a senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input mt-1.5">
            </div>

            <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-400">
                <input name="termos" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                <span>Li e aceito os <a href="{{ route('legal.terms') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Termos de Uso</a>.</span>
            </label>
            @error('termos')
                <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn-primary w-full py-2.5">Criar minha conta</button>
        </form>

        <p class="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
            Já tem conta? <a href="{{ route('login') }}" class="text-brand-800 underline dark:text-brand-300">Entrar</a>
        </p>
    </div>
</x-layouts.guest>
