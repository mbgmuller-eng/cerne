<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOrShared;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banco pro autocompletar de conta/cartão (ver AccountsIndex).
 *
 * Usa BelongsToProfileOrShared, mesmo padrão de ExpenseCategory:
 * profile_id nulo é um banco aprovado, visível a todo mundo; preenchido é
 * uma sugestão de um cliente específico, só ele vê até um admin aprovar
 * (ver App\Livewire\Admin\AdminBanks). O cliente nunca percebe a
 * diferença — digitar um banco novo já funcionava antes (bank_name em
 * bank_accounts/credit_cards sempre foi texto livre), isto só dá cor de
 * marca automática pros aprovados e um caminho de virar oficial pra todo
 * mundo sem precisar de deploy.
 */
#[Fillable(['profile_id', 'name', 'color_hex', 'dismissed_at'])]
class Bank extends Model
{
    use BelongsToProfileOrShared, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    /** Apelidos comuns que apontam pro nome oficial de um banco aprovado. */
    private const ALIASES = [
        'itau' => 'Itaú',
        'itau unibanco' => 'Itaú',
        'caixa' => 'Caixa Econômica Federal',
        'cef' => 'Caixa Econômica Federal',
        'inter' => 'Banco Inter',
        'pagseguro' => 'PagBank',
        'safra' => 'Banco Safra',
        'votorantim' => 'Banco BV',
        'bv' => 'Banco BV',
        'sofisa' => 'Banco Sofisa',
        'modalmais' => 'Banco Modal',
        'modal mais' => 'Banco Modal',
    ];

    /**
     * Acha o banco visível (aprovado ou sugestão minha de antes) que bate
     * com o nome digitado — ignora acento/maiúscula e apelido comum, pra
     * "itau" e "Itaú" não virarem duas sugestões diferentes.
     */
    public static function match(string $name): ?self
    {
        $chave = self::normalize($name);
        $canonico = self::ALIASES[$chave] ?? null;

        return self::all()->first(
            fn (self $b) => self::normalize($b->name) === $chave || ($canonico !== null && $b->name === $canonico)
        );
    }

    /** Cor de marca do banco que bate com o nome digitado, se houver. */
    public static function colorFor(string $name): ?string
    {
        return self::match($name)?->color_hex;
    }

    /** Nomes pro <datalist> do formulário, em ordem alfabética. */
    public static function names(): array
    {
        return self::query()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Garante que o nome digitado existe como banco — usa o já visível se
     * bater (aprovado ou sugestão minha de antes), ou cria uma sugestão
     * nova vinculada ao perfil ativo. Chamado ao salvar conta/cartão,
     * nunca bloqueia o cadastro.
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
     * Promove a sugestão a banco oficial (profile_id vira nulo) e apaga
     * qualquer outra sugestão com o mesmo nome vinda de outro cliente —
     * bank_name em conta/cartão é texto solto, não aponta pro id daqui,
     * então isso nunca afeta uma conta já cadastrada.
     */
    public function approve(?string $colorHex = null): void
    {
        static::withoutTaxonomyScope()
            ->whereNotNull('profile_id')
            ->where('id', '!=', $this->id)
            ->get()
            ->filter(fn (self $b) => self::normalize($b->name) === self::normalize($this->name))
            ->each->delete();

        $this->update([
            'profile_id' => null,
            'color_hex' => $colorHex ?: ($this->color_hex ?? '#64748B'),
        ]);
    }

    /** Tira da fila de sugestões pendentes sem apagar — a conta que já usa continua igual. */
    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNotNull('profile_id')->whereNull('dismissed_at');
    }

    private static function normalize(string $name): string
    {
        $semAcento = strtr(mb_strtolower(trim($name)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return $semAcento;
    }
}
