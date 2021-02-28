<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $fillable=[
		"id",
    	"fecha",
    	"hora",
    	"estado",
    	"origen",
    	"observaciones",
    	"id_linea",
		"id_cobro_yac"
    ];

    protected $table="turnos";

    public function linea(){
    	return $this->belongsTo(Linea::class,'id_linea');
    }

	public function cobro(){
		return $this->hasOne(Cobro::class,'id_turno','id');
	}

	public function datos(){
		return $this->hasOne(Datosturno::class,'id_turno','id');
	}
}
