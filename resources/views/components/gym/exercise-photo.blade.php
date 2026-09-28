@props(['exercise', 'size' => 'h-14 w-14 rounded-lg'])

{{--
    Foto do exercício. Quando tem os dois quadros (vínculo automático de
    referência — ver cerne:gym-link-images), alterna entre início e fim do
    movimento a cada 1,1s: dá o efeito de GIF sem gerar ou guardar um
    arquivo animado. Uma foto só (enviada à mão) fica parada.

    `prefers-reduced-motion` desliga a troca — mostra só o primeiro
    quadro parado.
--}}
@if ($exercise->imageUrl())
    <img
        src="{{ $exercise->imageUrl() }}"
        alt=""
        loading="lazy"
        {{ $attributes->merge(['class' => $size.' shrink-0 object-cover ring-1 ring-slate-200 dark:ring-white/10']) }}
        @if ($exercise->hasAnimatedImage())
            x-data="{
                frames: [@js($exercise->imageUrl(1)), @js($exercise->imageUrl(2))],
                i: 0,
                timer: null,
                init() {
                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                    this.timer = setInterval(() => { this.i = 1 - this.i; this.$el.src = this.frames[this.i]; }, 1100);
                },
                destroy() { clearInterval(this.timer); },
            }"
        @endif
    >
@endif
