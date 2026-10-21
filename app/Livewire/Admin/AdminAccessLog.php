<?php

namespace App\Livewire\Admin;

use App\Models\ImpersonationLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quem do time entrou na conta de quem, quando e por quê ("Entrar como"). Só o administrador. É a prestação de contas do acesso mais
 * sensível da plataforma: ver ImpersonationLog.
 */
#[Layout('components.layouts.app')]
class AdminAccessLog extends Component
{
    #[Url]
    public string $busca = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function render()
    {
        $t = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->busca)).'%';

        return view('livewire.admin.admin-access-log', [
            'acessos' => ImpersonationLog::query()
                ->when(trim($this->busca) !== '', fn ($q) => $q->where(fn ($w) => $w->where('target_email', 'like', $t)->orWhere('target_name', 'like', $t)->orWhere('admin_email', 'like', $t)->orWhere('reason', 'like', $t)))
                ->orderByDesc('started_at')->limit(300)->get(),
        ]);
    }
}
