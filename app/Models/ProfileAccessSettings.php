<?php

namespace App\Models;

use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Privacidade granular do casal. Os três atalhos da tela 9
 * (Transparente / Privado / Personalizado) escrevem aqui.
 */
#[Fillable([
    'profile_id',
    'expense_visibility',
    'income_visibility',
    'investment_visibility',
    'bank_account_visibility',
    'credit_card_visibility',
    'insurance_visibility',
    'can_edit_partner_records',
    'updated_by_user_id',
])]
class ProfileAccessSettings extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'profile_access_settings';

    /** Colunas de visibilidade, na ordem em que a tela 9 as apresenta. */
    public const DOMAINS = [
        'expense_visibility' => 'Despesas',
        'income_visibility' => 'Receitas',
        'investment_visibility' => 'Investimentos',
        'bank_account_visibility' => 'Contas bancárias',
        'credit_card_visibility' => 'Cartões de crédito',
        'insurance_visibility' => 'Seguros',
    ];

    protected function casts(): array
    {
        return [
            'expense_visibility' => Visibility::class,
            'income_visibility' => Visibility::class,
            'investment_visibility' => Visibility::class,
            'bank_account_visibility' => Visibility::class,
            'credit_card_visibility' => Visibility::class,
            'insurance_visibility' => Visibility::class,
            'can_edit_partner_records' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    // ---------------------------------------------------------------------
    // Atalhos da tela 9
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function transparentPreset(): array
    {
        return array_merge(
            array_fill_keys(array_keys(self::DOMAINS), Visibility::AllMembers),
            ['can_edit_partner_records' => true],
        );
    }

    /** @return array<string, mixed> */
    public static function privatePreset(): array
    {
        return array_merge(
            array_fill_keys(array_keys(self::DOMAINS), Visibility::OwnOnly),
            ['can_edit_partner_records' => false],
        );
    }

    /** Qual dos três atalhos descreve a configuração atual. */
    public function preset(): string
    {
        $values = array_map(
            fn (string $domain) => $this->{$domain},
            array_keys(self::DOMAINS),
        );

        $allShared = ! in_array(Visibility::OwnOnly, $values, true);
        $allPrivate = ! in_array(Visibility::AllMembers, $values, true);

        return match (true) {
            $allShared && $this->can_edit_partner_records => 'transparent',
            $allPrivate && ! $this->can_edit_partner_records => 'private',
            default => 'custom',
        };
    }

    /** O membro secundário enxerga tudo neste domínio? */
    public function sharesDomain(string $domain): bool
    {
        return $this->{$domain} === Visibility::AllMembers;
    }
}
