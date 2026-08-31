<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" class="btn-ghost relative" title="Notificações" @click="open = !open">
        <x-nav-icon name="bell" class="h-4 w-4" />
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-transition
        x-cloak
        class="absolute right-0 z-30 mt-2 w-80 max-w-[90vw] rounded-xl border border-slate-200 bg-white shadow-lg dark:border-white/10 dark:bg-slate-800"
    >
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5 dark:border-white/10">
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Notificações</p>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="text-xs text-brand-700 hover:underline dark:text-brand-300">
                    Marcar tudo como lido
                </button>
            @endif
        </div>

        @if ($notifications->isEmpty())
            <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                Nenhuma notificação ainda.
            </p>
        @else
            <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-white/10">
                @foreach ($notifications as $n)
                    <li
                        wire:click="markAsRead('{{ $n->id }}')"
                        @class([
                            'cursor-pointer px-4 py-2.5 hover:bg-slate-50 dark:hover:bg-white/5',
                            'bg-brand-50/60 dark:bg-brand-500/10' => $n->read_at === null,
                        ])
                    >
                        <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $n->data['title'] ?? 'Notificação' }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
