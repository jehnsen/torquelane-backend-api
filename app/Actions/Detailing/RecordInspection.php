<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Actions\Documents\ManageDocuments;
use App\Actions\Fleet\RecordReading;
use App\Domain\Documents\DocumentKind;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\DetailingDamagePoint;
use App\Models\DetailingInspection;
use App\Models\DetailingInspectionPhoto;
use App\Models\Document;
use App\Models\MeterReading;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A vehicle's inspection on a booking: the source's condition log (ratings,
 * condition, mileage, notes) with a damage map and photos. One per stage
 * (check-in, hand-back); saving a stage again replaces it and its map.
 *
 * The mileage is an odometer reading, so it is recorded as one (Phase 2's
 * rules apply: an implausible reading is refused until confirmed) and the
 * inspection points at it. A photo is a document on the private disk, filed
 * under the booking's account.
 */
final class RecordInspection
{
    public const int MAX_DAMAGE_POINTS = 60;

    public const int MAX_PHOTOS = 24;

    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly AuditTrail $audit,
        private readonly RecordReading $readings,
        private readonly ManageDocuments $documents,
    ) {}

    /**
     * @param  array{
     *     overall_rating: int, exterior_rating?: int|null, interior_rating?: int|null,
     *     exterior_condition?: string|null, interior_condition?: string|null,
     *     mileage?: int|null, confirm_mileage?: bool, notes?: string|null,
     *     damage_points?: list<array{x_permille: int, y_permille: int, kind?: string|null, note: string}>,
     * }  $data
     */
    public function handle(DetailingBooking $booking, string $stage, array $data): DetailingInspection
    {
        return DB::transaction(function () use ($booking, $stage, $data): DetailingInspection {
            $locked = $this->journal->lockBooking($booking);
            $inspection = DetailingInspection::query()->where('booking_id', $locked->id)->where('stage', $stage)->first();
            $before = $inspection === null ? null : AuditTrail::snapshot($inspection);
            $actor = $this->journal->actor();

            $inspection ??= (new DetailingInspection)->forceFill([
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'booking_id' => $locked->id,
                'customer_account_id' => $locked->customer_account_id,
                'stage' => $stage,
            ]);
            $inspection->forceFill([
                'vehicle_id' => $locked->vehicle_id,
                'overall_rating' => $data['overall_rating'],
                'exterior_rating' => $data['exterior_rating'] ?? null,
                'interior_rating' => $data['interior_rating'] ?? null,
                'exterior_condition' => $data['exterior_condition'] ?? null,
                'interior_condition' => $data['interior_condition'] ?? null,
                'notes' => trim($data['notes'] ?? '') === '' ? null : trim((string) $data['notes']),
                'recorded_by' => $actor?->id,
                'recorded_by_name' => $this->journal->actorName(),
                'recorded_at' => CarbonImmutable::now(),
                'meter_reading_id' => $this->reading($locked, $inspection, $data['mileage'] ?? null, $data['confirm_mileage'] ?? false),
            ])->save();

            if (array_key_exists('damage_points', $data)) {
                DetailingDamagePoint::query()->where('inspection_id', $inspection->id)->delete();
                foreach ($data['damage_points'] as $position => $point) {
                    (new DetailingDamagePoint)->forceFill([
                        'organization_id' => $inspection->organization_id,
                        'inspection_id' => $inspection->id,
                        'x_permille' => $point['x_permille'],
                        'y_permille' => $point['y_permille'],
                        'kind' => $point['kind'] ?? 'other',
                        'note' => trim($point['note']),
                        'position' => $position,
                    ])->save();
                }
            }

            $this->audit->record($inspection, $before === null ? 'recorded' : 'updated', $before, AuditTrail::snapshot($inspection));
            $this->journal->announce($locked, 'inspection');

            return $inspection;
        });
    }

    public function addPhoto(DetailingInspection $inspection, string $phase, UploadedFile $file, ?string $caption): DetailingInspectionPhoto
    {
        if (DetailingInspectionPhoto::query()->where('inspection_id', $inspection->id)->count() >= self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['file' => sprintf('An inspection holds at most %d photos.', self::MAX_PHOTOS)]);
        }
        $booking = DetailingBooking::query()->findOrFail($inspection->booking_id);
        $caption = trim($caption ?? '') === '' ? null : trim((string) $caption);

        // The file is stored (and its document row committed) first, as every upload is.
        $document = $this->documents->upload(
            CustomerAccount::query()->findOrFail($inspection->customer_account_id),
            $inspection->vehicle_id === null ? null : Vehicle::query()->find($inspection->vehicle_id),
            DocumentKind::Photo,
            $file,
            ['name' => sprintf('%s %s photo — %s', $booking->reference, $phase, $file->getClientOriginalName()), 'notes' => $caption],
        );

        return DB::transaction(function () use ($inspection, $document, $phase, $caption): DetailingInspectionPhoto {
            $photo = new DetailingInspectionPhoto;
            $photo->forceFill([
                'organization_id' => $inspection->organization_id,
                'inspection_id' => $inspection->id,
                'customer_account_id' => $inspection->customer_account_id,
                'document_id' => $document->id,
                'phase' => $phase,
                'caption' => $caption,
                'position' => DetailingInspectionPhoto::query()->where('inspection_id', $inspection->id)->count(),
            ])->save();
            $this->audit->record($photo, 'added', null, AuditTrail::snapshot($photo));

            return $photo->setRelation('document', $document);
        });
    }

    public function removePhoto(DetailingInspectionPhoto $photo): void
    {
        DB::transaction(function () use ($photo): void {
            $document = Document::query()->find($photo->document_id);
            $this->audit->record($photo, 'removed', AuditTrail::snapshot($photo), null);
            $photo->delete();
            if ($document instanceof Document) {
                $this->documents->delete($document);
            }
        });
    }

    /** The reading the inspection points at: the one it already has if the mileage is unchanged, else a new one. */
    private function reading(DetailingBooking $booking, DetailingInspection $inspection, ?int $mileage, bool $confirm): ?string
    {
        if ($mileage === null) {
            return $inspection->exists ? $inspection->meter_reading_id : null;
        }
        if ($booking->vehicle_id === null) {
            throw ValidationException::withMessages(['mileage' => 'Link the booking to one of the customer\'s vehicles to record its mileage.']);
        }
        if ($inspection->exists && $inspection->meter_reading_id !== null) {
            $current = MeterReading::query()->find($inspection->meter_reading_id);
            if ($current instanceof MeterReading && $current->value !== null && $current->value->isEqualTo(BigDecimal::of($mileage))) {
                return $current->id;
            }
        }

        return $this->readings->handle(Vehicle::query()->findOrFail($booking->vehicle_id), (string) $mileage, null, $confirm, 'check_in')->id;
    }
}
