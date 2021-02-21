<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Datosturno;
use Validator;

class SimulacionController extends Controller
{

    public function verify(Request $request)
    {
        $request->validate([
            'dominio' => 'required|string'
        ]);

        
        if($request->dominio=="AA670YQ"){
            return response()->json([
                'tipoVehiculo' => 'Auto',
                'dominio' => 'AA670YQ',
                'marca' => 'Renault',
                'modelo' => 'Sandero GT Line',
                'anio' => 2017,
                'tipoCombustible' => "Nafta",
                'nombre' => 'Ezequiel',
                'apellido' => 'Arevalo',
                'email' => 'ezequiel.d.arevalo@gmail.com',
                'telefono' => '1156990945',
            ],200);
        }
        if($request->dominio=="A123ABC"){
            return response()->json([
                'tipoVehiculo' => 'Moto',
                'dominio' => 'A123ABC',
                'marca' => 'Rowser',
                'modelo' => 'Pro',
                'anio' => 2018,
                'tipoCombustible' => "Diesel",
                'nombre' => 'Juan',
                'apellido' => 'Guerrero',
                'email' => 'juan.guerrero@gmail.com',
                'telefono' => '1156990946',
            ],200);
        }
        return response()->json([
            'mensaje' => 'Dominio no autorizado por el estado' 
            ],401);

    }

}
