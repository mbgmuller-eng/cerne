<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dados fiscais de quem paga (ver a migration): base da nota fiscal de serviço.
 */
#[Fillable([
    'user_id', 'full_name', 'document', 'birth_date', 'postal_code', 'street', 'number',
    'complement', 'neighborhood', 'city', 'state',
])]
class BillingDetail extends Model
{
    use HasUuids;

    /** Siglas das 27 unidades da federação. */
    public const STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompany(): bool
    {
        return strlen($this->document) === 14;
    }

    /**
     * Campos do cliente na Asaas. A cidade e o estado a Asaas deduz do CEP;
     * o bairro vai em `province`.
     *
     * @return array<string, string>
     */
    public function asaasCustomerFields(): array
    {
        return array_filter([
            'name' => $this->full_name,
            'cpfCnpj' => $this->document,
            'postalCode' => $this->postal_code,
            'address' => $this->street,
            'addressNumber' => $this->number,
            'complement' => $this->complement,
            'province' => $this->neighborhood,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}
