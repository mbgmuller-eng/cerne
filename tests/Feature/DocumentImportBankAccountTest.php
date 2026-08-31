<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Extraction\DocumentCommitService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O saldo da conta só fica certo depois de importar um extrato se a conta
 * for gravada em cada lançamento e o saldo debitado/creditado por item —
 * ver DocumentCommitService::bankStatement().
 */
class DocumentImportBankAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_de_extrato_bancario_exige_conta(): void
    {
        Storage::fake(config('cerne.documents.disk'));
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('extrato.pdf', 100, 'application/pdf'))
            ->set('documentType', 'bank_statement')
            ->call('enviar')
            ->assertHasErrors(['uploadBankAccountId']);
    }

    public function test_upload_de_apolice_nao_exige_conta(): void
    {
        Storage::fake(config('cerne.documents.disk'));
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(DocumentsIndex::class)
            ->set('arquivo', UploadedFile::fake()->create('apolice.pdf', 100, 'application/pdf'))
            ->set('documentType', 'insurance_policy')
            ->call('enviar')
            ->assertHasNoErrors();
    }

    public function test_confirmar_importacao_grava_conta_e_move_o_saldo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['current_balance' => '1000.00']);
        ExpenseCategory::factory()->create(['name' => 'Alimentação']);
        IncomeCategory::factory()->create(['name' => 'Salário']);

        $documento = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => auth()->id() ?? $membro->user_id,
            'member_id' => $membro->id,
            'bank_account_id' => $conta->id,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => [
                'itens' => [
                    ['data' => '2026-08-01', 'descricao' => 'Salário', 'valor' => '5000.00', 'tipo' => 'receita', 'categoria_sugerida' => 'Salário'],
                    ['data' => '2026-08-02', 'descricao' => 'Mercado', 'valor' => '300.00', 'tipo' => 'despesa', 'categoria_sugerida' => 'Alimentação'],
                    ['data' => '2026-08-03', 'descricao' => 'Farmácia', 'valor' => '50.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
                ],
            ],
        ]);

        $criados = app(DocumentCommitService::class)->commit($documento, [0, 1, 2], auth()->id() ?? $membro->user_id);

        self::assertSame(3, $criados);
        self::assertSame('5650.00', $conta->fresh()->current_balance); // 1000 + 5000 - 300 - 50

        $receita = IncomeRecord::withoutProfileScope()->where('description', 'Salário')->sole();
        self::assertSame($conta->id, $receita->bank_account_id);

        $despesas = ExpenseRecord::withoutProfileScope()->where('bank_account_id', $conta->id)->get();
        self::assertCount(2, $despesas);
    }

    public function test_extrato_sem_conta_selecionada_nao_move_saldo_mas_ainda_importa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        ExpenseCategory::factory()->create(['name' => 'Alimentação']);

        $documento = DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'member_id' => $membro->id,
            'bank_account_id' => null,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => [
                'itens' => [
                    ['data' => '2026-08-02', 'descricao' => 'Mercado', 'valor' => '300.00', 'tipo' => 'despesa', 'categoria_sugerida' => 'Alimentação'],
                ],
            ],
        ]);

        $criados = app(DocumentCommitService::class)->commit($documento, [0], $membro->user_id);

        self::assertSame(1, $criados);
        self::assertNull(ExpenseRecord::withoutProfileScope()->where('description', 'Mercado')->sole()->bank_account_id);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
