<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cobro extends Model
{
    
	protected $table="cobros";

    protected $fillable=[
    	"fecha_cobro",
    	"monto",
    	"metodo",
    	"descripcion",
    	"id_turno",
    	"id_cobro"
    ];

    public function turno(){
    	return $this->belongsTo('App\Models\Turno','id_turno');
    }
}
