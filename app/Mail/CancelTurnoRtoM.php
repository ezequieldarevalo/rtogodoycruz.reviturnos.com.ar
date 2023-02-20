<?php

namespace App\Mail;

use App\Models\CancelTurnoRto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CancelTurnoRtoM extends Mailable
{
    use Queueable, SerializesModels;


    /**
     * The order instance.
     *
     * @var \App\Models\Order
     */
    public $turnomail;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(CancelTurnoRto $turnomail)
    {
        $this->turnomail = $turnomail;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Turno RTO Mendoza')->view('emails.cancelturno');
    }
}
