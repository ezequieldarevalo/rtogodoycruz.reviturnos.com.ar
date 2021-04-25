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


    public function obtenerTurnosDiaActual(Request $request){


        $fecha_actual=new DateTime();
        $fecha_actual->modify('-3 hours');

        $dia_actual=$fecha_actual->format('Y-m-d');

        $conditions=[
            ['fecha' ,'=', $dia_actual],
            ['estado' ,'=', "P"]
        ];

        $conditions2=[
            ['fecha' ,'=', $dia_actual],
            ['estado' ,'=', "C"]
        ];
        
        $conditions3=[
            ['fecha' ,'>=', $dia_actual],
            ['estado' ,'=', "P"]
        ];

        $conditions4=[
            ['fecha' ,'>=', $dia_actual],
            ['estado' ,'=', "C"]
        ];
        


        $turnos=Turno::where($conditions)->orWhere($conditions2)->orderBy('hora')->get();

        $dias_turno=Turno::where($conditions3)->orWhere($conditions4)->distinct('fecha')->get(['fecha']);

        $dias_futuros=[];
        foreach ($dias_turno as $dia){
            array_push($dias_futuros,$dia);
        }

        $turnos_dia=[];
        foreach ($turnos as $turno){
            $turno->datos;
            $turno->cobro;
            array_push($turnos_dia,$turno);
        }

        $respuesta=[
            'turnosDia' => $turnos_dia,
            'diasFuturos' => $dias_futuros
        ];

        return response()->json($respuesta,200);

    }


    public function obtenerTurnosDiaFuturo(Request $request){

        $validator = Validator::make($request->all(), [
            'dia' => 'required|string'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $dia=$request->input("dia");

        $conditions=[
            ['fecha' ,'=', $dia],
            ['estado' ,'=', "P"]
        ];

        $conditions2=[
            ['fecha' ,'=', $dia],
            ['estado' ,'=', "C"]
        ];
        
        $turnos=Turno::where($conditions)->orWhere($conditions2)->orderBy('hora')->get();

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


    
    public function registrarPago(Request $request){

        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        
        $validator = Validator::make($request->all(), [
            'id_turno' => 'required|integer',
            'metodo_pago' => 'required|string|max:50',
            'id_pago' => 'required|string|max:100'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $id_turno=$request->input("id_turno");
        $metodo_pago=$request->input("metodo_pago");
        $id_cobro=$request->input("id_pago");
        $turno=Turno::find($id_turno);

        if($turno->estado!="C" || $turno->origen!="A"){

            $respuesta=[
                'status' => 'failed',
                'mensaje' => "No se puede registrar el pago de este turno."
            ];
                    
            return $respuesta;

        }

        // ACTUALIZO ESTADO DEL TURNO
        $res_pagar=Turno::where('id',$turno->id)->update(array('estado' => "P"));
            
        if(!$res_pagar){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al actualizar el estado del turno a pagado",
                "fix" => "REVISAR",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $turno->datos->nro_turno_rto,
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }

        $fecha_actual=new DateTime();
        $fecha_actual->modify('-3 hours');
        $fecha_actual_cobro=$fecha_actual->format('d-m-Y H:i:s');


        $precio=$turno->datos->precio->precio;

        $agrabar=[
            'fecha' => $fecha_actual_cobro,
            'monto' => $precio,
            'metodo' => $metodo_pago,
            'nro_op' => 0,
            'origen' => "Administracion",
            'id_turno' => $turno->id,
            'id_cobro' => $id_cobro
        ];


        $res_cobro=Cobro::insert($agrabar);

        if(!$res_cobro){
                        
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "El cobro no pudo registrarse",
                "fix" => "REVISAR",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $datos_turno->nro_turno_rto,
                "servicio" => "notification"
            ];

        Logerror::insert($error);

        }

        $respuesta=[
            'status' => 'success'
        ];

        return response()->json($respuesta,200);

    }



    //     public function registrarAtencion(Request $request){

    //     if($request->header('Content-Type')!="application/json"){   
    //         $respuesta=[
    //             'status' => 'failed',
    //             'mensaje' => "Debe enviar datos en formato json"
    //         ];
                    
    //         return $respuesta;
    //     }

        
    //     $validator = Validator::make($request->all(), [
    //         'id_turno' => 'required|integer',
    //         // 'resultado' => 'required|string|max:50',
    //         // 'id_pago' => 'required|string|max:100'
    //     ]);

    //     if ($validator->fails()) {
            
    //         $respuesta=[
    //             'status' => 'failed',
    //             'mensaje' => "Datos inválidos"
    //         ];
                    
    //         return response()->json($respuesta,400);
    //     }

    //     $id_turno=$request->input("id_turno");
    //     $turno=Turno::find($id_turno);

    //     //espero definicion de renzo

    //     $respuesta=[
    //         'status' => 'success'
    //     ];

    //     return response()->json($respuesta,200);

    // }


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
            'nombre' => 'required|string|max:100',
            'email' => 'required|email:rfc,dns',
            'marca' => 'required|string|max:40',
            'modelo' => 'required|string|max:50',
            'anio' => 'required|integer',
            'combustible' => 'required|string|max:30',
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
        $email=$request->input("email");
        $marca=$request->input("marca");
        $modelo=$request->input("modelo");
        $anio=$request->input("anio");
        $combustible=$request->input("combustible");

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
            'email' => $email,
            'tipo_vehiculo' => $tipo_vehiculo,
            'marca' => $marca,
            'modelo' => $modelo,
            'anio' => $anio,
            'combustible' => $combustible,
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

        public function reprogramarTurno(Request $request){

        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        
        $validator = Validator::make($request->all(), [
            'id_turno_ant' => 'required|integer',
            'id_turno_nuevo' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $id_turno_ant=$request->input("id_turno_ant");
        $id_turno_nuevo=$request->input("id_turno_nuevo");

        $turno_anterior=Turno::find($id_turno_ant);
        $turno_nuevo=Turno::find($id_turno_nuevo);

        if($turno_anterior->estado!="P"){

            $respuesta=[
                'status' => 'failed',
                'mensaje' => "El turno a reprogramar debe estar pagado."
            ];
                    
            return response()->json($respuesta,400);

        }

        if(!($turno_nuevo->estado=="D" || ($turno_nuevo->estado=="R" && $turno_nuevo->vencimiento<$fecha_actual))){
	        
            $respuestaError=[
                        'status' => 'failed',
                        'mensaje' => "El turno deseado ya no se encuentra disponible. Refresque la pagina."
                    ];

            return response()->json($respuestaError,400);

        }

        $datos_futuro_turno=[
            'estado' => $turno_anterior->estado,
            'id_cobro_yac' => $turno_anterior->id_cobro_yac
        ];

        $actualizar_turno_nuevo=Turno::where('id',$turno_nuevo->id)->update($datos_futuro_turno);
            
        if(!$actualizar_turno_nuevo){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al actualizar el nuevo turno",
                "fix" => "REVISAR",
                "id_turno" => $turno_anterior->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }

        $actualizar_id_datos=Datosturno::where('id_turno',$turno_anterior->id)->update(array('id_turno' => $turno_nuevo->id));
            
        if(!$actualizar_id_datos){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al actualizar el id de turno en Datos turno",
                "fix" => "REVISAR",
                "id_turno" => $turno_anterior->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }


        $actualizar_id_cobros=Cobro::where('id_turno',$turno_anterior->id)->update(array('id_turno' => $turno_nuevo->id));
            
        if(!$actualizar_id_cobros){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al actualizar el id en la tabla Cobros",
                "fix" => "REVISAR",
                "id_turno" => $turno_anterior->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }

        $datos_viejo_turno=[
            'estado' => "D",
            'id_cobro_yac' => ""
        ];

        $actualizar_viejo_nuevo=Turno::where('id',$turno_anterior->id)->update($datos_viejo_turno);
            
        if(!$actualizar_viejo_nuevo){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al actualizar el nuevo turno",
                "fix" => "REVISAR",
                "id_turno" => $turno_anterior->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }

        $respuesta=[
            'status' => 'success'
        ];

        return response()->json($respuesta,200);

    }

    
    public function buscarTurnoPorId(Request $request){

        // if($request->header('Content-Type')!="application/json"){   
        //     $respuesta=[
        //         'status' => 'failed',
        //         'mensaje' => "Debe enviar datos en formato json"
        //     ];
                    
        //     return $respuesta;
        // }

        
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
        
        $turno=Turno::where('id',$id_turno)->first();

        if(!$turno){
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "No existe un turno con el id ingresado."
            ];
                    
            return response()->json($respuesta,400);

        }


        if($turno->estado=="D" || $turno->estado=="R"){

            $respuesta=[
                'status' => 'failed',
                'mensaje' => "No existe un turno con el id ingresado."
            ];
                    
            return response()->json($respuesta,400);

        }

        $respuesta=[
            'status' => 'success',
            'tipo' => 'id',
            'id' => $turno->id
        ];

        return response()->json($respuesta,200);


    }


    public function buscarTurnoPorDominio(Request $request){

        // if($request->header('Content-Type')!="application/json"){   
        //     $respuesta=[
        //         'status' => 'failed',
        //         'mensaje' => "Debe enviar datos en formato json"
        //     ];
                    
        //     return $respuesta;
        // }

        
        $validator = Validator::make($request->all(), [
            'dominio' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $dominio=$request->input("dominio");
        
        $datosturnos=Datosturno::where('dominio',$dominio)->get();

        $turnos=[];

        foreach($datosturnos as $turno){
            $turno->turno->datos;
            array_push($turnos,$turno->turno);
        }

    

        $respuesta=[
            'status' => 'success',
            'tipo' => 'dominio',
            'turnos' => $turnos
        ];

        return response()->json($respuesta,200);


    }


    public function obtenerTurnosParaReprog(Request $request){
        
        
        $validator = Validator::make($request->all(), [
            'tipo_vehiculo' => 'required|string|max:50'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $tipo_vehiculo=$request->input("tipo_vehiculo");

        $vehiculo=Precio::where('descripcion',$tipo_vehiculo)->first();

        if(!$vehiculo){
            $respuestaError=[
                'status' => 'failed',
                'message' => 'Tipo de vehiculo no valido.'
            ];

            return response()->json($respuestaError,400);
        }

        $dia_actual=date("Y-m-d");

        $conditions=[
            "tipo_vehiculo" => $vehiculo->tipo_vehiculo
        ];

        $lineas = Linea::where('tipo_vehiculo',$vehiculo->tipo_vehiculo)->get();

        $lineas_turnos=array();
        foreach($lineas as $linea){
            array_push($lineas_turnos,$linea->id);
        }

        $conditions=[
            ['estado','=','D'],
            ['origen','=','T'],
            ['fecha','>=',$dia_actual]
        ];

        $fecha_actual=new DateTime();

        $conditions2=[
            ['estado','=','R'],
            ['origen','=','T'],
            ['fecha','>=',$dia_actual],
            ['vencimiento','<',$fecha_actual]            
        ];
        
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->orWhere($conditions2)->whereIn('id_linea',$lineas_turnos)->orderBy('fecha')->get();

        $respuesta=[
            'status' => 'success',
            'turnos' => $turnos
        ];
        
        return response()->json($respuesta,200);


    }
    

}
