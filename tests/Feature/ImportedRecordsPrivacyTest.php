<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\DocumentUpload;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\DocumentCommitService;
use App\Support\ProfileContext;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importar extrato ou fatura CRIA lançamentos. O que vem de uma conta ou cartão que a pessoa escondeu do cônjuge nasce oculto (regra 2 do CLAUDE.md:
 * lançamento gerado a partir de algo oculto herda a marca). Conta do casal, ou visível ao cônjuge, segue como sempre foi.
 */
class ImportedRecordsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroBruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaxonomySeeder::class);
        $this->ana = User::factory()->create();
        $this->bruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->bruno->id, 'name' => 'Bruno']);

        $this->entrar($this->ana, $this->membroAna);
    }

    private function entrar(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function conta(bool $conjunta, bool $visivel): BankAccount
    {
        return BankAccount::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroAna->id, 'current_balance' => '5000.00', 'is_joint' => $conjunta, 'visible_to_partner' => $visivel,
        ]);
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function importar(array $itens, ?BankAccount $conta, ?CreditCard $cartao = null): void
    {
        $documento = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'uploaded_by_user_id' => $this->ana->id, 'member_id' => $this->membroAna->id,
            'bank_account_id' => $conta?->id, 'credit_card_id' => $cartao?->id,
            'document_type' => $cartao !== null ? DocumentType::CreditCardInvoice : DocumentType::BankStatement,
            'original_filename' => 'extrato.pdf', 'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed, 'extraction_summary' => ['itens' => $itens],
        ]);

        app(DocumentCommitService::class)->commit($documento, array_keys($itens), $this->ana->id);
    }

    private function despesa(): array
    {
        return ['data' => '2026-10-16', 'descricao' => 'Mercado', 'valor' => '120.00', 'tipo' => 'despesa', 'categoria_sugerida' => null];
    }

    private function receita(): array
    {
        return ['data' => '2026-10-17', 'descricao' => 'Freela', 'valor' => '900.00', 'tipo' => 'receita', 'categoria_sugerida' => null];
    }

    public function test_extrato_de_conta_oculta_gera_despesa_e_receita_ocultas_que_o_conjuge_nao_ve(): void
    {
        $this->importar([$this->despesa(), $this->receita()], $this->conta(conjunta: false, visivel: false));

        $this->assertTrue(ExpenseRecord::query()->sole()->is_private);
        $this->assertTrue(IncomeRecord::query()->sole()->is_private);

        $this->entrar($this->bruno, $this->membroBruno);

        $this->assertSame(0, ExpenseRecord::query()->count());
        $this->assertSame(0, IncomeRecord::query()->count());
    }

    public function test_extrato_de_conta_do_casal_ou_visivel_continua_visivel_aos_dois(): void
    {
        $this->importar([$this->despesa()], $this->conta(conjunta: true, visivel: true));
        $this->importar([$this->receita()], $this->conta(conjunta: false, visivel: true));

        $this->assertFalse(ExpenseRecord::query()->sole()->is_private);
        $this->assertFalse(IncomeRecord::query()->sole()->is_private);

        $this->entrar($this->bruno, $this->membroBruno);

        $this->assertSame(1, ExpenseRecord::query()->count());
        $this->assertSame(1, IncomeRecord::query()->count());
    }

    public function test_fatura_de_cartao_oculto_gera_despesa_oculta(): void
    {
        $cartao = CreditCard::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $this->membroAna->id, 'is_joint' => false, 'visible_to_partner' => false, 'closing_day' => 10, 'due_day' => 20,
        ]);

        $this->importar([$this->despesa()], null, $cartao);

        $this->assertTrue(ExpenseRecord::query()->sole()->is_private);

        $this->entrar($this->bruno, $this->membroBruno);
        $this->assertSame(0, ExpenseRecord::query()->count());
    }
}
