<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\Concerns\NormalizesNames;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Seguradora pro autocompletar de apólice (ver InsuranceIndex), mesmo padrão
 * de Bank: profile_id nulo é uma seguradora aprovada, visível a todos;
 * preenchido é uma sugestão de um cliente, só ele vê até um admin aprovar
 * (ver App\Livewire\Admin\AdminInsurers).
 *
 * A apólice continua guardando o nome em texto (insurance_policies.
 * insurer_name), não um id daqui: as telas do consultor atravessam vários
 * clientes e agrupam por esse texto, e aprovar, renomear ou remover um
 * cadastro nunca pode mexer em apólice já cadastrada. Isto só dá cor de
 * marca pros selos e um caminho de virar oficial sem deploy.
 */
#[Fillable(['profile_id', 'name', 'color_hex', 'dismissed_at'])]
class Insurer extends Model
{
    use BelongsToProfileOrShared, HasUuids, NormalizesNames;

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    /** Chave normalizada de um apelido comum => nome oficial da seguradora aprovada. */
    public const ALIASES = [
        'porto' => 'Porto Seguro',
        'porto seguros' => 'Porto Seguro',
        'sul america' => 'SulAmérica',
        'bradesco' => 'Bradesco Seguros',
        'bradesco seguro' => 'Bradesco Seguros',
        'icatu' => 'Icatu Seguros',
        'tokio' => 'Tokio Marine',
        'tokio marine seguradora' => 'Tokio Marine',
        'liberty' => 'Liberty Seguros',
        'mapfre seguros' => 'Mapfre',
        'zurich seguros' => 'Zurich',
        'sompo seguros' => 'Sompo',
        'hdi seguros' => 'HDI',
        'allianz seguros' => 'Allianz',
        'azos seguros' => 'Azos',
        'metlife seguros' => 'MetLife',
        'prudential do brasil' => 'Prudential',
        'caixa seguros' => 'Caixa Seguradora',
        'bb seguros' => 'BB Seguros',
        'itau' => 'Itaú Seguros',
        'itau seguros' => 'Itaú Seguros',
    ];

    /**
     * Acha a seguradora visível (aprovada ou sugestão minha de antes) que
     * bate com o nome digitado — ignora acento/maiúscula e apelido comum.
     */
    public static function match(string $name): ?self
    {
        $chave = self::normalizeName($name);
        $canonico = self::ALIASES[$chave] ?? null;

        return self::all()->first(
            fn (self $i) => self::normalizeName($i->name) === $chave || ($canonico !== null && $i->name === $canonico)
        );
    }

    /** Cor de marca da seguradora que bate com o nome digitado, se houver. */
    public static function colorFor(string $name): ?string
    {
        return self::match($name)?->color_hex;
    }

    /**
     * Cores de todas as seguradoras visíveis, de uma vez: as telas de seguros
     * pintam um selo por grupo, e match() faria uma consulta por selo. Passar
     * o resultado a colorIn().
     *
     * @return array<string, string> nome normalizado => cor
     */
    public static function colorMap(): array
    {
        return self::query()->get()
            ->filter(fn (self $i) => $i->color_hex !== null)
            ->mapWithKeys(fn (self $i) => [self::normalizeName($i->name) => $i->color_hex])
            ->all();
    }

    /** @param  array<string, string>  $mapa  resultado de colorMap() */
    public static function colorIn(array $mapa, string $name): ?string
    {
        $chave = self::normalizeName($name);
        $canonico = self::ALIASES[$chave] ?? null;

        return $mapa[$chave] ?? ($canonico !== null ? ($mapa[self::normalizeName($canonico)] ?? null) : null);
    }

    /**
     * Devolve o nome oficial de uma seguradora a partir de qualquer grafia ("ICATU", "Icatu", "icatu seguros"),
     * para as telas que juntam vários clientes não tratarem a mesma seguradora como duas. Nome que não bate com
     * nenhuma aprovada nem apelido conhecido volta como veio (só sem espaços nas pontas). Não mexe em dado
     * gravado: a apólice continua com o texto que foi digitado.
     *
     * @return \Closure(string): string
     */
    public static function canonicalizer(): \Closure
    {
        $oficiais = self::query()->whereNull('profile_id')->get()
            ->mapWithKeys(fn (self $i) => [self::normalizeName($i->name) => $i->name])
            ->all();

        return function (string $nome) use ($oficiais): string {
            $chave = self::normalizeName($nome);

            return $oficiais[$chave] ?? self::ALIASES[$chave] ?? trim($nome);
        };
    }

    /** Nomes pro <datalist> do formulário, em ordem alfabética. */
    public static function names(): array
    {
        return self::query()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Garante que o nome digitado existe como seguradora — usa a já visível
     * se bater, ou cria uma sugestão nova vinculada ao perfil ativo. Chamado
     * ao salvar a apólice, nunca bloqueia o cadastro.
     */
    public static function resolveOrSuggest(string $name): self
    {
        $name = trim($name);

        return self::match($name) ?? self::create([
            'profile_id' => app(ProfileContext::class)->profileId(),
            'name' => $name,
        ]);
    }

    /**
     * Promove a sugestão a seguradora oficial (profile_id vira nulo) e apaga
     * qualquer outra sugestão com o mesmo nome vinda de outro cliente —
     * insurer_name na apólice é texto solto, então isso nunca afeta uma
     * apólice já cadastrada.
     */
    public function approve(?string $colorHex = null): void
    {
        static::withoutTaxonomyScope()
            ->whereNotNull('profile_id')
            ->where('id', '!=', $this->id)
            ->get()
            ->filter(fn (self $i) => self::normalizeName($i->name) === self::normalizeName($this->name))
            ->each->delete();

        $this->update([
            'profile_id' => null,
            'color_hex' => $colorHex ?: ($this->color_hex ?? '#64748B'),
        ]);
    }

    /**
     * Cadastro direto pelo admin, já aprovado. Sugestões pendentes com o
     * mesmo nome ficam sem sentido e saem da fila.
     */
    public static function createApproved(string $name, ?string $colorHex): self
    {
        $name = trim($name);

        $seguradora = self::create([
            'profile_id' => null,
            'name' => $name,
            'color_hex' => $colorHex ?: '#64748B',
        ]);

        static::withoutTaxonomyScope()
            ->whereNotNull('profile_id')
            ->get()
            ->filter(fn (self $i) => self::normalizeName($i->name) === self::normalizeName($name))
            ->each->delete();

        return $seguradora;
    }

    /** Já existe uma seguradora APROVADA com esse nome (ignorando acento, caixa e a própria linha)? */
    public static function approvedNameTaken(string $name, ?string $exceptId = null): bool
    {
        $chave = self::normalizeName($name);

        return static::withoutTaxonomyScope()->shared()
            ->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->contains(fn (self $i) => self::normalizeName($i->name) === $chave);
    }

    /** Tira da fila de sugestões pendentes sem apagar — a apólice que já usa continua igual. */
    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNotNull('profile_id')->whereNull('dismissed_at');
    }
}
