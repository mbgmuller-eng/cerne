<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank substitui a antiga App\Support\KnownBanks (constante PHP) — a
 * lista de bancos aprovados agora vem do banco de dados (seedada na
 * migration), pra dar um caminho de "virar oficial" sem deploy. Mesmo
 * padrão de ExpenseCategory (BelongsToProfileOrShared).
 */
class BankTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconhece_nome_exato_de_banco_aprovado(): void
    {
        self::assertSame('#EC7000', Bank::colorFor('Itaú'));
    }

    public function test_reconhece_apelido_sem_acento_e_maiuscula(): void
    {
        self::assertSame('#EC7000', Bank::colorFor('itau'));
        self::assertSame('#EC7000', Bank::colorFor('ITAÚ'));
    }

    public function test_reconhece_apelido_mapeado(): void
    {
        self::assertSame('#0033A0', Bank::colorFor('caixa'));
        self::assertSame('#FF7A00', Bank::colorFor('Inter'));
    }

    public function test_banco_desconhecido_devolve_nulo(): void
    {
        self::assertNull(Bank::colorFor('Banco da Esquina Ltda'));
    }

    public function test_digitar_banco_desconhecido_cria_sugestao_privada_do_perfil(): void
    {
        $perfil = $this->criarPerfilAtivo();

        $banco = Bank::resolveOrSuggest('Cooperativa do Vale');

        self::assertSame($perfil->id, $banco->profile_id);
        self::assertSame('Cooperativa do Vale', $banco->name);
        self::assertNull($banco->color_hex);
    }

    public function test_sugerir_o_mesmo_nome_duas_vezes_nao_duplica(): void
    {
        $this->criarPerfilAtivo();

        $primeiro = Bank::resolveOrSuggest('Cooperativa do Vale');
        $segundo = Bank::resolveOrSuggest('cooperativa do vale');

        self::assertSame($primeiro->id, $segundo->id);
        self::assertSame(1, Bank::query()->where('name', 'Cooperativa do Vale')->count());
    }

    public function test_sugestao_de_um_perfil_nao_aparece_pra_outro(): void
    {
        $this->criarPerfilAtivo();
        Bank::resolveOrSuggest('Cooperativa do Vale');

        $outroPerfil = $this->criarPerfilAtivo();

        self::assertNull(Bank::match('Cooperativa do Vale'));

        // o outro perfil também consegue sugerir o mesmo nome — cada um
        // fica com a própria linha, sem saber da sugestão do outro.
        $bancoDoOutro = Bank::resolveOrSuggest('Cooperativa do Vale');
        self::assertSame($outroPerfil->id, $bancoDoOutro->profile_id);
        self::assertSame(2, Bank::withoutTaxonomyScope()->where('name', 'Cooperativa do Vale')->count());
    }

    public function test_aprovar_torna_visivel_a_todo_mundo_e_apaga_sugestoes_duplicadas(): void
    {
        $this->criarPerfilAtivo();
        $primeira = Bank::resolveOrSuggest('Cooperativa do Vale');

        $this->criarPerfilAtivo();
        Bank::resolveOrSuggest('Cooperativa do Vale');

        self::assertSame(2, Bank::withoutTaxonomyScope()->where('name', 'Cooperativa do Vale')->count());

        $primeira->approve('#334455');

        self::assertSame(1, Bank::withoutTaxonomyScope()->where('name', 'Cooperativa do Vale')->count());

        $primeira->refresh();
        self::assertNull($primeira->profile_id);
        self::assertSame('#334455', $primeira->color_hex);
        self::assertSame('#334455', Bank::colorFor('Cooperativa do Vale'));
    }

    public function test_aprovar_sem_cor_usa_um_cinza_neutro(): void
    {
        $this->criarPerfilAtivo();
        $banco = Bank::resolveOrSuggest('Cooperativa do Vale');

        $banco->approve();

        self::assertSame('#64748B', $banco->fresh()->color_hex);
    }

    public function test_dispensar_tira_da_fila_sem_apagar(): void
    {
        $this->criarPerfilAtivo();
        $banco = Bank::resolveOrSuggest('Cooperativa do Vale');

        self::assertTrue(Bank::withoutTaxonomyScope()->pending()->whereKey($banco->id)->exists());

        $banco->dismiss();

        self::assertFalse(Bank::withoutTaxonomyScope()->pending()->whereKey($banco->id)->exists());
        self::assertNotNull($banco->fresh());
    }

    private function criarPerfilAtivo(): FinancialProfile
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        return $perfil;
    }
}
