<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TurnoRto extends Model
{
    use HasFactory;

    protected $fillable=[
        "id",
    	"fecha",
    	"hora",
    	"url",
        "dominio",
        "nombre",
        "plant_name",
        "no_payment",
        "time_to_pay"
    ];

}
