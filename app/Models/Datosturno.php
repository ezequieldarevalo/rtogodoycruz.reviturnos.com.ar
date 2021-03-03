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
        "tipo_vehiculo",
        "marca",
        "modelo",
        "anio",
        "combustible",
        "inscr_mendoza",
    	"id_turno",
        "nro_turno_rto"
    ];

    public function turno(){
    	return $this->belongsTo('App\Models\Turno','id_turno');
    }

        public function precio(){
		return $this->hasOne(Precio::class,'descripcion','tipo_vehiculo');
	}

}
