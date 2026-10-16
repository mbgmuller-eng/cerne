<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A carteira de uma corretora num mês: patrimônio de abertura e fechamento, movimentações, impostos, ganho, rentabilidade e CDI.
 * Vem do relatório de performance da corretora e alimenta os gráficos de evolução do patrimônio e de rentabilidade contra o CDI.
 *
 * Os saldos são opcionais (o relatório só traz os últimos 12 meses); a rentabilidade vai mais longe. Privacidade como a dos
 * investimentos: do dono, visível ao casal, a não ser que marcado como privado.
 */
#[Fillable([
    'profile_id', 'member_id', 'institution', 'year', 'month', 'opening_balance', 'movements', 'taxes', 'closing_balance',
    'gain', 'return_percent', 'cdi_percent', 'is_private', 'source_document_id',
])]
class InvestmentPortfolioMonth extends Model
{
    use BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'opening_balance' => 'decimal:2',
            'movements' => 'decimal:2',
            'taxes' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'gain' => 'decimal:2',
            'return_percent' => 'decimal:4',
            'cdi_percent' => 'decimal:4',
            'is_private' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    /** Percentual do CDI que a carteira rendeu no mês (110 = 110% do CDI). Nulo sem CDI ou com CDI zero. */
    public function percentOfCdi(): ?string
    {
        if ($this->return_percent === null || $this->cdi_percent === null || bccomp((string) $this->cdi_percent, '0', 4) === 0) {
            return null;
        }

        return bcmul(bcdiv((string) $this->return_percent, (string) $this->cdi_percent, 6), '100', 2);
    }
}
