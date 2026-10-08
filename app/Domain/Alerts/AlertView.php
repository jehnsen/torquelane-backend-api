<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/** Port of ../web's `AlertView`. */
final readonly class AlertView
{
    /**
     * @param  list<Alert>  $all
     * @param  list<Alert>  $visible  everything not dismissed
     * @param  list<Alert>  $unread
     */
    public function __construct(
        public array $all,
        public array $visible,
        public array $unread,
        public int $unreadCount,
        /** Dismissals that still match a live alert only. */
        public int $dismissedCount,
    ) {}
}
