@use('App\Enums\DocumentCategory')

<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Documentos</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                CNH, passaporte, certificados, apólices e exames, em pastas do seu jeito.
            </p>
        </div>
        @if ($podeGerenciar)
            <button type="button" wire:click="newDocument" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Novo documento</button>
        @endif
    </div>

    {{-- Caminho até a pasta aberta, subpastas e atalho para listar tudo. --}}
    @if ($temPastas || $podeGerenciar)
        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <nav aria-label="Caminho das pastas" class="flex min-w-0 flex-wrap items-center gap-1 text-sm">
                    @if ($verTodos)
                        <span class="font-medium text-slate-800 dark:text-slate-200">Todos os documentos</span>
                    @else
                        <button type="button" wire:click="abrirPasta('')" @class([
                            'rounded px-1.5 py-0.5 hover:bg-slate-100 dark:hover:bg-white/10',
                            'font-medium text-slate-800 dark:text-slate-200' => $pastaAtual === null,
                            'text-documentos-800 dark:text-documentos-200' => $pastaAtual !== null,
                        ])>Documentos</button>
                        @foreach ($caminho as $nivel)
                            <span class="text-slate-400" aria-hidden="true">›</span>
                            <button type="button" wire:click="abrirPasta('{{ $nivel->id }}')" wire:key="caminho-{{ $nivel->id }}" @class([
                                'rounded px-1.5 py-0.5 hover:bg-slate-100 dark:hover:bg-white/10',
                                'font-medium text-slate-800 dark:text-slate-200' => $loop->last,
                                'text-documentos-800 dark:text-documentos-200' => ! $loop->last,
                            ])>{{ $nivel->name }}</button>
                        @endforeach
                    @endif
                </nav>

                <div class="flex shrink-0 items-center gap-3 text-xs">
                    @if ($temPastas)
                        <button type="button" wire:click="alternarVerTodos" class="text-slate-500 hover:underline dark:text-slate-400">
                            {{ $verTodos ? 'Voltar às pastas' : 'Ver todos os documentos' }}
                        </button>
                    @endif
                    @if ($podeGerenciar && ! $verTodos)
                        @if ($pastaAtual)
                            <button type="button" wire:click="editFolder('{{ $pastaAtual->id }}')" class="text-slate-500 hover:underline dark:text-slate-400">Editar pasta</button>
                        @endif
                        <button type="button" wire:click="newFolder" class="font-medium text-documentos-800 hover:underline dark:text-documentos-200">+ Nova pasta</button>
                    @endif
                </div>
            </div>

            @if (! $verTodos && $subpastas->isNotEmpty())
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($subpastas as $item)
                        <li wire:key="pasta-{{ $item['pasta']->id }}">
                            <button type="button" wire:click="abrirPasta('{{ $item['pasta']->id }}')"
                                class="card flex w-full items-center gap-3 p-3 text-left transition hover:bg-slate-50 dark:hover:bg-white/5">
                                <x-nav-icon name="folder" class="h-6 w-6 text-documentos-800 dark:text-documentos-200" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $item['pasta']->name }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        {{ $item['documentos'] }} {{ $item['documentos'] === 1 ? 'documento' : 'documentos' }}
                                        @if ($item['subpastas'] > 0)
                                            · {{ $item['subpastas'] }} {{ $item['subpastas'] === 1 ? 'subpasta' : 'subpastas' }}
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (! $verTodos && ! $temPastas && $podeGerenciar)
                <div class="card space-y-2 p-4">
                    <p class="text-sm text-slate-700 dark:text-slate-300">Organize seus documentos em pastas e subpastas, do seu jeito.</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Exemplo: Propriedades › Fazenda Santa Maria › Escritura. Quem ainda não tem pasta pode começar pelas sugestões.</p>
                    <button type="button" wire:click="criarPastasSugeridas" class="btn-secondary px-3 py-1.5 text-xs">Criar pastas sugeridas (Propriedades, Veículos, Família, Outros)</button>
                </div>
            @endif
        </section>
    @endif

    <section class="card space-y-3 p-5">
        @if ($documents->isEmpty())
            <p class="text-xs text-slate-400">{{ $verTodos || ($pastaAtual === null && ! $temPastas) ? 'Nenhum documento guardado ainda.' : 'Nenhum documento nesta pasta.' }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($documents as $documento)
                    <li
                        wire:key="doc-{{ $documento->id }}"
                        x-data="documentDownload"
                        class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-white/10"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $documento->title }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $documento->category->label() }}
                                @if ($verTodos && $documento->folder)
                                    · Pasta: {{ $caminhoPorPasta[$documento->folder_id] ?? $documento->folder->name }}
                                @endif
                                @if ($documento->member)
                                    · {{ $documento->member->name }}
                                @else
                                    · Documento da família
                                @endif
                                @if ($documento->category === DocumentCategory::InsurancePolicy && $documento->insurancePolicy)
                                    · {{ $documento->insurancePolicy->insurer_name }}
                                @endif
                                @if ($documento->expires_on)
                                    · <span class="{{ $documento->expires_on->isPast() ? 'text-red-700 dark:text-red-400' : '' }}">vence {{ $documento->expires_on->format('d/m/Y') }}</span>
                                @endif
                            </p>
                            <p x-show="erro" x-cloak x-text="erro" class="mt-1 text-xs text-red-700 dark:text-red-400"></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($documento->mime_type === 'application/pdf')
                                {{-- PDF: nenhum navegador (Android, desktop, iPhone) sabe renderizar
                                     PDF dentro de um elemento embutido — só em navegação de página
                                     inteira. Abre numa aba nova, deixando o visualizador nativo de
                                     cada aparelho cuidar disso; fechar/trocar de aba volta pro Cerne. --}}
                                <a href="{{ route('documents.vault.file', $documento->id) }}" target="_blank" rel="noopener" class="btn-ghost px-2 py-1 text-xs">
                                    Ver
                                </a>
                            @else
                                <button
                                    type="button"
                                    @click="$store.documentViewer.abrir(@js(route('documents.vault.file', $documento->id)), @js($documento->title), @js($documento->original_filename))"
                                    class="btn-ghost px-2 py-1 text-xs"
                                >
                                    Ver
                                </button>
                            @endif
                            <button
                                type="button"
                                @click="baixar(@js(route('documents.vault.file', $documento->id)), @js($documento->original_filename))"
                                :disabled="baixando"
                                class="text-xs font-medium text-documentos-800 hover:underline disabled:opacity-60 dark:text-documentos-200"
                            >
                                <span x-show="!baixando">Baixar</span>
                                <span x-show="baixando" x-cloak>Baixando...</span>
                            </button>
                            @if ($podeGerenciar)
                                <button type="button" wire:click="editDocument('{{ $documento->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                <button type="button" wire:click="delete('{{ $documento->id }}')" wire:confirm="Remover este documento?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">
                                    remover
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($podeGerenciar)
        <x-modal wire-model="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar documento' : 'Novo documento' }}</h2>
                    <button type="button" wire:click="closeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                        <select wire:model.live="category" class="select mt-1.5">
                            <option value=""></option>
                            @foreach (DocumentCategory::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('category') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Título</label>
                        <input type="text" wire:model="title" class="input mt-1.5" placeholder="Ex.: CNH — Marcelo" maxlength="120">
                        @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa (opcional)</label>
                        <select wire:model="memberId" class="select mt-1.5">
                            <option value="">Documento da família</option>
                            @foreach ($membros as $membro)
                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pasta (opcional)</label>
                        <select wire:model="folderId" class="select mt-1.5">
                            <option value="">Sem pasta (raiz)</option>
                            @foreach ($arvore as $linha)
                                <option value="{{ $linha['pasta']->id }}">{{ str_repeat('— ', $linha['nivel']) }}{{ $linha['pasta']->name }}</option>
                            @endforeach
                        </select>
                        @error('folderId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($category === DocumentCategory::InsurancePolicy->value)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Apólice</label>
                            <select wire:model="insurancePolicyId" class="select mt-1.5">
                                <option value=""></option>
                                @foreach ($policies as $apolice)
                                    <option value="{{ $apolice->id }}">{{ $apolice->insurer_name }} — {{ $apolice->personLabel() ?? 'seguro familiar' }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">Visível ao consultor/corretor exatamente como a apólice já é hoje.</p>
                            @error('insurancePolicyId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data de validade (opcional)</label>
                        <input type="date" wire:model="expiresOn" class="input mt-1.5">
                        @error('expiresOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($category === DocumentCategory::Other->value)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="checkbox" wire:model="visibleToProfessional" class="h-4 w-4 rounded accent-documentos-800">
                            Visível ao consultor/corretor vinculado
                        </label>
                    @endif

                    @if (! $editingExisting)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Arquivo (PDF, JPG ou PNG)</label>
                            <input type="file" wire:model="arquivo" accept=".pdf,.jpg,.jpeg,.png" class="input mt-1.5">
                            <div wire:loading wire:target="arquivo" class="mt-1 text-xs text-slate-400">Enviando...</div>
                            @error('arquivo') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn-primary w-full">Salvar</button>
            </form>
        </x-modal>
    @endif

    @if ($podeGerenciar)
        <x-modal wire-model="showFolderForm" max-width="sm">
            <form wire:submit="saveFolder" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingFolder ? 'Editar pasta' : 'Nova pasta' }}</h2>
                    <button type="button" wire:click="closeFolderForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome da pasta</label>
                    <input type="text" wire:model="folderName" class="input mt-1.5" placeholder="Ex.: Fazenda Santa Maria" maxlength="80">
                    @error('folderName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dentro de</label>
                    <select wire:model="folderParentId" class="select mt-1.5">
                        <option value="">Documentos (raiz)</option>
                        @foreach ($arvoreParaMover as $linha)
                            <option value="{{ $linha['pasta']->id }}">{{ str_repeat('— ', $linha['nivel']) }}{{ $linha['pasta']->name }}</option>
                        @endforeach
                    </select>
                    @error('folderParentId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary flex-1">Salvar</button>
                    @if ($editingFolder)
                        <button type="button" wire:click="deleteFolder('{{ $editingFolderId }}')"
                            wire:confirm="Remover esta pasta? Os documentos e as subpastas dela não são apagados: sobem para a pasta de cima."
                            class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover pasta</button>
                    @endif
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Visualizador de IMAGEM: um modal só pra lista inteira (ver
         x-data="documentViewer" em app.js — store global, não por linha).
         Só imagem passa por aqui (PDF abre em aba nova, ver botão "Ver"
         acima). Nunca navega a página, então "voltar" é só fechar o modal
         — sem o problema do PWA no iPhone preso numa visualização sem
         histórico de navegação nenhum pra desfazer. --}}
    <div
        x-data
        x-show="$store.documentViewer.aberto"
        x-cloak
        x-on:keydown.escape.window="$store.documentViewer.fechar()"
        class="fixed inset-0 z-40"
    >
        <div class="fixed inset-0 bg-slate-950/50 dark:bg-black/60" x-on:click="$store.documentViewer.fechar()"></div>

        <div class="fixed inset-0 overflow-y-auto">
            <div class="flex min-h-full items-start justify-center p-4 pt-10 sm:items-center sm:pt-4">
                <div class="relative w-full sm:max-w-3xl card p-5">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-white" x-text="$store.documentViewer.titulo"></h2>
                        <div class="flex shrink-0 items-center gap-3">
                            <button type="button" x-show="$store.documentViewer.blobUrl" x-cloak @click="$store.documentViewer.baixar()" class="text-xs font-medium text-documentos-800 hover:underline dark:text-documentos-200">
                                Baixar
                            </button>
                            <button type="button" @click="$store.documentViewer.fechar()" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div x-show="$store.documentViewer.carregando" x-cloak class="py-12 text-center text-xs text-slate-400">
                            Abrindo...
                        </div>
                        <p x-show="$store.documentViewer.erro" x-cloak x-text="$store.documentViewer.erro" class="py-12 text-center text-xs text-red-700 dark:text-red-400"></p>

                        <img x-show="$store.documentViewer.blobUrl" :src="$store.documentViewer.blobUrl" class="mx-auto max-h-[75vh] max-w-full rounded-lg">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
