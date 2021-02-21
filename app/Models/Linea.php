<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Linea extends Model
{
    protected $fillable=[
    	'nombre',
    	'tipo_vehiculo',
    	'precio',
    	'tope_por_hora',
    	'tope_por_hora_2',
    	'max_dias_disponibles',
    	'cant_franjas',
    	'desde_franja_1',
    	'hasta_franja_1',
    	'desde_franja_2',
    	'hasta_franja_2',
    	'id_planta',
    	'id_usuario'
    ];
}
