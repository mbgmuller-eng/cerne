<?php

namespace App\Models;

use App\Enums\ConsultantClientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultant_id', 'client_id', 'status', 'invited_at', 'accepted_at', 'notes'])]
class ConsultantClient extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => ConsultantClientStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConsultantClientStatus::Active);
    }
}
