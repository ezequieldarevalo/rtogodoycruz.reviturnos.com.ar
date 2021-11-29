<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagoRto extends Model
{
    use HasFactory;

    protected $fillable=[
        "id",
    	"fecha",
    	"hora",
    	"url",
        "dominio",
        "nombre"
    ];

}