<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Logerror extends Model
{
    

    protected $fillable=[
    	"tipo",
        "descripcion",
    	"id_turno",
    	"nro_turno_rto",
        "fechahora"
    ];

    
}