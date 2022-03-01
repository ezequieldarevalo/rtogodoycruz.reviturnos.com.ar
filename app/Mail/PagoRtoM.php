<?php

namespace App\Mail;

use App\Models\PagoRto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PagoRtoM extends Mailable
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
    public function __construct(PagoRto $turnomail)
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
        return $this->from('turnos@rtogodoycruz.com.ar','RTO Godoy Cruz')
            ->subject('Turno RTO Mendoza')->view('emails.pagoturno');
    }
}
