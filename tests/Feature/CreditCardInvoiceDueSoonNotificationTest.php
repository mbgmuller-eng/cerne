<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\CreditCardInvoiceDueSoon;
use App\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Espelho de FixedBillDueSoonNotificationTest, cobrindo o outro par de
 * campos de privacidade (is_joint/visible_to_partner em vez de is_private).
 */
class CreditCardInvoiceDueSoonNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = CarbonImmutable::parse('2026-09-01');
    }

    public function test_cartao_oculto_do_conjuge_notifica_so_o_dono(): void
    {
        Notification::fake();
        [$perfil, $titular, $conjuge, $membroConjuge] = $this->criarCasal();

        $card = CreditCard::factory()->create([
            'profile_id' => $perfil->id,
            'member_id' => $membroConjuge->id,
            'is_joint' => false,
            'visible_to_partner' => false,
        ]);
        $this->criarFatura($card, $this->hoje->addDays(3));

        app(InvoiceService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertSentTo($conjuge, CreditCardInvoiceDueSoon::class);
        Notification::assertNotSentTo($titular, CreditCardInvoiceDueSoon::class);
    }

    public function test_cartao_conjunto_notifica_os_dois(): void
    {
        Notification::fake();
        [$perfil, $titular, $conjuge, $membroConjuge] = $this->criarCasal();

        $card = CreditCard::factory()->create([
            'profile_id' => $perfil->id,
            'member_id' => $membroConjuge->id,
            'is_joint' => true,
            'visible_to_partner' => false,
        ]);
        $this->criarFatura($card, $this->hoje->addDays(3));

        app(InvoiceService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertSentTo($titular, CreditCardInvoiceDueSoon::class);
        Notification::assertSentTo($conjuge, CreditCardInvoiceDueSoon::class);
    }

    public function test_fatura_fora_da_janela_de_antecedencia_nao_notifica(): void
    {
        Notification::fake();
        [$perfil, $titular, $membro] = $this->criarPerfil();

        $card = CreditCard::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id]);
        $this->criarFatura($card, $this->hoje->addDays(5));

        app(InvoiceService::class)->notifyUpcomingDueDates($this->hoje);

        Notification::assertNothingSentTo($titular);
    }

    private function criarFatura(CreditCard $card, CarbonImmutable $vencimento): CreditCardInvoice
    {
        return CreditCardInvoice::withoutProfileScope()->create([
            'profile_id' => $card->profile_id,
            'credit_card_id' => $card->id,
            'year' => $vencimento->year,
            'month' => $vencimento->month,
            'closing_date' => $vencimento->subDays(8),
            'due_date' => $vencimento,
            'total_amount' => '250.00',
            'status' => InvoiceStatus::Closed,
        ]);
    }

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$perfil, $titular, $membro];
    }

    /** @return array{0: FinancialProfile, 1: User, 2: User, 3: ProfileMember} */
    private function criarCasal(): array
    {
        [$perfil, $titular] = $this->criarPerfil();

        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        return [$perfil, $titular, $conjuge, $membroConjuge];
    }
}
