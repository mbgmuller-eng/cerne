<?php

namespace App\Livewire\Admin;

use App\Models\ErrorLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Erros da plataforma, ao vivo (a tela se atualiza sozinha a cada 30 segundos): o que está aberto, quantas vezes aconteceu hoje, quantos
 * usuários bateram, o passo a passo de cada um e o que já foi resolvido ou ignorado. Só o administrador. Não mostra dado de cliente: o
 * registro já guarda só o que é técnico (ver ErrorRecorder).
 */
#[Layout('components.layouts.app')]
class AdminErrors extends Component
{
    /** abertos, resolvidos, ignorados ou todos. */
    #[Url]
    public string $filtro = 'abertos';

    #[Url]
    public string $busca = '';

    public ?int $selecionado = null;

    public bool $detalheAberto = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function abrir(int $id): void
    {
        $this->selecionado = ErrorLog::query()->findOrFail($id)->id;
        $this->detalheAberto = true;
    }

    public function fechar(): void
    {
        $this->selecionado = null;
        $this->detalheAberto = false;
    }

    public function resolver(int $id): void
    {
        ErrorLog::query()->findOrFail($id)->forceFill(['status' => ErrorLog::RESOLVED, 'resolved_at' => now()])->save();
    }

    public function ignorar(int $id): void
    {
        ErrorLog::query()->findOrFail($id)->forceFill(['status' => ErrorLog::IGNORED])->save();
    }

    public function reabrir(int $id): void
    {
        ErrorLog::query()->findOrFail($id)->forceFill(['status' => ErrorLog::NEW, 'resolved_at' => null])->save();
    }

    public function render()
    {
        $hoje = CarbonImmutable::today();

        $erros = ErrorLog::query()
            ->selectRaw('error_logs.*,
                (select count from error_log_days d where d.error_log_id = error_logs.id and d.day = ?) as today_count,
                (select count(*) from error_log_users u where u.error_log_id = error_logs.id) as users_count', [$hoje->toDateString()])
            ->when($this->filtro === 'abertos', fn ($q) => $q->where('status', ErrorLog::NEW))
            ->when($this->filtro === 'resolvidos', fn ($q) => $q->where('status', ErrorLog::RESOLVED))
            ->when($this->filtro === 'ignorados', fn ($q) => $q->where('status', ErrorLog::IGNORED))
            ->when(trim($this->busca) !== '', function ($q) {
                $t = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->busca)).'%';
                $q->where(fn ($w) => $w->where('message', 'like', $t)->orWhere('exception_class', 'like', $t)->orWhere('origin', 'like', $t)->orWhere('file', 'like', $t));
            })
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();

        return view('livewire.admin.admin-errors', [
            'erros' => $erros,
            'resumo' => $this->summary($hoje),
            'detalhe' => $this->detalheAberto && $this->selecionado !== null ? $this->detail($this->selecionado) : null,
        ]);
    }

    /** @return array{open: int, today: int, new_today: int, users_today: int} */
    private function summary(CarbonImmutable $hoje): array
    {
        return [
            'open' => ErrorLog::query()->open()->count(),
            'today' => (int) DB::table('error_log_days')->where('day', $hoje->toDateString())->sum('count'),
            'new_today' => ErrorLog::query()->where('first_seen_at', '>=', $hoje->startOfDay())->count(),
            'users_today' => (int) DB::table('error_log_users as u')->join('error_log_days as d', 'd.error_log_id', '=', 'u.error_log_id')
                ->where('d.day', $hoje->toDateString())->distinct()->count('u.user_id'),
        ];
    }

    /** @return array{erro: ErrorLog, dias: Collection<int, object>, users: int}|null */
    private function detail(int $id): ?array
    {
        $erro = ErrorLog::query()->find($id);

        if ($erro === null) {
            return null;
        }

        return [
            'erro' => $erro,
            'dias' => DB::table('error_log_days')->where('error_log_id', $id)->orderByDesc('day')->limit(14)->get(),
            'users' => (int) DB::table('error_log_users')->where('error_log_id', $id)->count(),
        ];
    }
}
