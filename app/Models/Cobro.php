<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cobro extends Model
{
    
	protected $table="cobros";

    protected $fillable=[
    	"fecha",
    	"monto",
    	"metodo",
    	"nro_op",
    	"origen",
    	"id_turno"
    ];

    public function turno(){
    	return $this->belongsTo('App\Models\Turno','id_turno');
    }
}

