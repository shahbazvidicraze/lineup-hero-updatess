<?php

namespace App\Mail;

use App\Models\Payment; // Import Payment model
use App\Models\User;    // Import User model
use App\Models\Team;    // Import Team model
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPaymentReceivedMail extends Mailable // implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Payment $payment;
    public User $user;

    /**
     * Create a new message instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
        // Eager load relationships if not already loaded when $payment is passed
        $this->payment->loadMissing(['user']);
        $this->user = $this->payment->user;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Payment Received on ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin.payment-received',
            with: [
                'payment' => $this->payment,
                'user' => $this->user,
                'appName' => config('app.name'),
                // Format amount for display
                'amountFormatted' => number_format($this->payment->amount / 100, 2),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
