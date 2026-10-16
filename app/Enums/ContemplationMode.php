<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ContemplationMode: string
{
    use HasOptions;

    case Draw = 'draw';
    case FreeBid = 'free_bid';
    case FixedBid = 'fixed_bid';
    case EmbeddedBid = 'embedded_bid';

    public function label(): string
    {
        return match ($this) {
            self::Draw => 'Sorteio',
            self::FreeBid => 'Lance livre',
            self::FixedBid => 'Lance fixo',
            self::EmbeddedBid => 'Lance embutido',
        };
    }

    public function hasBid(): bool
    {
        return $this !== self::Draw;
    }
}
