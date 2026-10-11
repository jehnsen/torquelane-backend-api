<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A CRM message as an email: the rendered template, as plain paragraphs with its links clickable. */
final class CrmMessageMail extends Mailable
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $subjectLine,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $escaped = e($this->body);
        $linked = (string) preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $escaped);

        return new Content(htmlString: '<div style="font-family: sans-serif; font-size: 15px; line-height: 1.5">'.nl2br($linked).'</div>');
    }
}
