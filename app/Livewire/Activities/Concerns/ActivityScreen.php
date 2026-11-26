<?php

namespace App\Livewire\Activities\Concerns;

use App\Enums\PlatformModule;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresModule;
use App\Livewire\Concerns\RequiresPersonalHealth;

/** O que toda tela da área Atividades exige: perfil ativo, ser o próprio dono (nem cônjuge, nem consultor) e a assinatura da Saúde. */
trait ActivityScreen
{
    use RequiresActiveProfile, RequiresModule, RequiresPersonalHealth;

    protected function guardActivityScreen(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
        $this->abortUnlessModuleEntitled(PlatformModule::Saude);
    }
}
