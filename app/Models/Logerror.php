<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Logerror extends Model
{
    

    protected $fillable=[
    	"tipo",
        "descripcion",
        "fix",
    	"id_turno",
    	"nro_turno_rto"
    ];

    
}