<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Notifications\NotificationCenter;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DocumentProcessed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_a_contagem_de_nao_lidas(): void
    {
        $usuario = $this->criarUsuarioComPerfil();

        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Failed)));

        Livewire::test(NotificationCenter::class)
            ->assertViewHas('unreadCount', 2);
    }

    public function test_marcar_como_lida_reduz_a_contagem(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $id = $usuario->notifications()->first()->id;

        Livewire::test(NotificationCenter::class)
            ->call('markAsRead', $id)
            ->assertViewHas('unreadCount', 0);

        self::assertNotNull($usuario->notifications()->find($id)->read_at);
    }

    public function test_marcar_tudo_como_lido(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Failed)));

        Livewire::test(NotificationCenter::class)
            ->call('markAllAsRead')
            ->assertViewHas('unreadCount', 0);
    }

    /** @param  array<string, mixed>  $dados */
    private function gravar(User $usuario, array $dados, ?string $quando = null): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Notifications\DatabaseNotification::create([
            'id' => $id,
            'type' => 'App\Notifications\Qualquer',
            'notifiable_type' => User::class,
            'notifiable_id' => $usuario->id,
            'data' => $dados,
            'created_at' => $quando ?? now(),
        ]);

        return $id;
    }

    public function test_aniversario_mostra_quem_e_quando_em_vez_de_so_o_nome(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        // Formato exato do registro antigo em produção: só kind e campos crus.
        $this->gravar($usuario, [
            'kind' => 'client_birthday_upcoming', 'member_id' => 'x', 'title' => 'Helen Muller',
            'occurrence_date' => '2026-10-14', 'turning_age' => 40,
        ]);

        Livewire::test(NotificationCenter::class)
            ->assertSee('Aniversário chegando')
            ->assertSee('Helen Muller completa 40 anos em 14/10');
    }

    public function test_cada_tipo_diz_do_que_trata(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $casos = [
            [['kind' => 'insurance_policy_expiring_upcoming', 'title' => 'Allianz', 'person_label' => 'Carolina', 'expiry_date' => '2026-10-20'], 'Apólice vencendo', 'Apólice Allianz (Carolina) vence em 20/10'],
            [['kind' => 'insurance_policy_anniversary_upcoming', 'title' => 'Icatu', 'years_completing' => 3, 'occurrence_date' => '2026-10-09'], 'Aniversário de apólice', 'Apólice Icatu completa 3 ano(s) em 09/10'],
            [['kind' => 'investment_maturity_upcoming', 'title' => 'CDB Inter 2028', 'maturity_date' => '2026-11-02'], 'Investimento vencendo', 'CDB Inter 2028 vence em 02/11'],
            [['kind' => 'fixed_bill_due_soon', 'title' => 'Internet', 'due_date' => '2026-10-10', 'amount' => '129.90'], 'Conta a vencer', 'Internet vence em 10/10 (R$ 129,90)'],
            [['kind' => 'document_processed', 'title' => 'Fatura_Itau.pdf', 'status' => 'completed'], 'Importação concluída', 'Fatura_Itau.pdf'],
            [['kind' => 'document_processed', 'title' => 'Fatura_Itau.pdf', 'status' => 'failed'], 'Falha na importação', 'Fatura_Itau.pdf'],
        ];

        foreach ($casos as [$dados, $titulo, $mensagem]) {
            $this->gravar($usuario, $dados);
        }

        $tela = Livewire::test(NotificationCenter::class);
        foreach ($casos as [$dados, $titulo, $mensagem]) {
            $tela->assertSee($titulo)->assertSee($mensagem);
        }
    }

    public function test_tipo_desconhecido_cai_no_titulo_gravado(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $this->gravar($usuario, ['title' => 'Algo novo']);

        Livewire::test(NotificationCenter::class)->assertSee('Algo novo');
    }

    public function test_clicar_marca_como_lida_e_abre_a_tela_do_assunto(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $id = $this->gravar($usuario, [
            'kind' => 'client_birthday_upcoming', 'title' => 'Helen Muller', 'occurrence_date' => '2026-10-14', 'turning_age' => 40,
        ]);

        Livewire::test(NotificationCenter::class)
            ->call('abrirNotificacao', $id)
            ->assertRedirect(route('consultant.portfolio.important-dates'));

        self::assertNotNull($usuario->notifications()->find($id)->read_at);
    }

    public function test_clicar_em_notificacao_sem_destino_so_marca_como_lida(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $id = $this->gravar($usuario, ['title' => 'Sem link']);

        Livewire::test(NotificationCenter::class)->call('abrirNotificacao', $id)->assertNoRedirect();

        self::assertNotNull($usuario->notifications()->find($id)->read_at);
    }

    /**
     * Regressão: o painel do sino tem `x-data="{ open: false }"`. Um
     * wire:click="open(...)" resolve `open` nesse escopo do Alpine (um
     * booleano) antes de chegar no método do Livewire e quebra em silêncio
     * com "open is not a function" — o clique não faz nada e nenhum teste de
     * servidor percebe. O método precisa ter outro nome.
     */
    public function test_acao_do_clique_nao_colide_com_a_variavel_open_do_alpine(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $this->gravar($usuario, ['kind' => 'fixed_bill_due_soon', 'title' => 'Internet', 'due_date' => '2026-10-10']);

        $html = Livewire::test(NotificationCenter::class)->html();

        self::assertStringContainsString('wire:click="abrirNotificacao(', $html);
        self::assertStringNotContainsString('wire:click="open(', $html);
    }

    public function test_nao_abre_notificacao_de_outra_pessoa(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $outra = User::factory()->create();
        $id = $this->gravar($outra, ['kind' => 'fixed_bill_due_soon', 'title' => 'Internet', 'due_date' => '2026-10-10']);

        Livewire::test(NotificationCenter::class)->call('abrirNotificacao', $id)->assertNoRedirect();

        self::assertNull($outra->notifications()->find($id)->read_at);
    }

    private function criarUsuarioComPerfil(): User
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        return $usuario;
    }

    private function criarDocumento(User $usuario, ProcessingStatus $status): DocumentUpload
    {
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);

        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $usuario->id,
            'member_id' => $membro->id,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'documento.pdf',
            'storage_path' => 'documentos/'.$perfil->id.'/documento.pdf',
            'size_bytes' => 1024,
            'processing_status' => $status,
        ]);
    }
}
