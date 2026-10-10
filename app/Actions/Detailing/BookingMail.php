<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Domain\Detailing\SlotGrid;
use App\Models\Branch;
use App\Models\DetailingBooking;
use App\Notifications\DetailingBookingNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a booking's customer, when the booking carries an email address:
 * "received" for a booking made from the public page, "confirmed" when staff
 * confirm it. The notification is queued after commit.
 */
final class BookingMail
{
    public function send(DetailingBooking $booking, string $kind, ?string $token = null): void
    {
        $email = $booking->customer_email;
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $branch = Branch::query()->findOrFail($booking->branch_id);
        $frontend = rtrim(config()->string('app.frontend_url'), '/');

        Notification::route('mail', $email)->notify(new DetailingBookingNotification($kind, [
            'reference' => $booking->reference,
            'customer_name' => $booking->customer_name,
            'package' => $booking->package_name,
            'size' => $booking->size_class->label(),
            'date' => $booking->scheduled_date->format('l, j F Y'),
            'time' => SlotGrid::label(SlotGrid::nearest($booking->start_minutes)),
            'vehicle' => $booking->vehicle_label,
            'total' => '₱'.number_format(intdiv($booking->package_price_cents, 100)).'.'.str_pad((string) ($booking->package_price_cents % 100), 2, '0', STR_PAD_LEFT),
            'shop' => $branch->brand_name ?? $branch->name,
            'shop_phone' => $branch->contact_phone,
            'url' => $token === null || $frontend === '' ? null : "{$frontend}/book/{$branch->slug}/confirmation/{$token}",
        ]));
    }
}
