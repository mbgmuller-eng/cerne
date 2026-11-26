<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O parecer de um revisor sobre uma versão de uma regra. Só vale depois de enviado (`submitted_at`). */
#[Fillable(['reviewer_id', 'rule_key', 'rule_version', 'decision', 'comment', 'wrong_items', 'missing', 'submitted_at'])]
class RuleReview extends Model
{
    use HasUuids;

    public const APPROVED = 'approved';

    public const CHANGES = 'changes';

    protected function casts(): array
    {
        return ['wrong_items' => 'array', 'submitted_at' => 'datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(RuleReviewer::class, 'reviewer_id');
    }
}
