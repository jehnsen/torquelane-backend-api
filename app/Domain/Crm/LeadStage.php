<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Where a B2B lead stands. The five open stages may be moved between freely
 * (a deal goes backwards as well as forwards). `won` is reached only by
 * converting the lead into a customer account, and is final. `lost` may be
 * re-opened.
 */
enum LeadStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case SiteVisit = 'site_visit';
    case QuoteSent = 'quote_sent';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::SiteVisit => 'Site visit',
            self::QuoteSent => 'Quote sent',
            self::Negotiating => 'Negotiating',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Won && $this !== self::Lost;
    }

    /** Whether a plain stage change may take a lead from here to `$to` (winning is a conversion, not a move). */
    public function canMoveTo(self $to): bool
    {
        if ($this === $to || $this === self::Won || $to === self::Won) {
            return false;
        }

        return true;
    }

    /**
     * The board's columns, in order.
     *
     * @return list<array{value: string, label: string, open: bool}>
     */
    public static function board(): array
    {
        return array_map(fn (self $stage): array => ['value' => $stage->value, 'label' => $stage->label(), 'open' => $stage->isOpen()], self::cases());
    }
}
