<?php

namespace App\Services\Extraction;

use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reconhece, numa linha de extrato, a transferência entre contas do próprio perfil.
 *
 * Três pistas:
 *  - texto que diz isso ("transferência entre contas", "mesma titularidade");
 *  - o nome de um membro do perfil num PIX, TED ou transferência (dinheiro indo de você para você);
 *  - o par: já existe, em outra conta do perfil, um lançamento de mesmo valor, em sentido oposto, a até 3 dias.
 *
 * Só sugere; a pessoa confirma na revisão (regra 5 do CLAUDE.md). O par só olha o que já foi lançado: o que foi importado
 * antes desta função continua como está.
 */
class OwnTransferMatcher
{
    private const PAIR_DAYS = 3;

    /** @var list<string> nomes dos membros do perfil, normalizados */
    private array $names = [];

    /** @var Collection<int, array{tipo: string, valor: string, data: CarbonImmutable, conta: string, descricao: string}> */
    private Collection $others;

    /**
     * Prepara a comparação para um documento: nomes do perfil e o que já existe nas OUTRAS contas no período.
     *
     * @param  array<int, array<string, mixed>>  $itens
     */
    public function prepare(?string $contaDoExtratoId, array $itens): static
    {
        $this->names = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->pluck('name')
            ->map(fn ($n) => $this->normalize((string) $n))
            ->filter(fn (string $n) => $n !== '')
            ->values()
            ->all();

        $this->others = collect();

        $datas = collect($itens)->map(fn ($item) => $this->date($item['data'] ?? null))->filter();

        if ($datas->isEmpty() || $contaDoExtratoId === null) {
            return $this;
        }

        $de = $datas->min()->subDays(self::PAIR_DAYS)->toDateString();
        $ate = $datas->max()->addDays(self::PAIR_DAYS)->toDateString();
        $contas = BankAccount::query()->where('id', '!=', $contaDoExtratoId)->get()->keyBy('id');

        if ($contas->isEmpty()) {
            return $this;
        }

        $entradas = IncomeRecord::query()->whereIn('bank_account_id', $contas->keys())->whereBetween('received_date', [$de, $ate])->get()
            ->map(fn (IncomeRecord $r) => ['tipo' => 'receita', 'valor' => ltrim((string) $r->amount, '-'), 'data' => CarbonImmutable::instance($r->received_date),
                'conta' => $contas[$r->bank_account_id]->displayName(), 'descricao' => (string) $r->description]);

        $saidas = ExpenseRecord::query()->whereIn('bank_account_id', $contas->keys())->where('is_refund', false)->whereBetween('expense_date', [$de, $ate])->get()
            ->map(fn (ExpenseRecord $r) => ['tipo' => 'despesa', 'valor' => ltrim((string) $r->amount, '-'), 'data' => CarbonImmutable::instance($r->expense_date),
                'conta' => $contas[$r->bank_account_id]->displayName(), 'descricao' => (string) $r->description]);

        $this->others = $entradas->concat($saidas)->values();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{selecionar: bool, sugerir: bool, motivo: ?string}
     *   selecionar: evidência suficiente para a linha já vir marcada como transferência; sugerir: há uma pista, mas a pessoa
     *   decide (a caixa aparece); motivo: o que foi reconhecido, em português.
     */
    public function match(array $item): array
    {
        $descricao = $this->normalize((string) ($item['descricao'] ?? ''));
        $data = $this->date($item['data'] ?? null);
        $valor = ltrim(Money::parse($item['valor'] ?? 0), '-');
        $ehReceita = ($item['tipo'] ?? null) === 'receita';

        $texto = (bool) preg_match('/transferencia\s+(entre\s+)?(minhas\s+)?contas|transf\w*\s+propria|mesma\s+titularidade|entre\s+contas\s+(proprias|minhas)/u', $descricao)
            || $this->normalize((string) ($item['categoria_origem'] ?? '')) === 'transferencia';

        $viaTransferencia = (bool) preg_match('/\b(pix|ted|doc|transf\w*|trf)\b/u', $descricao);
        $nome = $viaTransferencia && $this->mentionsOwnName($descricao);
        $par = $data === null ? null : $this->pair($ehReceita, $valor, $data);

        $motivo = match (true) {
            $texto => 'A descrição diz que é transferência entre contas.',
            $nome && $par !== null => 'Tem o seu nome, e existe '.($ehReceita ? 'uma saída' : 'uma entrada').' igual na conta '.$par['conta'].' em '.$par['data']->format('d/m').'.',
            $nome => 'Tem o seu nome na descrição de um PIX ou TED.',
            $par !== null => 'Existe '.($ehReceita ? 'uma saída' : 'uma entrada').' de mesmo valor na conta '.$par['conta'].' em '.$par['data']->format('d/m').' ("'.Str::limit($par['descricao'], 40).'").',
            default => null,
        };

        return [
            'selecionar' => $texto || ($nome && ($par !== null || $viaTransferencia)),
            'sugerir' => $motivo !== null,
            'motivo' => $motivo,
        ];
    }

    private function mentionsOwnName(string $descricao): bool
    {
        foreach ($this->names as $nome) {
            $partes = array_values(array_filter(explode(' ', $nome), fn (string $p) => mb_strlen($p) >= 3));

            if (count($partes) < 2) {
                continue;
            }

            // Primeiro e último nome como palavras inteiras: o extrato costuma abreviar o meio ("MARCELO B G MULLER").
            if (preg_match('/\b'.preg_quote($partes[0], '/').'\b/u', $descricao) && preg_match('/\b'.preg_quote(end($partes), '/').'\b/u', $descricao)) {
                return true;
            }
        }

        return false;
    }

    /** @return ?array{conta: string, data: CarbonImmutable, descricao: string} */
    private function pair(bool $ehReceita, string $valor, CarbonImmutable $data): ?array
    {
        $achado = $this->others
            ->filter(fn (array $o) => $o['tipo'] === ($ehReceita ? 'despesa' : 'receita')
                && bccomp($o['valor'], $valor, 2) === 0
                && abs($o['data']->startOfDay()->diffInDays($data->startOfDay())) <= self::PAIR_DAYS)
            ->sortBy(fn (array $o) => abs($o['data']->startOfDay()->diffInDays($data->startOfDay())))
            ->first();

        return $achado === null ? null : ['conta' => $achado['conta'], 'data' => $achado['data'], 'descricao' => $achado['descricao']];
    }

    private function normalize(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texto))) ?? '');
    }

    private function date(mixed $valor): ?CarbonImmutable
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
