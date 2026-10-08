<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Port of ../web's `DocumentKind` and lib/documents.ts / lib/compliance.ts
 * kind sets. Labels are verbatim: alert bodies and the expiring-documents
 * tile print them.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case ServiceReport = 'service_report';
    case Inspection = 'inspection';
    case LtoRegistration = 'lto_registration';
    case Ctpl = 'ctpl';
    case ComprehensiveInsurance = 'comprehensive_insurance';
    case EmissionTest = 'emission_test';
    case LtfrbFranchise = 'ltfrb_franchise';
    case Warranty = 'warranty';
    case Photo = 'photo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::ServiceReport => 'Service report',
            self::Inspection => 'Inspection',
            self::LtoRegistration => 'LTO registration',
            self::Ctpl => 'CTPL insurance',
            self::ComprehensiveInsurance => 'Comprehensive insurance',
            self::EmissionTest => 'Emission test',
            self::LtfrbFranchise => 'LTFRB franchise',
            self::Warranty => 'Warranty',
            self::Photo => 'Photo',
            self::Other => 'Other',
        };
    }

    /**
     * Kinds that carry a renewal date (`EXPIRING_KINDS`). Only these accept an
     * `expires_on`; on any other kind an expiry is refused as noise.
     */
    public function expires(): bool
    {
        return in_array($this, [
            self::LtoRegistration, self::Ctpl, self::ComprehensiveInsurance,
            self::EmissionTest, self::LtfrbFranchise, self::Warranty,
        ], true);
    }

    /**
     * Roadworthiness kinds (`COMPLIANCE_DOC_KINDS`): an expired one can take a
     * vehicle off the road. Warranty is deliberately not one.
     */
    public function isCompliance(): bool
    {
        return in_array($this, [
            self::LtoRegistration, self::Ctpl, self::ComprehensiveInsurance,
            self::EmissionTest, self::LtfrbFranchise,
        ], true);
    }
}
