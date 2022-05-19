<?php

namespace App\Mail;

use App\Models\TurnoRto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TurnoRtoM extends Mailable
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
    public function __construct(TurnoRto $turnomail)
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
            ->subject('Turno RTO Mendoza')->view('emails.nuevoturno');
    }
}
