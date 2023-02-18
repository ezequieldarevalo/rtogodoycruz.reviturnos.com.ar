<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReprogTurnoRto extends Model
{
    use HasFactory;

    protected $fillable=[
        "id",
    	"fecha",
    	"hora",
        "dominio",
        "nombre",
        "plant_name",
        "change_date_url"
    ];

}
