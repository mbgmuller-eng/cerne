<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Documents\DocumentVaultIndex;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DocumentFolderService;
use App\Support\ProfileContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pastas e subpastas em Documentos (sugestão de quem testa o app). Pasta só ORGANIZA:
 * quem vê cada documento continua sendo decidido pela categoria (DocumentVisibilityScope).
 */
class DocumentFolderTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $titular;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.document_vault.disk'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->titular = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'name' => 'Marcelo']);

        $this->entrar();
    }

    private function entrar(): void
    {
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->titular);
    }

    private function servico(): DocumentFolderService
    {
        return app(DocumentFolderService::class);
    }

    private function pasta(string $nome, ?DocumentFolder $pai = null): DocumentFolder
    {
        return $this->servico()->create($nome, $pai);
    }

    private function documento(array $extra = []): Document
    {
        return Document::withoutGlobalScopes()->create($extra + [
            'profile_id' => $this->perfil->id, 'member_id' => $this->titular->id, 'category' => 'other', 'title' => 'Doc',
            'original_filename' => 'doc.pdf', 'storage_path' => 'x', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
            'visible_to_professional' => false, 'created_by_member_id' => $this->titular->id,
        ]);
    }

    private function entrarComoConsultor(): FinancialProfile
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active]);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, member: null, asConsultant: true);

        return $this->perfil;
    }

    // ---------------------------------------------------------------- serviço

    public function test_cria_pasta_e_subpastas_em_arvore(): void
    {
        $propriedades = $this->pasta('Propriedades');
        $fazenda = $this->pasta('Fazenda Santa Maria', $propriedades);
        $escritura = $this->pasta('Escritura', $fazenda);

        self::assertSame(['Propriedades', 'Fazenda Santa Maria', 'Escritura'], $this->servico()->ancestors($escritura)->pluck('name')->all());
        self::assertSame(3, $this->servico()->depth($escritura));
        self::assertSame($this->perfil->id, $escritura->profile_id);
    }

    public function test_nome_repetido_na_mesma_pasta_e_barrado_ignorando_acento_e_caixa(): void
    {
        $this->pasta('Família');

        $this->expectException(DomainException::class);
        $this->pasta('  familia ');
    }

    public function test_mesmo_nome_em_pastas_diferentes_e_permitido(): void
    {
        $a = $this->pasta('Propriedades');
        $b = $this->pasta('Veículos');

        $this->pasta('Contratos', $a);
        $this->pasta('Contratos', $b);

        self::assertSame(2, DocumentFolder::query()->where('name', 'Contratos')->count());
    }

    public function test_limite_de_profundidade(): void
    {
        $pasta = $this->pasta('N1');
        foreach (['N2', 'N3', 'N4', 'N5'] as $nome) {
            $pasta = $this->pasta($nome, $pasta);
        }

        $this->expectException(DomainException::class);
        $this->pasta('N6', $pasta);
    }

    public function test_nome_vazio_ou_longo_demais_e_barrado(): void
    {
        foreach (['   ', str_repeat('a', 81)] as $nome) {
            try {
                $this->pasta($nome);
                self::fail('Era esperado DomainException.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_mover_pasta_para_dentro_dela_mesma_ou_de_uma_subpasta_e_barrado(): void
    {
        $a = $this->pasta('A');
        $b = $this->pasta('B', $a);

        foreach ([$a, $b] as $destino) {
            try {
                $this->servico()->update($a, 'A', $destino);
                self::fail('Era esperado DomainException.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        self::assertNull($a->fresh()->parent_id);
    }

    public function test_mover_respeita_a_profundidade_contando_as_subpastas_que_vao_junto(): void
    {
        $fundo = $this->pasta('F1');
        foreach (['F2', 'F3', 'F4'] as $nome) {
            $fundo = $this->pasta($nome, $fundo);
        }
        $com2Niveis = $this->pasta('X');
        $this->pasta('Y', $com2Niveis);

        $this->expectException(DomainException::class);
        $this->servico()->update($com2Niveis, 'X', $fundo); // F4 está no nível 4; X (2 níveis) passaria de 5
    }

    public function test_renomear_e_mover_pelo_servico(): void
    {
        $a = $this->pasta('A');
        $b = $this->pasta('B');

        $this->servico()->update($b, 'Bela', $a);

        self::assertSame('Bela', $b->fresh()->name);
        self::assertSame($a->id, $b->fresh()->parent_id);
    }

    public function test_apagar_pasta_sobe_documentos_e_subpastas_e_nao_apaga_nada(): void
    {
        $raiz = $this->pasta('Propriedades');
        $meio = $this->pasta('Fazenda', $raiz);
        $filha = $this->pasta('Contratos', $meio);
        $doc = $this->documento(['folder_id' => $meio->id]);
        $docFilha = $this->documento(['folder_id' => $filha->id]);

        $this->servico()->delete($meio);

        self::assertNull(DocumentFolder::query()->find($meio->id));
        self::assertSame($raiz->id, $filha->fresh()->parent_id, 'a subpasta sobe');
        self::assertSame($raiz->id, $doc->fresh()->folder_id, 'o documento sobe');
        self::assertSame($filha->id, $docFilha->fresh()->folder_id);
        self::assertSame(2, Document::query()->count());
    }

    public function test_apagar_pasta_da_raiz_manda_o_conteudo_para_a_raiz(): void
    {
        $pasta = $this->pasta('Solta');
        $doc = $this->documento(['folder_id' => $pasta->id]);

        $this->servico()->delete($pasta);

        self::assertNull($doc->fresh()->folder_id);
        self::assertSame(1, Document::query()->count());
    }

    public function test_subpasta_que_sobe_com_nome_repetido_ganha_sufixo(): void
    {
        $raiz = $this->pasta('Propriedades');
        $this->pasta('Contratos', $raiz);
        $meio = $this->pasta('Fazenda', $raiz);
        $this->pasta('Contratos', $meio);

        $this->servico()->delete($meio);

        self::assertSame(['Contratos', 'Contratos (2)'], DocumentFolder::query()->where('parent_id', $raiz->id)->orderBy('name')->pluck('name')->all());
    }

    public function test_pastas_sugeridas_so_para_quem_nao_tem_nenhuma(): void
    {
        self::assertSame(4, $this->servico()->createSuggested());
        self::assertSame(['Família', 'Outros', 'Propriedades', 'Veículos'], DocumentFolder::query()->orderBy('name')->pluck('name')->all());
        self::assertSame(0, $this->servico()->createSuggested(), 'idempotente');
    }

    public function test_outro_perfil_nao_enxerga_nem_move_para_a_pasta_alheia(): void
    {
        $minha = $this->pasta('Minha pasta');

        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $outroMembro = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id]);
        $this->actingAs($outroUsuario);
        app(ProfileContext::class)->set($outroPerfil, $outroMembro);

        self::assertSame(0, DocumentFolder::query()->count());

        $upload = app(\App\Services\DocumentService::class)->upload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), [
            'category' => 'other', 'title' => 'Doc', 'folder_id' => $minha->id,
        ], $outroMembro);

        self::assertNull($upload->folder_id, 'pasta de outro perfil vira "sem pasta"');
    }

    // ---------------------------------------------------------------- tela

    public function test_cria_pasta_e_subpasta_pela_tela_e_navega(): void
    {
        $tela = Livewire::test(DocumentVaultIndex::class)
            ->call('newFolder')
            ->set('folderName', 'Propriedades')
            ->call('saveFolder')
            ->assertHasNoErrors()
            ->assertSee('Propriedades');

        $propriedades = DocumentFolder::query()->sole();

        $tela->call('abrirPasta', $propriedades->id)
            ->call('newFolder')
            ->assertSet('folderParentId', $propriedades->id)
            ->set('folderName', 'Fazenda Santa Maria')
            ->call('saveFolder')
            ->assertHasNoErrors()
            ->assertSee('Fazenda Santa Maria');

        self::assertSame($propriedades->id, DocumentFolder::query()->where('name', 'Fazenda Santa Maria')->value('parent_id'));
    }

    public function test_erro_de_nome_repetido_aparece_no_formulario(): void
    {
        $this->pasta('Família');

        Livewire::test(DocumentVaultIndex::class)
            ->call('newFolder')
            ->set('folderName', 'familia')
            ->call('saveFolder')
            ->assertHasErrors('folderName')
            ->assertSee('Já existe uma pasta com esse nome aqui.')
            ->assertSet('showFolderForm', true);
    }

    public function test_a_raiz_mostra_so_os_documentos_sem_pasta_e_cada_pasta_so_os_dela(): void
    {
        $pasta = $this->pasta('Fazenda');
        $this->documento(['title' => 'Solto na raiz']);
        $this->documento(['title' => 'Escritura da fazenda', 'folder_id' => $pasta->id]);

        Livewire::test(DocumentVaultIndex::class)
            ->assertSee('Solto na raiz')
            ->assertDontSee('Escritura da fazenda')
            ->assertSee('1 documento')
            ->call('abrirPasta', $pasta->id)
            ->assertSee('Escritura da fazenda')
            ->assertDontSee('Solto na raiz');
    }

    public function test_ver_todos_lista_tudo_com_o_caminho_da_pasta(): void
    {
        $a = $this->pasta('Propriedades');
        $b = $this->pasta('Fazenda', $a);
        $this->documento(['title' => 'Escritura', 'folder_id' => $b->id]);
        $this->documento(['title' => 'Solto']);

        Livewire::test(DocumentVaultIndex::class)
            ->call('alternarVerTodos')
            ->assertSee('Escritura')
            ->assertSee('Solto')
            ->assertSee('Pasta: Propriedades › Fazenda');
    }

    public function test_caminho_no_topo_leva_de_volta_para_cada_nivel(): void
    {
        $a = $this->pasta('Propriedades');
        $b = $this->pasta('Fazenda', $a);

        Livewire::test(DocumentVaultIndex::class)
            ->call('abrirPasta', $b->id)
            ->assertSeeInOrder(['Documentos', 'Propriedades', 'Fazenda'])
            ->call('abrirPasta', $a->id)
            ->assertSet('pasta', $a->id)
            ->call('abrirPasta', '')
            ->assertSet('pasta', '');
    }

    public function test_pasta_inexistente_na_url_cai_na_raiz(): void
    {
        $this->documento(['title' => 'Solto na raiz']);

        Livewire::withQueryParams(['pasta' => '00000000-0000-0000-0000-000000000000'])->test(DocumentVaultIndex::class)
            ->assertSee('Solto na raiz');
    }

    public function test_novo_documento_ja_nasce_na_pasta_aberta_e_grava_a_pasta(): void
    {
        $pasta = $this->pasta('Veículos');

        Livewire::test(DocumentVaultIndex::class)
            ->call('abrirPasta', $pasta->id)
            ->call('newDocument')
            ->assertSet('folderId', $pasta->id)
            ->set('category', 'other')
            ->set('title', 'IPVA do Honda')
            ->set('arquivo', UploadedFile::fake()->create('ipva.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame($pasta->id, Document::query()->sole()->folder_id);
    }

    public function test_mover_documento_de_pasta_pela_edicao(): void
    {
        $a = $this->pasta('A');
        $b = $this->pasta('B');
        $doc = $this->documento(['folder_id' => $a->id]);

        Livewire::test(DocumentVaultIndex::class)
            ->call('editDocument', $doc->id)
            ->assertSet('folderId', $a->id)
            ->set('folderId', $b->id)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame($b->id, $doc->fresh()->folder_id);

        Livewire::test(DocumentVaultIndex::class)
            ->call('editDocument', $doc->id)
            ->set('folderId', '')
            ->call('save');

        self::assertNull($doc->fresh()->folder_id);
    }

    public function test_pasta_inexistente_no_formulario_do_documento_e_barrada(): void
    {
        Livewire::test(DocumentVaultIndex::class)
            ->call('newDocument')
            ->set('category', 'other')
            ->set('title', 'X')
            ->set('folderId', '00000000-0000-0000-0000-000000000000')
            ->set('arquivo', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('folderId');
    }

    public function test_apagar_a_pasta_aberta_volta_para_o_nivel_de_cima(): void
    {
        $a = $this->pasta('A');
        $b = $this->pasta('B', $a);

        Livewire::test(DocumentVaultIndex::class)
            ->call('abrirPasta', $b->id)
            ->call('deleteFolder', $b->id)
            ->assertSet('pasta', $a->id);

        // Apagar uma pasta de cima da que está aberta também tira a pessoa de dentro dela.
        $c = $this->pasta('C');
        $d = $this->pasta('D', $c);

        Livewire::test(DocumentVaultIndex::class)
            ->call('abrirPasta', $d->id)
            ->call('deleteFolder', $c->id)
            ->assertSet('pasta', '');
    }

    public function test_primeira_vez_oferece_as_pastas_sugeridas(): void
    {
        Livewire::test(DocumentVaultIndex::class)
            ->assertSee('Criar pastas sugeridas')
            ->call('criarPastasSugeridas')
            ->assertDontSee('Criar pastas sugeridas')
            ->assertSee('Propriedades')
            ->assertSee('Veículos');
    }

    // ---------------------------------------------------------------- privacidade

    public function test_consultor_so_ve_pasta_que_tem_documento_dele_e_o_caminho_ate_la(): void
    {
        $propriedades = $this->pasta('Propriedades');
        $fazenda = $this->pasta('Fazenda', $propriedades);
        $divorcio = $this->pasta('Divórcio');
        $vazia = $this->pasta('Pasta vazia');

        $this->documento(['title' => 'Contrato da fazenda', 'category' => 'other', 'visible_to_professional' => true, 'folder_id' => $fazenda->id]);
        $this->documento(['title' => 'Petição', 'category' => 'other', 'visible_to_professional' => false, 'folder_id' => $divorcio->id]);
        $this->documento(['title' => 'Exame', 'category' => 'health_exam', 'visible_to_professional' => true, 'folder_id' => $divorcio->id]);

        $this->entrarComoConsultor();

        Livewire::test(DocumentVaultIndex::class)
            ->assertSee('Propriedades')
            ->assertDontSee('Divórcio')
            ->assertDontSee('Pasta vazia')
            ->assertDontSee('Petição')
            ->call('abrirPasta', $propriedades->id)
            ->assertSee('Fazenda')
            ->call('abrirPasta', $fazenda->id)
            ->assertSee('Contrato da fazenda');
    }

    public function test_consultor_nao_abre_pasta_escondida_nem_pela_url(): void
    {
        $divorcio = $this->pasta('Divórcio');
        $this->documento(['title' => 'Petição', 'folder_id' => $divorcio->id]);
        $this->documento(['title' => 'Aberto na raiz', 'visible_to_professional' => true]);

        $this->entrarComoConsultor();

        Livewire::withQueryParams(['pasta' => $divorcio->id])->test(DocumentVaultIndex::class)
            ->assertDontSee('Divórcio')
            ->assertDontSee('Petição')
            ->assertSee('Aberto na raiz');
    }

    public function test_ver_todos_do_consultor_continua_filtrado_pela_categoria(): void
    {
        $pasta = $this->pasta('Mista');
        $this->documento(['title' => 'Para o consultor', 'visible_to_professional' => true, 'folder_id' => $pasta->id]);
        $this->documento(['title' => 'Privado', 'visible_to_professional' => false, 'folder_id' => $pasta->id]);

        $this->entrarComoConsultor();

        Livewire::test(DocumentVaultIndex::class)
            ->call('alternarVerTodos')
            ->assertSee('Para o consultor')
            ->assertDontSee('Privado');
    }

    public function test_apolice_dentro_de_pasta_continua_seguindo_a_regra_da_apolice(): void
    {
        $pasta = $this->pasta('Seguros');
        $apolice = InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id]);
        $doc = $this->documento(['title' => 'Apólice', 'category' => 'insurance_policy', 'insurance_policy_id' => $apolice->id, 'folder_id' => $pasta->id]);

        $this->entrarComoConsultor();

        self::assertSame([$doc->id], Document::query()->pluck('id')->all());
        Livewire::test(DocumentVaultIndex::class)->assertSee('Seguros')->call('abrirPasta', $pasta->id)->assertSee('Apólice');
    }

    public function test_consultor_nao_cria_edita_nem_apaga_pasta(): void
    {
        $pasta = $this->pasta('Intocável');
        $this->documento(['visible_to_professional' => true, 'folder_id' => $pasta->id]);
        $this->entrarComoConsultor();

        Livewire::test(DocumentVaultIndex::class)
            ->assertDontSee('+ Nova pasta')
            ->call('newFolder')->assertForbidden();

        Livewire::test(DocumentVaultIndex::class)->call('editFolder', $pasta->id)->assertForbidden();
        Livewire::test(DocumentVaultIndex::class)->call('deleteFolder', $pasta->id)->assertForbidden();
        Livewire::test(DocumentVaultIndex::class)->call('criarPastasSugeridas')->assertForbidden();

        self::assertNotNull(DocumentFolder::withoutGlobalScopes()->find($pasta->id));
    }

    public function test_mover_documento_para_pasta_nao_muda_a_visibilidade_dele(): void
    {
        $aberta = $this->pasta('Aberta');
        $doc = $this->documento(['title' => 'Exame', 'category' => 'health_exam', 'visible_to_professional' => false]);

        $doc->update(['folder_id' => $aberta->id]);

        $this->entrarComoConsultor();
        self::assertSame(0, Document::query()->count(), 'pasta nunca libera documento para o profissional');
    }
}
