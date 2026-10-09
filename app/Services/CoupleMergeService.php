<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Junta dois clientes que hoje têm perfis individuais separados num único perfil de casal.
 *
 * O perfil da PRINCIPAL continua sendo o perfil do casal (mesmo id, mesmo dono, mesmo vínculo com o consultor).
 * O registro de membro da CÔNJUGE é MOVIDO para esse perfil, com o papel de cônjuge, em vez de ser recriado:
 * como todo dado dela aponta para esse `member_id`, nada precisa ser reapontado (lançamentos, contas, apólices,
 * documentos, saúde, treinos, privacidade por lançamento). Só o `profile_id` das demais tabelas muda.
 *
 * Os dois logins continuam existindo e passam a abrir o mesmo perfil. O perfil antigo da cônjuge, já vazio, é
 * apagado, e o vínculo dela com o consultor sai da Carteira (o casal aparece uma vez só, pelo vínculo da principal).
 *
 * Por que tanta recusa: as chaves estrangeiras de perfil e de membro apagam em cascata. Qualquer coisa que a
 * operação não entendeu viraria dado perdido, então a regra é abortar antes de mexer em qualquer linha.
 */
class CoupleMergeService
{
    /** Tabelas cujo conteúdo do perfil antigo não vale a pena mover (nada financeiro): são apagadas junto com ele. */
    private const DESCARTAVEIS = ['partner_invites'];

    /**
     * Confere tudo e descreve o que seria feito, sem gravar nada.
     *
     * @return array{
     *     principal: User, conjuge: User, perfilPrincipal: FinancialProfile, perfilConjuge: FinancialProfile,
     *     membroConjuge: ProfileMember, linhas: array<string, int>, regrasDuplicadas: array<string, int>,
     *     descartadas: array<string, int>, vinculosRemovidos: list<array<string, mixed>>
     * }
     *
     * @throws RuntimeException  quando algo impede a unificação (a mensagem diz o quê)
     */
    public function plan(User $principal, User $conjuge): array
    {
        if ($principal->id === $conjuge->id) {
            throw new RuntimeException('A principal e o cônjuge precisam ser pessoas diferentes.');
        }

        foreach ([$principal, $conjuge] as $usuario) {
            if (! $usuario->isClient()) {
                throw new RuntimeException("{$usuario->email} não é uma conta de cliente.");
            }
        }

        $perfilPrincipal = $this->perfilUnico($principal, 'principal');
        $perfilConjuge = $this->perfilUnico($conjuge, 'cônjuge');

        if ($perfilPrincipal->profile_type !== ProfileType::Single || $perfilConjuge->profile_type !== ProfileType::Single) {
            throw new RuntimeException('Os dois perfis precisam ser individuais. Um deles já é de casal.');
        }

        $membrosPrincipal = ProfileMember::query()->where('profile_id', $perfilPrincipal->id)->get();
        if ($membrosPrincipal->count() !== 1 || $membrosPrincipal->first()->user_id !== $principal->id) {
            throw new RuntimeException('O perfil da principal já tem outro membro além dela.');
        }

        $membrosConjuge = ProfileMember::query()->where('profile_id', $perfilConjuge->id)->get();
        if ($membrosConjuge->count() !== 1 || $membrosConjuge->first()->user_id !== $conjuge->id) {
            throw new RuntimeException('O perfil do cônjuge tem outros membros além dele(a).');
        }
        $membroConjuge = $membrosConjuge->first();

        $outrosPerfis = ProfileMember::query()->where('user_id', $conjuge->id)->where('profile_id', '<>', $perfilConjuge->id)->exists();
        if ($outrosPerfis) {
            throw new RuntimeException('O cônjuge também é membro de outro perfil. Resolva isso antes.');
        }

        if (DB::table('subscriptions')->where('user_id', $conjuge->id)->exists()) {
            throw new RuntimeException('O cônjuge tem assinatura própria. Decida o que fazer com a cobrança antes de unificar.');
        }

        $vinculosRemovidos = $this->vinculosDoConjuge($principal, $conjuge);

        $this->conferirTaxonomia($perfilPrincipal, $perfilConjuge);
        $this->conferirReservas($perfilPrincipal, $perfilConjuge);

        $linhas = [];
        $descartadas = [];
        foreach ($this->tabelasDoPerfil() as $tabela) {
            $total = DB::table($tabela)->where('profile_id', $perfilConjuge->id)->count();

            if ($total === 0) {
                continue;
            }

            in_array($tabela, self::DESCARTAVEIS, true) ? $descartadas[$tabela] = $total : $linhas[$tabela] = $total;
        }

        $regrasDuplicadas = [];
        foreach (['expense_categorization_rules', 'income_categorization_rules'] as $tabela) {
            $n = $this->regrasDuplicadas($tabela, $perfilPrincipal->id, $perfilConjuge->id)->count();
            if ($n > 0) {
                $regrasDuplicadas[$tabela] = $n;
            }
        }

        return compact('principal', 'conjuge', 'perfilPrincipal', 'perfilConjuge', 'membroConjuge', 'linhas', 'regrasDuplicadas', 'descartadas', 'vinculosRemovidos');
    }

