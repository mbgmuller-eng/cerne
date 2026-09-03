<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Documents\DocumentsIndex;
use App\Models\BankAccount;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Motivado por um caso real: cliente categoriza parte de um extrato longo
 * e quer confirmar aquele tanto sem perder o trabalho, voltando depois
 * pro resto — sem que o documento inteiro vire "Importado" antes da hora
 * (ver DocumentUpload::isFullyResolved()).
 */
class DocumentPartialImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmar_com_apenas_parte_categorizada_importa_so_essa_parte_e_mantem_documento_aberto(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item categorizado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item ainda por categorizar', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors()
            ->assertSet('revisandoId', $documento->id) // painel continua aberto
            // $revisando é computed property (memoizada por request pelo
            // Livewire) — sem dar unset() nela depois do commit(), a tela
            // ainda renderiza com o documento de ANTES de importar, e este
            // contador mostraria "0 já importado" mesmo tendo acabado de
            // importar um.
            ->assertSee('1 já importado', false);

        self::assertSame(1, ExpenseRecord::where('source_document_id', $documento->id)->count());
        self::assertSame('Item categorizado', ExpenseRecord::where('source_document_id', $documento->id)->sole()->description);

        $documento->refresh();
        self::assertSame(ProcessingStatus::Completed, $documento->processing_status);
        self::assertSame([0], $documento->imported_item_indices);
        self::assertFalse($documento->isFullyResolved());
    }

    public function test_reabrir_a_revisao_nao_reoferece_item_ja_importado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item categorizado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item ainda por categorizar', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar');

        $component = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id);

        self::assertSame([1], $component->get('aceitos'));
    }

    public function test_completar_o_restante_depois_finaliza_o_documento(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item categorizado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item ainda por categorizar', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar');

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.1', 'discretionary')
            ->set('categoriaPorItem.1', $categoria->id)
            ->set('subcategoriaPorItem.1', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors()
            ->assertSet('revisandoId', null); // agora fechou

        self::assertSame(2, ExpenseRecord::where('source_document_id', $documento->id)->count());

        $documento->refresh();
        self::assertSame(ProcessingStatus::Committed, $documento->processing_status);
        self::assertNotNull($documento->committed_at);
        self::assertTrue($documento->isFullyResolved());
    }

    public function test_excluir_item_marca_pra_nunca_importar_e_nao_cria_lancamento(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item indesejado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item pendente', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->call('excluirItem', 0)
            ->assertSet('revisandoId', $documento->id);

        self::assertSame(0, ExpenseRecord::where('source_document_id', $documento->id)->count());

        $documento->refresh();
        self::assertSame([0], $documento->excluded_item_indices);
        self::assertSame(ProcessingStatus::Completed, $documento->processing_status);
        self::assertFalse($documento->isFullyResolved());
    }

    public function test_excluir_o_ultimo_item_pendente_finaliza_o_documento(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item categorizado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item indesejado', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->call('confirmar');

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->call('excluirItem', 1)
            ->assertSet('revisandoId', null);

        $documento->refresh();
        self::assertSame(ProcessingStatus::Committed, $documento->processing_status);
        self::assertTrue($documento->isFullyResolved());
    }

    public function test_reabrir_a_revisao_nao_reoferece_item_excluido(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item indesejado', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item pendente', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->call('excluirItem', 0);

        $component = Livewire::test(DocumentsIndex::class)->call('revisar', $documento->id);

        self::assertSame([1], $component->get('aceitos'));
    }

    public function test_confirmar_sem_nenhum_item_pronto_mostra_erro_amigavel_e_nao_lanca_excecao(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item sem categorizar', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->call('confirmar')
            ->assertHasErrors('confirmar')
            ->assertSet('revisandoId', $documento->id);

        self::assertSame(0, ExpenseRecord::count());
        self::assertSame(ProcessingStatus::Completed, $documento->fresh()->processing_status);
    }

    /** Confirmação total (tudo categorizado de uma vez) continua fechando o painel — não é só a parcial que fecha. */
    public function test_confirmar_tudo_de_uma_vez_ainda_fecha_o_painel_e_finaliza(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->for($membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $subcategoria = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $documento = $this->criarExtrato($perfil, $membro, $conta, [
            ['data' => '2026-09-10', 'descricao' => 'Item um', 'valor' => '35.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
            ['data' => '2026-09-11', 'descricao' => 'Item dois', 'valor' => '80.00', 'tipo' => 'despesa', 'categoria_sugerida' => null],
        ]);

        Livewire::test(DocumentsIndex::class)
            ->call('revisar', $documento->id)
            ->set('necessidadePorItem.0', 'essential')
            ->set('categoriaPorItem.0', $categoria->id)
            ->set('subcategoriaPorItem.0', $subcategoria->id)
            ->set('necessidadePorItem.1', 'discretionary')
            ->set('categoriaPorItem.1', $categoria->id)
            ->set('subcategoriaPorItem.1', $subcategoria->id)
            ->call('confirmar')
            ->assertHasNoErrors()
            ->assertSet('revisandoId', null);

        $documento->refresh();
        self::assertSame(ProcessingStatus::Committed, $documento->processing_status);
        self::assertSame(2, ExpenseRecord::where('source_document_id', $documento->id)->count());
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function criarExtrato(FinancialProfile $perfil, ProfileMember $membro, BankAccount $conta, array $itens): DocumentUpload
    {
        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $membro->user_id,
            'member_id' => $membro->id,
            'bank_account_id' => $conta->id,
            'document_type' => 'bank_statement',
            'original_filename' => 'extrato.pdf',
            'storage_path' => 'documentos/x.pdf',
            'processing_status' => 'completed',
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
