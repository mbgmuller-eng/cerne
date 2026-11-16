<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Livewire\Goals\GoalsIndex;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\Privacy\UserDataExporter;
use App\Support\ProfileContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Objetivo ligado a MAIS DE UM investimento: o que ele tem é a soma dos saldos, a tela marca vários, e a ligação respeita perfil e privacidade
 * (um investimento privado do cônjuge não entra na soma de quem não o enxerga, e editar o objetivo não apaga a ligação que a pessoa nem vê).
 */
class GoalInvestmentsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    private User $usuarioAna;

    private User $usuarioBruno;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');

        $this->usuarioAna = User::factory()->create();
        $this->usuarioBruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);
        $this->como($this->usuarioAna, $this->ana);
    }

    private function como(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function investimento(string $nome, string $saldo, array $campos = []): InvestmentRecord
    {
        return InvestmentRecord::factory()->for($this->perfil, 'profile')->create(array_merge([
            'name' => $nome, 'current_amount' => $saldo, 'invested_amount' => $saldo, 'member_id' => $this->ana->id, 'is_private' => false,
        ], $campos));
    }

    private function objetivo(array $campos = []): Goal
    {
        return Goal::create(array_merge([
            'name' => 'Casa', 'priority' => 1, 'estimated_value' => '100000.00', 'current_amount' => '0.00', 'funding_method' => FundingMethod::LumpSum,
            'status' => GoalStatus::Active, 'created_by_user_id' => $this->usuarioAna->id,
        ], $campos));
    }

    private function ligados(Goal $g): array
    {
        $ids = DB::table('goal_investment')->where('goal_id', $g->id)->pluck('investment_id')->all();
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------ a soma

    public function test_o_objetivo_ligado_a_varios_investimentos_soma_os_saldos(): void
    {
        $a = $this->investimento('CDB', '30000.00');
        $b = $this->investimento('Tesouro', '10000.50');
        $g = $this->objetivo();
        $g->investments()->attach([$a->id, $b->id]);

        $g = Goal::query()->findOrFail($g->id);

        self::assertTrue($g->hasInvestmentLinks());
        self::assertSame('40000.50', $g->accumulated());
        self::assertSame(40.0, round($g->progressPercentage()));
        self::assertFalse($g->acceptsContributions(), 'quem avança por investimento não tem histórico de aportes');
    }

    public function test_a_lista_nao_faz_uma_consulta_por_objetivo(): void
    {
        foreach (range(1, 5) as $i) {
            $g = $this->objetivo(['name' => "O{$i}", 'priority' => $i]);
            $g->investments()->attach([$this->investimento("A{$i}", '100.00')->id, $this->investimento("B{$i}", '200.00')->id]);
        }

        DB::enableQueryLog();
        $objetivos = Goal::query()->active()->withContributionTotal()->withInvestmentCount()->with('investments')->get();
        $objetivos->each(fn (Goal $g) => [$g->accumulated(), $g->acceptsContributions()]);
        $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'goal_investment') || str_contains($q['query'], 'investment_records'))->count();
        DB::disableQueryLog();

        self::assertSame('300.00', $objetivos->first()->accumulated());
        self::assertLessThanOrEqual(2, $consultas, 'uma consulta traz as ligações e outra a contagem, para todos os objetivos');
    }

    // ------------------------------------------------------------------ o formulário

    public function test_salva_com_varios_investimentos_e_o_aviso_diz_que_e_a_soma(): void
    {
        $a = $this->investimento('CDB Reserva', '30000.00');
        $b = $this->investimento('Tesouro Selic', '10000.00');

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')
            ->set('linkedInvestmentIds', [$a->id, $b->id])
            ->assertSee('da soma dos saldos de 2 investimentos')
            ->assertSee('R$ 40.000,00 de R$ 100.000,00 (40,0%)')
            ->call('save')->assertHasNoErrors();

        $g = Goal::query()->where('name', 'Casa')->sole();
        self::assertEqualsCanonicalizing([$a->id, $b->id], $this->ligados($g));
        self::assertSame('0.00', (string) $g->current_amount);
        self::assertSame('40000.00', $g->accumulated());
    }

    public function test_um_so_investimento_continua_com_a_frase_de_antes(): void
    {
        $a = $this->investimento('CDB Reserva', '4000.00');

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Carro')->set('estimatedValue', '10000')->set('linkType', 'investment')->set('linkedInvestmentIds', [$a->id])
            ->assertSee('O avanço vem do saldo de '.$a->displayName().': R$ 4.000,00 de R$ 10.000,00 (40,0%).');
    }

    public function test_ligar_sem_marcar_nenhum_da_erro_com_mensagem_em_portugues(): void
    {
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')->set('linkedInvestmentIds', [])
            ->call('save')->assertHasErrors(['linkedInvestmentIds'])->assertSee('Escolha ao menos um investimento da lista.');

        self::assertSame(0, Goal::query()->count());
    }

    public function test_editar_carrega_os_marcados_e_permite_trocar_a_selecao(): void
    {
        $a = $this->investimento('CDB', '1000.00');
        $b = $this->investimento('Tesouro', '2000.00');
        $c = $this->investimento('Fundo', '3000.00');
        $g = $this->objetivo();
        $g->investments()->attach([$a->id, $b->id]);

        Livewire::test(GoalsIndex::class)
            ->call('edit', $g->id)
            ->assertSet('linkType', 'investment')
            ->assertSet('linkedInvestmentIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$a->id, $b->id])->sort()->values()->all())
            ->set('linkedInvestmentIds', [$b->id, $c->id])
            ->call('save')->assertHasNoErrors();

        self::assertEqualsCanonicalizing([$b->id, $c->id], $this->ligados($g));
        self::assertSame('5000.00', Goal::query()->findOrFail($g->id)->accumulated());
    }

    public function test_voltar_para_dinheiro_informado_desfaz_todas_as_ligacoes(): void
    {
        $g = $this->objetivo();
        $g->investments()->attach([$this->investimento('CDB', '1000.00')->id, $this->investimento('Tesouro', '2000.00')->id]);

        Livewire::test(GoalsIndex::class)->call('edit', $g->id)->set('linkType', '')->set('currentAmount', '700')->call('save')->assertHasNoErrors();

        self::assertSame([], $this->ligados($g));
        $g = Goal::query()->findOrFail($g->id);
        self::assertFalse($g->hasInvestmentLinks());
        self::assertTrue($g->acceptsContributions());
        self::assertSame('700.00', $g->accumulated());
    }

    public function test_a_lista_do_objetivo_mostra_os_nomes_dos_investimentos(): void
    {
        $g = $this->objetivo();
        $g->investments()->attach([$this->investimento('CDB Casa', '1000.00')->id, $this->investimento('Tesouro Casa', '2000.00')->id]);

        Livewire::test(GoalsIndex::class)->assertSee('ligado a')->assertSee('CDB Casa')->assertSee('Tesouro Casa');
    }

    // ------------------------------------------------------------------ id vindo do navegador

    public function test_investimento_de_outro_perfil_ou_inventado_e_recusado(): void
    {
        $outro = FinancialProfile::factory()->create();
        $alheio = InvestmentRecord::factory()->for($outro, 'profile')->create(['name' => 'Alheio', 'current_amount' => '999999.00']);
        $meu = $this->investimento('Meu', '1000.00');

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')
            ->set('linkedInvestmentIds', [$meu->id, $alheio->id])
            ->call('save')->assertHasErrors(['linkedInvestmentIds'])->assertSee('Escolha só investimentos da lista.');

        self::assertSame(0, Goal::query()->count());
        self::assertSame(0, DB::table('goal_investment')->count());

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')
            ->set('linkedInvestmentIds', ['nao-existe'])
            ->call('save')->assertHasErrors(['linkedInvestmentIds']);
    }

    public function test_investimento_encerrado_nao_se_liga_mas_o_ja_ligado_continua_na_lista_e_no_salvar(): void
    {
        $ativo = $this->investimento('Ativo', '1000.00');
        $encerrado = $this->investimento('Encerrado', '500.00', ['is_active' => false]);

        // Novo: o encerrado não é aceito.
        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')
            ->set('linkedInvestmentIds', [$encerrado->id])
            ->call('save')->assertHasErrors(['linkedInvestmentIds']);

        // Já ligado: aparece marcado ao editar e o salvar o mantém.
        $g = $this->objetivo();
        $g->investments()->attach([$ativo->id, $encerrado->id]);

        Livewire::test(GoalsIndex::class)->call('edit', $g->id)->assertSee('Encerrado')->set('name', 'Casa nova')->call('save')->assertHasNoErrors();

        self::assertEqualsCanonicalizing([$ativo->id, $encerrado->id], $this->ligados($g));
    }

    public function test_o_mesmo_id_repetido_na_selecao_liga_uma_vez_so(): void
    {
        $a = $this->investimento('CDB', '1000.00');

        Livewire::test(GoalsIndex::class)->call('openForm')
            ->set('name', 'Casa')->set('estimatedValue', '100000')->set('linkType', 'investment')
            ->set('linkedInvestmentIds', [$a->id, $a->id])
            ->call('save')->assertHasNoErrors();

        self::assertSame(1, DB::table('goal_investment')->count());
    }

    public function test_a_mesma_ligacao_nunca_existe_duas_vezes_por_indice_unico(): void
    {
        $a = $this->investimento('CDB', '1000.00');
        $g = $this->objetivo();
        DB::table('goal_investment')->insert(['goal_id' => $g->id, 'investment_id' => $a->id]);

        $this->expectException(QueryException::class);
        DB::table('goal_investment')->insert(['goal_id' => $g->id, 'investment_id' => $a->id]);
    }

    // ------------------------------------------------------------------ privacidade do casal

    public function test_investimento_privado_do_conjuge_nao_entra_na_soma_de_quem_nao_o_enxerga(): void
    {
        $compartilhado = $this->investimento('Conjunto', '10000.00');
        $privado = $this->investimento('Só da Ana', '5000.00', ['member_id' => $this->ana->id, 'is_private' => true]);
        $g = $this->objetivo(['name' => 'Casa do casal']);
        $g->investments()->attach([$compartilhado->id, $privado->id]);

        $this->como($this->usuarioAna, $this->ana);
        self::assertSame('15000.00', Goal::query()->findOrFail($g->id)->accumulated(), 'a Ana vê os dois');

        $this->como($this->usuarioBruno, $this->bruno);
        $paraBruno = Goal::query()->findOrFail($g->id);
        self::assertSame('10000.00', $paraBruno->accumulated(), 'o Bruno não soma o que não enxerga: o total não entrega o privado por subtração');
        self::assertTrue($paraBruno->hasInvestmentLinks());
        self::assertFalse($paraBruno->acceptsContributions());
    }

    public function test_quem_edita_sem_enxergar_um_investimento_nao_apaga_a_ligacao_dele(): void
    {
        $compartilhado = $this->investimento('Conjunto', '10000.00');
        $privado = $this->investimento('Só da Ana', '5000.00', ['member_id' => $this->ana->id, 'is_private' => true]);
        $novo = $this->investimento('Novo conjunto', '2000.00');
        $g = $this->objetivo(['name' => 'Casa do casal']);
        $g->investments()->attach([$compartilhado->id, $privado->id]);

        $this->como($this->usuarioBruno, $this->bruno);

        Livewire::test(GoalsIndex::class)
            ->call('edit', $g->id)
            ->assertSet('linkedInvestmentIds', [$compartilhado->id])
            ->set('linkedInvestmentIds', [$novo->id])
            ->call('save')->assertHasNoErrors();

        // O Bruno trocou o que ENXERGA (conjunto -> novo); a ligação com o privado da Ana segue intacta.
        self::assertEqualsCanonicalizing([$novo->id, $privado->id], $this->ligados($g));
    }

    // ------------------------------------------------------------------ exclusão e exportação

    public function test_excluir_o_objetivo_ou_o_investimento_leva_a_ligacao_junto(): void
    {
        $a = $this->investimento('CDB', '1000.00');
        $b = $this->investimento('Tesouro', '2000.00');
        $g1 = $this->objetivo(['name' => 'Um']);
        $g2 = $this->objetivo(['name' => 'Dois', 'priority' => 2]);
        $g1->investments()->attach([$a->id, $b->id]);
        $g2->investments()->attach([$a->id]);

        $g1->delete();
        self::assertSame([$a->id], $this->ligados($g2), 'a ligação do outro objetivo não é tocada');
        self::assertSame(0, DB::table('goal_investment')->where('goal_id', $g1->id)->count());

        $a->delete();
        self::assertSame([], $this->ligados($g2));
        self::assertNotNull(Goal::query()->find($g2->id), 'apagar o investimento não apaga o objetivo');
    }

    public function test_a_copia_de_dados_leva_so_as_ligacoes_dos_objetivos_que_a_pessoa_enxerga(): void
    {
        Storage::fake('local');
        $a = $this->investimento('CDB', '1000.00');
        $visivel = $this->objetivo(['name' => 'Visível para o Bruno']);
        $visivel->investments()->attach([$a->id]);
        $privado = $this->objetivo(['name' => 'Segredo da Ana', 'priority' => 2, 'member_id' => $this->ana->id, 'is_private' => true]);
        $privado->investments()->attach([$a->id]);

        $pedido = app(UserDataExporter::class)->export($this->usuarioBruno);

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($pedido->path));
        $conteudo = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), 'objetivos-investimentos.json')) {
                $conteudo = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        self::assertStringContainsString($visivel->id, $conteudo);
        self::assertStringNotContainsString($privado->id, $conteudo, 'o objetivo privado da Ana não aparece na cópia do Bruno');
    }

    // ------------------------------------------------------------------ vínculos antigos

    public function test_o_vinculo_unico_antigo_foi_copiado_para_a_tabela_na_migracao(): void
    {
        // A migração copia goals.linked_investment_id para goal_investment. Aqui repete a MESMA consulta sobre um objetivo com a coluna antiga preenchida.
        $a = $this->investimento('CDB', '1000.00');
        $g = $this->objetivo();
        DB::table('goals')->where('id', $g->id)->update(['linked_investment_id' => $a->id]);
        DB::table('goal_investment')->delete();

        DB::table('goal_investment')->insertUsing(
            ['goal_id', 'investment_id'],
            DB::table('goals')->whereNotNull('linked_investment_id')->select('id', 'linked_investment_id'),
        );

        self::assertSame([$a->id], $this->ligados($g));
        self::assertSame('1000.00', Goal::query()->findOrFail($g->id)->accumulated());
    }
}
