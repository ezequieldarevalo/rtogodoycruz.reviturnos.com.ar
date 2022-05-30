<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Day extends Model
{
    use HasFactory;

    protected $fillable=[
    	"month",
        "lunes_desde",
        "lunes_hasta",
        "martes_desde",
        "martes_hasta",
        "miercoles_desde",
        "miercoles_hasta",
        "jueves_desde",
        "jueves_hasta",
        "viernes_desde",
        "viernes_hasta",
        "sabado_desde",
        "sabado_hasta",
        "domingo_desde",
        "domingo_hasta"
    ];

}