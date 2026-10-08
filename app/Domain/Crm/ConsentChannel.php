<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/** How a consent decision reached us. */
enum ConsentChannel: string
{
    case InPerson = 'in_person';
    case PaperForm = 'paper_form';
    case Email = 'email';
    case Sms = 'sms';
    case Phone = 'phone';
    case Portal = 'portal';
    /** Carried over from a record that predates the ledger (the demo seed). */
    case Import = 'import';
}
