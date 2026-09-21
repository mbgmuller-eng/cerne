<?php

namespace App\Models;

use App\Enums\LeadActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um contato registrado com o lead — ligação, reunião, e-mail, proposta, nota. */
#[Fillable(['lead_id', 'type', 'description', 'occurred_at', 'created_by_user_id'])]
class LeadActivity extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