    /**
     * Aplica a unificação numa transação só: ou tudo, ou nada.
     *
     * @return array<string, mixed>  o mesmo resumo de plan(), mais `copia` com os registros que serviriam para desfazer
     */
    public function merge(User $principal, User $conjuge): array
    {
        $plano = $this->plan($principal, $conjuge);

        return DB::transaction(function () use ($plano): array {
            /** @var FinancialProfile $novo */
            $novo = $plano['perfilPrincipal'];
            /** @var FinancialProfile $antigo */
            $antigo = $plano['perfilConjuge'];

            $copia = $this->backup($plano);

            // 1. Regras de categorização iguais (mesmo texto): vale a da principal; as da cônjuge saem.
            foreach (['expense_categorization_rules', 'income_categorization_rules'] as $tabela) {
                $ids = $this->regrasDuplicadas($tabela, $novo->id, $antigo->id)->pluck('id')->all();
                if ($ids !== []) {
                    DB::table($tabela)->whereIn('id', $ids)->delete();
                }
            }

            // 2. A cônjuge entra no perfil da principal: mesmo registro de membro, só trocando de perfil e de papel.
            DB::table('profile_members')->where('id', $plano['membroConjuge']->id)->update([
                'profile_id' => $novo->id,
                'role' => MemberRole::Secondary->value,
                'updated_at' => now(),
            ]);

            // 3. Todo o resto do perfil antigo passa para o novo.
            foreach (array_keys($plano['linhas']) as $tabela) {
                DB::table($tabela)->where('profile_id', $antigo->id)->update(['profile_id' => $novo->id]);
            }

            // 4. O perfil agora é de casal.
            $novo->update(['profile_type' => ProfileType::Couple]);

            // 5. A cônjuge deixa de aparecer como cliente à parte na Carteira do consultor.
            DB::table('consultant_clients')->where('client_id', $plano['conjuge']->id)->delete();

            // 6. Nada pode ter sobrado no perfil antigo, a não ser o descartável. Se sobrou, a operação toda é desfeita.
            foreach ($this->tabelasDoPerfil() as $tabela) {
                if (in_array($tabela, self::DESCARTAVEIS, true)) {
                    continue;
                }

                $restante = DB::table($tabela)->where('profile_id', $antigo->id)->count();
                if ($restante > 0) {
                    throw new RuntimeException("Sobraram {$restante} linha(s) de {$tabela} no perfil antigo. Nada foi alterado.");
                }
            }

            $restanteMembros = DB::table('profile_members')->where('profile_id', $antigo->id)->count();
            if ($restanteMembros > 0) {
                throw new RuntimeException('Sobrou um membro no perfil antigo. Nada foi alterado.');
            }

            // 7. Só agora o perfil antigo (vazio) pode ir embora: a exclusão é em cascata.
            DB::table('financial_profiles')->where('id', $antigo->id)->delete();

            \App\Services\DashboardService::forgetProfile($novo->id);
            \App\Services\DashboardService::forgetProfile($antigo->id);

            return $plano + ['copia' => $copia];
        });
    }

    private function perfilUnico(User $usuario, string $papel): FinancialProfile
    {
        $perfis = FinancialProfile::query()->where('owner_user_id', $usuario->id)->get();

        if ($perfis->count() !== 1) {
            throw new RuntimeException("A {$papel} ({$usuario->email}) deveria ter exatamente um perfil, e tem {$perfis->count()}.");
        }

        return $perfis->first();
    }

    /**
     * Os vínculos da cônjuge com consultores. Cada um precisa ter o par do lado da principal, senão o consultor
     * perderia o acesso à cônjuge ao unificar.
     *
     * @return list<array<string, mixed>>
     */
    private function vinculosDoConjuge(User $principal, User $conjuge): array
    {
        $vinculosConjuge = DB::table('consultant_clients')->where('client_id', $conjuge->id)->get();
        $statusDaPrincipal = DB::table('consultant_clients')->where('client_id', $principal->id)->pluck('status', 'consultant_id')->all();

        foreach ($vinculosConjuge as $vinculo) {
            // O vínculo ativo da cônjuge só pode sair se o da principal com o mesmo consultor também estiver ativo.
            $cobertura = $statusDaPrincipal[$vinculo->consultant_id] ?? null;

            if ($cobertura === null || ($vinculo->status === 'active' && $cobertura !== 'active')) {
                throw new RuntimeException('A cônjuge é cliente de um consultor que não acompanha a principal (ou o vínculo da principal não está ativo). Unificar o faria perder o acesso.');
            }
        }

        return $vinculosConjuge->map(fn ($v) => (array) $v)->all();
    }

