@props(['exercise', 'size' => 'h-14 w-14 rounded-lg'])

{{--
    Foto do exercício. Quando tem os dois quadros (vínculo automático de
    referência — ver cerne:gym-link-images), alterna entre início e fim do
    movimento a cada 1,1s: dá o efeito de GIF sem gerar ou guardar um
    arquivo animado. Uma foto só (enviada à mão) fica parada.

    Clicar amplia — o zoom usa o MESMO estado (`src`) da miniatura, então
    a animação continua rodando ampliada, sem duplicar o timer.

    `prefers-reduced-motion` desliga a troca — mostra só o primeiro
    quadro parado.
--}}
@if ($exercise->imageUrl())
    {{-- display:contents: a div não entra no layout (grid/flex do chamador vê só o <img>) --}}
    <div
        class="contents"
        x-data="{
            frames: [@js($exercise->imageUrl(1)), @js($exercise->imageUrl(2))],
            i: 0,
            src: @js($exercise->imageUrl(1)),
            open: false,
            timer: null,
            init() {
                if (! {{ $exercise->hasAnimatedImage() ? 'true' : 'false' }}) return;
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                this.timer = setInterval(() => { this.i = 1 - this.i; this.src = this.frames[this.i]; }, 1100);
            },
            destroy() { clearInterval(this.timer); },
        }"
    >
        <img
            :src="src"
            alt=""
            loading="lazy"
            x-on:click="open = true"
            {{ $attributes->merge(['class' => $size.' shrink-0 cursor-zoom-in object-cover ring-1 ring-slate-200 dark:ring-white/10']) }}
        >

        <div x-show="open" x-cloak x-on:keydown.escape.window="open = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4" x-on:click="open = false">
            <img :src="src" alt="{{ $exercise->name }}" class="max-h-[85vh] max-w-full rounded-xl object-contain">
            <button type="button" x-on:click.stop="open = false"
                    class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-black/40 text-lg text-white/90"
                    aria-label="Fechar">✕</button>
        </div>
    </div>
@endif
