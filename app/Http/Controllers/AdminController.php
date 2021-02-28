<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vehiculo;
use App\Models\Linea;
use App\Models\Turno;
use App\Models\Cobro;
use App\Models\Datosturno;
use App\Models\Token;
use App\Models\Precio;
use App\Models\TurnoRto;
use App\Models\Logerror;
use Validator;
use App\Exceptions\MyOwnException;
use Exception;
use Http;
use DateTime;
use App\Mail\TurnoRtoM;
use SteamCondenser\Exceptions\SocketException;
use Illuminate\Support\Facades\Mail;

class AdminController extends Controller
{


    public function obtenerTurnosDia(Request $request){


        $fecha_actual=new DateTime();
        $fecha_actual->modify('+10 days');

        $dia_actual=$fecha_actual->format('Y-m-d');

        $conditions=[
            ['fecha' ,'=', $dia_actual],
            ['estado' ,'=', "P"]
        ];

        $conditions2=[
            ['fecha' ,'=', $dia_actual],
            ['estado' ,'=', "C"]
        ];

        // $conditions3=[
        //     ['fecha' ,'=', $dia_actual],
        //     ['estado' ,'=', "D"]
        // ];


        $turnos=Turno::where($conditions)->orWhere($conditions2)->get();


        $resultado=[];
        foreach ($turnos as $turno){
            $turno->datos;
            $turno->cobro;
            array_push($resultado,$turno);
        }

        return response()->json($resultado,200);

    }



    public function obtenerTiposVehiculo(Request $request){

        $vehiculos=Precio::all();

        return response()->json($vehiculos,200);

    }


    public function obtenerDatosTurno(Request $request){

        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        
        $validator = Validator::make($request->all(), [
            'id_turno' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $id_turno=$request->input("id_turno");

        $turno=Turno::find($id_turno);
        $turno->cobro;
        $turno->datos;

        return response()->json($turno,200);

    }


    public function crearTurno(Request $request){

        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        
        $validator = Validator::make($request->all(), [
            'dominio' => 'required|string|max:20',
            'tipo_vehiculo' => 'required|string|max:50',
            'nombre' => 'required|string|max:100'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $dominio=$request->input("dominio");
        $tipo_vehiculo=$request->input("tipo_vehiculo");
        $nombre=$request->input("nombre");

        $fecha_actual=new DateTime();
        $fecha_actual->modify('-3 hours');

        $dia_actual=$fecha_actual->format('Y-m-d');
        $hora_actual=$fecha_actual->format('H:i:s');

        $aux_carga_turno=[
            'fecha' => $dia_actual,
            'hora' => $hora_actual,
            'estado' => "C",
            'origen' => "A",
            'observaciones' => "Creado desde la administracion",
            'id_linea' => 0,
            'id_cobro_yac' => ""
        ];

        $nuevo_turno=Turno::create($aux_carga_turno);

        $aux_carga_datos_turno=[
            'nombre' => $nombre,
            'dominio' => $dominio,
            'email' => "",
            'tipo_vehiculo' => $tipo_vehiculo,
            'marca' => "",
            'modelo' => "",
            'anio' => 0,
            'combustible' => "",
            'inscr_mendoza' => "Si",
            'id_turno' => $nuevo_turno->id,
            'nro_turno_rto' => 0
        ];
        
        $cargar_datos=Datosturno::insert($aux_carga_datos_turno);

        $respuesta=[
            'status' => 'success',
            'id' => $nuevo_turno->id
        ];

        return response()->json($respuesta,200);

    }
    

}
