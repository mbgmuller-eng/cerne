<div class="mx-auto max-w-3xl space-y-6" data-assistente>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Pergunte ao Cerne</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pergunte sobre as finanças deste perfil, como “quanto gastei com delivery este mês?”.</p>
        </div>
        @if (count($conversa) > 0)
            <button type="button" wire:click="limpar" class="btn-secondary px-3 py-1.5 text-xs" data-assistente-limpar>Nova conversa</button>
        @endif
    </div>

    <div class="space-y-3" data-assistente-conversa>
        @forelse ($conversa as $m)
            <div @class(['flex', 'justify-end' => $m['role'] === 'user'])>
                <div @class([
                    'max-w-[85%] whitespace-pre-line rounded-2xl px-4 py-3 text-sm',
                    'bg-brand-600 text-white' => $m['role'] === 'user',
                    'bg-white text-slate-800 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-white/10' => $m['role'] !== 'user',
                ]) data-mensagem="{{ $m['role'] }}">{{ $m['text'] }}</div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-8 text-sm text-slate-500 dark:border-slate-600 dark:bg-slate-800/40 dark:text-slate-400">
                <p class="font-medium text-slate-700 dark:text-slate-300">Exemplos</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Quanto gastei com mercado este mês?</li>
                    <li>Qual o saldo das minhas contas?</li>
                    <li>O que vence nos próximos dias?</li>
                    <li>Como estão os meus objetivos?</li>
                </ul>
            </div>
        @endforelse

        <div wire:loading wire:target="enviar" class="text-sm text-slate-400" data-assistente-pensando>Consultando os seus dados...</div>
    </div>

    @if ($erro)
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20" data-assistente-erro>{{ $erro }}</p>
    @endif

    <form wire:submit="enviar" class="space-y-2">
        <div class="flex gap-2">
            <input type="text" wire:model="pergunta" maxlength="500" autocomplete="off" placeholder="Escreva a sua pergunta" aria-label="Pergunta" data-assistente-campo
                   class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-slate-800 dark:text-white">
            <button type="submit" class="btn-primary shrink-0 px-5" wire:loading.attr="disabled" wire:target="enviar">Enviar</button>
        </div>
        @error('pergunta')
            <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
        @enderror
        <p class="text-xs text-slate-400">O assistente só consulta, não lança nem altera nada, e vê o mesmo que você vê. A conversa não fica gravada. Ainda pode errar: confira os valores nas telas. Perguntas restantes hoje: {{ $restantes }}.</p>
    </form>
</div>
