/**
 * Academia (Saúde) — pausa entre séries e cronômetro.
 *
 * Tudo no navegador e ancorado num INSTANTE (fim da pausa / início do
 * cronômetro), nunca num contador decrementado: aba em segundo plano
 * espaça os timers, e o contador acumularia atraso. Com o instante
 * guardado em localStorage a contagem também sobrevive a recarregar a
 * tela no meio da pausa. O servidor não sabe da pausa — só recebe as
 * séries concluídas. Não há push do servidor pro fim da pausa: com a
 * tela ligada (interruptor global) o próprio navegador avisa.
 */
function formatClock(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));

    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

document.addEventListener('alpine:init', () => {
    Alpine.data('gymSession', (sessionId) => ({
        restEnd: null,
        restTotal: 0,
        remaining: 0,
        ended: false,
        ticker: null,
        wakeLock: null,
        audio: null,
        canVibrate: 'vibrate' in navigator,
        storageKey: 'cerne.gym.rest.' + sessionId,

        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey) || 'null');

                if (saved && saved.end > Date.now()) {
                    this.restEnd = saved.end;
                    this.restTotal = saved.total;
                    this.tick();
                    this.ticker = setInterval(() => this.tick(), 250);
                } else if (saved) {
                    localStorage.removeItem(this.storageKey);
                }
            } catch (e) {
                // Sem localStorage a pausa só não sobrevive a recarregar.
            }

            this.$watch('$wire.keepAwake', (ligado) => (ligado ? this.acquireWakeLock() : this.releaseWakeLock()));

            // O navegador solta a trava de tela quando a aba some; ao voltar, pede de novo.
            this.onVisible = () => {
                if (document.visibilityState !== 'visible') {
                    return;
                }

                this.tick();

                if (this.$wire.keepAwake) {
                    this.acquireWakeLock();
                }
            };
            document.addEventListener('visibilitychange', this.onVisible);

            if (this.$wire.keepAwake) {
                this.acquireWakeLock();
            }
        },

        destroy() {
            clearInterval(this.ticker);
            document.removeEventListener('visibilitychange', this.onVisible);
            this.releaseWakeLock();
        },

        // Sons só tocam depois de um gesto do usuário: qualquer toque na tela do treino destrava.
        unlockAudio() {
            try {
                this.audio = this.audio ?? new (window.AudioContext || window.webkitAudioContext)();

                if (this.audio.state === 'suspended') {
                    this.audio.resume();
                }
            } catch (e) {
                // Sem WebAudio: fica só a vibração/aviso visual.
            }
        },

        async acquireWakeLock() {
            if (!('wakeLock' in navigator) || this.wakeLock) {
                return;
            }

            try {
                this.wakeLock = await navigator.wakeLock.request('screen');
                this.wakeLock.addEventListener('release', () => {
                    this.wakeLock = null;
                });
            } catch (e) {
                // Negado (economia de bateria, aba oculta): tenta de novo ao voltar.
            }
        },

        releaseWakeLock() {
            this.wakeLock?.release();
            this.wakeLock = null;
        },

        startRest(seconds) {
            this.restTotal = seconds;
            this.restEnd = Date.now() + seconds * 1000;
            this.ended = false;
            this.persist();
            this.tick();
            clearInterval(this.ticker);
            this.ticker = setInterval(() => this.tick(), 250);
        },

        adjustRest(delta) {
            if (this.restEnd === null) {
                return;
            }

            this.restEnd += delta * 1000;
            this.restTotal = Math.max(1, this.restTotal + delta);
            this.persist();
            this.tick();
        },

        cancelRest() {
            this.stopRest();
            this.ended = false;
        },

        persist() {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify({ end: this.restEnd, total: this.restTotal }));
            } catch (e) {
                // idem init()
            }
        },

        stopRest() {
            clearInterval(this.ticker);
            this.restEnd = null;
            this.remaining = 0;

            try {
                localStorage.removeItem(this.storageKey);
            } catch (e) {
                // idem init()
            }
        },

        tick() {
            if (this.restEnd === null) {
                return;
            }

            const left = Math.ceil((this.restEnd - Date.now()) / 1000);

            if (left > 0) {
                this.remaining = left;

                return;
            }

            // Só avisa se o fim foi agora: quem voltou à aba minutos depois não precisa de bipe.
            const fresh = Date.now() - this.restEnd < 3000;
            this.stopRest();
            this.ended = true;
            setTimeout(() => (this.ended = false), 6000);

            if (fresh) {
                this.notify();
            }
        },

        notify() {
            if (this.$wire.vibrate && this.canVibrate) {
                navigator.vibrate([250, 120, 250]);
            }

            if (this.$wire.sound && this.audio) {
                const t0 = this.audio.currentTime;

                [0, 0.25, 0.5].forEach((offset) => {
                    const osc = this.audio.createOscillator();
                    const gain = this.audio.createGain();
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.2, t0 + offset);
                    gain.gain.exponentialRampToValueAtTime(0.001, t0 + offset + 0.18);
                    osc.connect(gain);
                    gain.connect(this.audio.destination);
                    osc.start(t0 + offset);
                    osc.stop(t0 + offset + 0.2);
                });
            }
        },

        clock(seconds) {
            return formatClock(seconds);
        },
    }));

    Alpine.data('gymStopwatch', (sessionId, itemId) => ({
        startedAt: null,
        elapsed: 0,
        ticker: null,
        storageKey: 'cerne.gym.sw.' + sessionId + '.' + itemId,

        init() {
            try {
                const saved = Number(localStorage.getItem(this.storageKey));

                if (saved > 0) {
                    this.startedAt = saved;
                    this.run();
                }
            } catch (e) {
                // Sem localStorage o cronômetro só não sobrevive a recarregar.
            }
        },

        destroy() {
            clearInterval(this.ticker);
        },

        get running() {
            return this.startedAt !== null;
        },

        run() {
            this.update();
            this.ticker = setInterval(() => this.update(), 250);
        },

        update() {
            this.elapsed = Math.max(0, Math.floor((Date.now() - this.startedAt) / 1000));
        },

        start() {
            this.startedAt = Date.now();

            try {
                localStorage.setItem(this.storageKey, String(this.startedAt));
            } catch (e) {
                // idem init()
            }

            this.run();
        },

        reset() {
            clearInterval(this.ticker);
            this.startedAt = null;
            this.elapsed = 0;

            try {
                localStorage.removeItem(this.storageKey);
            } catch (e) {
                // idem init()
            }
        },

        async stop(setNumber) {
            this.update();
            const seconds = Math.max(1, this.elapsed);
            this.reset();
            await this.$wire.completeTimedSet(itemId, setNumber, seconds);
        },

        clock(seconds) {
            return formatClock(seconds);
        },
    }));
});
