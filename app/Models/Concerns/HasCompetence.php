<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mantém `year`/`month` em sincronia com a data do lançamento.
 *
 * Os dois campos são desnormalizados de propósito (seção 15 da
 * especificação): os dashboards agregam por competência, e uma função de
 * data no WHERE impediria o uso do índice. O preço disso é o risco de
 * dessincronizar — por isso o preenchimento é automático e o usuário
 * nunca informa esses campos.
 *
 * O model declara de qual coluna a competência deriva:
 *
 *     protected static string $competenceDate = 'expense_date';
 */
trait HasCompetence
{
    public static function bootHasCompetence(): void
    {
        $sync = function (Model $model): void {
            $coluna = static::$competenceDate;
            $data = $model->getAttribute($coluna);

            if ($data === null) {
                return;
            }

            $data = $data instanceof \DateTimeInterface ? $data : \Carbon\CarbonImmutable::parse($data);

            $model->setAttribute('year', (int) $data->format('Y'));
            $model->setAttribute('month', (int) $data->format('n'));
        };

        static::creating($sync);
        static::updating($sync);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where($this->qualifyColumn('year'), $year)
            ->where($this->qualifyColumn('month'), $month);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where($this->qualifyColumn('year'), $year);
    }
}
