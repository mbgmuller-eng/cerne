<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importar "Fatura de cartão" tinha dois furos: a despesa criada nunca
 * ficava vinculada a nenhum cartão/fatura de verdade (o total da fatura
 * real nunca batia com o que acabou de ser importado), e não havia como
 * lançar um estorno (contestação, cashback) sem categorizar igual a uma
 * despesa comum.
 */
class CreditCardInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_importado_fica_vinculado_ao_cartao_e_a_fatura_certa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = \App\Models\ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarFatura($perfil, $membro, $cartao, [
            ['data' => '2026-09-10', 'descricao' => 'Supermercado', 'valor' => '150.00', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::where('source_document_id', $documento->id)->sole();
        self::assertSame($cartao->id, $lancamento->credit_card_id);
        self::assertNotNull($lancamento->credit_card_invoice_id);

        $fatura = CreditCardInvoice::findOrFail($lancamento->credit_card_invoice_id);
        self::assertSame($cartao->id, $fatura->credit_card_id);
        self::assertSame('150.00', $fatura->total_amount);
    }

    public function test_estorno_detectado_pelo_valor_negativo_nao_pede_categoria_e_abate_o_total(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = \App\Models\ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarFatura($perfil, $membro, $cartao, [
            ['data' => '2026-09-10', 'descricao' => 'Compra normal', 'valor' => '500.00', 'categoria_sugerida' => null],
            ['data' => '2026-09-12', 'descricao' => 'CREDITO PROV CONTESTACAO', 'valor' => '-200.00', 'categoria_sugerida' => null],
        ]);

        $component = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id);

        // A IA já leu o valor negativo — o item vem pré-marcado como
        // estorno, sem precisar de nenhuma ação manual.
        self::assertTrue($component->get('estornoPorItem')[1]);
        self::assertFalse($component->get('itensFaltandoCategoria')[1]);

        $component
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors();

        $estorno = ExpenseRecord::where('description', 'CREDITO PROV CONTESTACAO')->sole();
        self::assertTrue($estorno->is_refund);
        self::assertNull($estorno->necessity);
        self::assertNull($estorno->category_id);
        self::assertSame('-200.00', $estorno->amount);
        self::assertSame($cartao->id, $estorno->credit_card_id);
        self::assertNotNull($estorno->credit_card_invoice_id);

        $fatura = CreditCardInvoice::findOrFail($estorno->credit_card_invoice_id);
        // 500 (compra) - 200 (estorno) = 300, sem lógica extra em InvoiceService.
        self::assertSame('300.00', $fatura->total_amount);
    }

    public function test_pessoa_pode_desmarcar_o_estorno_pre_marcado_e_tratar_como_despesa_normal(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = \App\Models\ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarFatura($perfil, $membro, $cartao, [
            ['data' => '2026-09-10', 'descricao' => 'Ajuste', 'valor' => '-50.00', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('estornoPorItem.0', false)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors();

        $lancamento = ExpenseRecord::where('description', 'Ajuste')->sole();
        self::assertFalse($lancamento->is_refund);
        self::assertSame($categoria->id, $lancamento->category_id);
    }

    public function test_upload_de_fatura_sem_cartao_selecionado_e_bloqueado(): void
    {
        $this->criarPerfil();

        Livewire::test(DocumentsIndex::class)
            ->set('documentType', 'credit_card_invoice')
            ->set('uploadCreditCardId', '')
            ->set('arquivo', \Illuminate\Http\UploadedFile::fake()->create('fatura.pdf', 100, 'application/pdf'))
            ->call('enviar')
            ->assertHasErrors('uploadCreditCardId');
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function criarFatura(FinancialProfile $perfil, ProfileMember $membro, CreditCard $cartao, array $itens): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'member_id' => $membro->id,
            'credit_card_id' => $cartao->id,
            'document_type' => DocumentType::CreditCardInvoice,
            'original_filename' => 'fatura.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => ProcessingStatus::Completed,
            'extraction_summary' => ['itens' => $itens],
        ]);
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
