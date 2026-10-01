<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_ve_a_vitrine(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Assinar')
            ->assertSee(Money::format(config('billing.prices.'.\App\Enums\SubscriptionBundle::Completo->value)), false);
    }

    public function test_logado_e_redirecionado_pro_painel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_termos_abre_pra_qualquer_um(): void
    {
        $this->get(route('legal.terms'))->assertOk();
    }
}
