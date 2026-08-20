<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\HasCompetence;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'category_id', 'description', 'amount', 'received_date',
    'bank_account_id', 'is_recurring', 'notes', 'source_document_id', 'created_by_user_id',
])]
class IncomeRecord extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasCompetence, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'income_visibility';

    protected static string $competenceDate = 'received_date';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_date' => 'date',
            'year' => 'integer',
            'month' => 'integer',
            'is_recurring' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'category_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
