@props(['wireModel', 'maxWidth' => 'lg'])

@php
$maxWidthClass = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth] ?? 'sm:max-w-lg';
@endphp

{{--
    Popup reutilizável pros formulários de criar/editar. Fica visível onde
    a pessoa estiver na tela, em vez de abrir empurrando a lista lá em cima.

    `wireModel` é o NOME da propriedade booleana do componente Livewire
    (ex.: "showExpenseForm"), passado como string — não existe
    $attributes->wire('model') nesta versão do Livewire (é helper do
    Jetstream, que não está instalado aqui), então usamos a diretiva nativa
    @entangle() com o nome recebido via @props.

    Sem x-transition de propósito: testado ao vivo e, quando `show` muda
    pelo lado do Livewire (@entangle, não uma interação direta do Alpine),
    o elemento ficava preso em display:none/opacity:0 mesmo depois do
    Livewire confirmar a propriedade como true — a mecânica de transição do
    Alpine não lida bem com essa mudança "de fora". x-show puro (sem
    transição) sincroniza certo nos dois sentidos.

    `@container`: o popup fica no máximo com `max-w-lg` (32rem) mesmo numa
    tela grande — um grid de formulário com `lg:grid-cols-3` reage ao
    VIEWPORT, não a essa largura real, e espremia 3 colunas num espaço que
    não cabia (select cortado, layout quebrado). Os formulários usam
    `@sm:`/`@lg:` (variante de container query do Tailwind 4) em vez de
    `sm:`/`lg:` pra reagir à largura do próprio popup.
--}}
<div
    x-data="{ show: @entangle($wireModel) }"
    x-show="show"
    x-cloak
    x-on:keydown.escape.window="show = false"
    class="fixed inset-0 z-40"
>
    <div class="fixed inset-0 bg-slate-950/50 dark:bg-black/60" x-on:click="show = false"></div>

    <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-start justify-center p-4 pt-10 sm:items-center sm:pt-4">
            <div class="relative w-full {{ $maxWidthClass }} card @container max-h-[85vh] overflow-y-auto p-5">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
