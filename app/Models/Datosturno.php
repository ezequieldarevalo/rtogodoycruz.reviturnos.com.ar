<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Datosturno extends Model
{
    protected $table="datos_turno";

    protected $fillable=[
    	"nombre",
    	"dominio",
    	"email",
    	"id_turno"
    ];

    public function turno(){
    	return $this->belongsTo('App\Models\Turno','id_turno');
    }

}
