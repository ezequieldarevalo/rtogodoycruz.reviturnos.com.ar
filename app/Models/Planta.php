<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Planta extends Model
{
    protected $fillable=['nombre','direccion','latitud','longitud','vehiculos','cant_dias_disponibles'];
}
