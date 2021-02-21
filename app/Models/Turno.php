<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $fillable=[
    	"fecha",
    	"hora",
    	"estado",
    	"origen",
    	"observaciones",
    	"id_linea",
    	"id_planta"
    ];

    protected $table="turnos";

    public function linea(){
    	return $this->belongsTo('App\Models\Linea','id_linea');
    }
}
