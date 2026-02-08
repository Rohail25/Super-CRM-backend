<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BulkEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Email subject
     */
    private string $emailSubject;

    /**
     * Email message content
     */
    private string $emailMessage;

    /**
     * Create a new message instance.
     */
    public function __construct(string $subject, string $message)
    {
        $this->emailSubject = $subject;
        $this->emailMessage = $message;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->emailMessage)),
        );
    }
}
