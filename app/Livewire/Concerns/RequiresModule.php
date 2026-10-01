<?php

namespace App\Livewire\Concerns;

use App\Enums\PlatformModule;
use App\Services\EntitlementService;
use App\Support\ProfileContext;

/**
 * Toda tela de um produto pago (Finanças/Seguros/Documentos/Saúde) chama
 * isto no mount(), depois de RequiresActiveProfile — sem perfil ativo
 * quem decide é ela. 402 (Payment Required) é o status certo aqui: tem
 * view própria em resources/views/errors/402.blade.php, Laravel não vem
 * com uma.
 */
trait RequiresModule
{
    protected function abortUnlessModuleEntitled(PlatformModule $modulo): void
    {
        $profile = app(ProfileContext::class)->profile();

        if ($profile === null) {
            return;
        }

        abort_unless(app(EntitlementService::class)->profileHasModule($profile, $modulo), 402);
    }
}
