<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SubscriptionAccessEnding;
use App\Notifications\SubscriptionOverdue;
use App\Notifications\SubscriptionPaymentFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Com Notification::fake() o e-mail nunca é renderizado nos outros testes,
 * então um erro de template só apareceria em produção.
 */
class SubscriptionBillingMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_aviso_de_cartao_recusado_renderiza_com_e_sem_link_de_fatura(): void
    {
        $usuario = User::factory()->create(['name' => 'Marina Alencar']);

        $comLink = (new SubscriptionPaymentFailed('Completo', '15/10/2026', 'https://sandbox.asaas.com/i/x'))->toMail($usuario)->render();
        $semLink = (new SubscriptionPaymentFailed('Completo', '15/10/2026', null))->toMail($usuario)->render();

        self::assertStringContainsString('Marina Alencar', (string) $comLink);
        self::assertStringContainsString('15/10/2026', (string) $comLink);
        self::assertStringContainsString('https://sandbox.asaas.com/i/x', (string) $comLink);
        self::assertStringContainsString(route('subscription.index'), (string) $semLink);
    }

    public function test_aviso_de_atraso_renderiza(): void
    {
        $usuario = User::factory()->create();

        $html = (string) (new SubscriptionOverdue('Completo', '10/10/2026', '15/10/2026', null))->toMail($usuario)->render();

        self::assertStringContainsString('10/10/2026', $html);
        self::assertStringContainsString('15/10/2026', $html);
    }

    public function test_aviso_de_acesso_encerrando_renderiza(): void
    {
        $usuario = User::factory()->create();

        $html = (string) (new SubscriptionAccessEnding('Completo', '15/10/2026', 'https://sandbox.asaas.com/i/y'))->toMail($usuario)->render();

        self::assertStringContainsString('15/10/2026', $html);
        self::assertStringContainsString('https://sandbox.asaas.com/i/y', $html);
    }
}