    /** Categorias próprias com o mesmo nome nos dois perfis exigiriam fundir os lançamentos de cada uma: não é automático. */
    private function conferirTaxonomia(FinancialProfile $principal, FinancialProfile $conjuge): void
    {
        foreach (['expense_categories', 'income_categories'] as $tabela) {
            $nomesPrincipal = DB::table($tabela)->where('profile_id', $principal->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();

            $repetidos = DB::table($tabela)->where('profile_id', $conjuge->id)->pluck('name')
                ->filter(fn ($n) => in_array(mb_strtolower(trim($n)), $nomesPrincipal, true));

            if ($repetidos->isNotEmpty()) {
                throw new RuntimeException("As duas têm categoria própria com o mesmo nome em {$tabela} ({$repetidos->first()}). Ajuste antes de unificar.");
            }
        }
    }

    /** Reserva "do casal" (sem membro) existindo nos dois perfis para o mesmo tipo: a chave única impediria juntar. */
    private function conferirReservas(FinancialProfile $principal, FinancialProfile $conjuge): void
    {
        $tiposPrincipal = DB::table('financial_reserves')->where('profile_id', $principal->id)->whereNull('member_id')->pluck('reserve_type')->all();

        $conflito = DB::table('financial_reserves')->where('profile_id', $conjuge->id)->whereNull('member_id')->whereIn('reserve_type', $tiposPrincipal ?: [''])->exists();

        if ($conflito) {
            throw new RuntimeException('As duas têm a mesma reserva do casal cadastrada. Ajuste antes de unificar.');
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> regras da cônjuge cujo texto já existe na principal */
    private function regrasDuplicadas(string $tabela, string $idPrincipal, string $idConjuge)
    {
        $padroes = DB::table($tabela)->where('profile_id', $idPrincipal)->pluck('pattern')->all();

        return DB::table($tabela)->where('profile_id', $idConjuge)->whereIn('pattern', $padroes ?: [''])->get(['id', 'pattern']);
    }

    /** Todas as tabelas com profile_id, menos profile_members (tratada à parte) e a própria financial_profiles. */
    private function tabelasDoPerfil(): array
    {
        return collect(DB::select(
            "SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'profile_id' ORDER BY table_name"
        ))
            ->pluck('t')
            ->reject(fn (string $t) => in_array($t, ['profile_members', 'financial_profiles'], true) || ! Schema::hasTable($t))
            ->values()
            ->all();
    }

    /**
     * O que seria preciso para desfazer à mão: as linhas do perfil e do membro antigos, os vínculos removidos,
     * as regras repetidas que saem e os ids de tudo que muda de perfil.
     *
     * @param  array<string, mixed>  $plano  o resultado de plan()
     * @return array<string, mixed>
     */
    public function backup(array $plano): array
    {
        $antigo = $plano['perfilConjuge'];

        $movidos = [];
        foreach (array_keys($plano['linhas']) as $tabela) {
            $movidos[$tabela] = DB::table($tabela)->where('profile_id', $antigo->id)->pluck('id')->all();
        }

        $regras = [];
        foreach (['expense_categorization_rules', 'income_categorization_rules'] as $tabela) {
            $ids = $this->regrasDuplicadas($tabela, $plano['perfilPrincipal']->id, $antigo->id)->pluck('id')->all();
            $regras[$tabela] = DB::table($tabela)->whereIn('id', $ids ?: [''])->get()->map(fn ($r) => (array) $r)->all();
        }

        return [
            'perfil_antigo' => (array) DB::table('financial_profiles')->where('id', $antigo->id)->first(),
            'membro_movido' => (array) DB::table('profile_members')->where('id', $plano['membroConjuge']->id)->first(),
            'vinculos_removidos' => $plano['vinculosRemovidos'],
            'regras_removidas' => $regras,
            'descartados' => collect(array_keys($plano['descartadas']))->mapWithKeys(
                fn (string $t) => [$t => DB::table($t)->where('profile_id', $antigo->id)->get()->map(fn ($r) => (array) $r)->all()]
            )->all(),
            'ids_movidos' => $movidos,
            'perfil_novo' => $plano['perfilPrincipal']->id,
        ];
    }
}
