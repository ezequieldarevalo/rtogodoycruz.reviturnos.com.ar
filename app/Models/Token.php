<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $fillable=[
    	"token",
    	"fecha_expiracion",
        "id_planta"
    ];

}
