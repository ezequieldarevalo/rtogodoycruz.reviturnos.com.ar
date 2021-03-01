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

class ApiturnoController extends Controller
{

        // funcion que busca el token en la tabla, luego si esta vencido obtiene otro y lo guarda
    public function obtenerToken(){
        
        
        $token = Token::first();

        $tres_horas_despues=date("Y-m-d H:i:s");

        $momento_actual=date("Y-m-d H:i:s",strtotime($tres_horas_despues."-3 hours"));

        if($momento_actual<$token["fecha_expiracion"]){

            $respuesta=[
                'status' => 'success',
                'token' => $token["token"]
            ];
            
            return $respuesta;

        }else{


            $data=[
                 'email' => 'turnos@rtorivadavia.com.ar',
                 'password' => '20ReviRv'
            ];

            try{
                
                $response = Http::withOptions(['verify' => false])->post('https://rto.mendoza.gov.ar/api/v1/auth/login',$data);

                if( $response->getStatusCode()!=200){

                    $respuesta=[
                        'status' => 'failed',
                        'token' => ''
                    ];
                
                    return $respuesta;
                
                }else{
                    
                    $newToken=[
                    'token' => $response["access_token"],
                    'fecha_expiracion' => $response["expires_at"]
                    ];

                    $update=Token::where('id',1)->update($newToken);

                    $respuesta=[
                        'status' => 'success',
                        'token' => $newToken["token"]
                    ];
                
                    return $respuesta;
                }


            }catch(\Exception $e){
                
                $respuesta=[
                    'status' => 'failed',
                    'mensaje' => 'RTO no responde al obtener el token'
                ];
            
                return $respuesta;
            }

        }

    }

    public function validarTurno(Request $request){


        // valido que el dato venga en formato JSON
        if($request->header('Content-Type')!="application/json"){
            $respuestaError=[
                'status' => 'failed',
                'message' => "Debe enviar datos en formato json"
            ];
                    
            return response()->json($respuestaError,400);
        }

        // valido que el dato numero de turno sea un entero y se encuentre presente
        $validator = Validator::make($request->all(), [
            'nro_turno_rto' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuestaError=[
                'status' => 'failed',
                'message' => "Datos inválidos"
            ];
                    
            return response()->json($respuestaError,400);
        }

        $nro_turno_rto=$request->input('nro_turno_rto');

        // obtengo token de plataforma RTO
        $nuevoToken=$this->obtenerToken();

        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'status' => 'failed',
                'message' => $nuevoToken["mensaje"]
            ];
            return response()->json($respuestaError,400);
        }

        // preparo los datos a postear a RTO Mendoza
        $data=[
            'turno' => $nro_turno_rto
        ];

        // ejecuto la consulta del turno a la plataforma RTO
        try{

            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/turno',$data);

        }catch(\Exception $e){
                
            $respuestaError=[
                'status' => 'failed',
                'message' => 'RTO no responde al consultar turno'
            ];
            
            return response()->json($respuestaError,400);

        }

        // valido la respuesta de RTO
        if( $response->getStatusCode()!=200){

            
            $respuestaError=[
                'status' => 'failed',
                'message' => 'El turno ingresado no se encuentra disponible para hacer la RTO'
            ];
            return response()->json($respuestaError,400);
            
        }else{
            if($response["status"]!='success'){
                    
                $respuestaError=[
                    'status' => 'failed',
                    'message' => 'El turno ingresado no se encuentra disponible para hacer la RTO'
                ];
                return response()->json($respuestaError,400);
            }
        }

        // si el status code es 200 y el status es success obtengo los datos del turno
        $datos_turno=$response["turno"];

        // valido que el turno este pendiente
        if($datos_turno["estado"]!="PENDIENTE"){
            $respuestaError=[
                'status' => 'failed',
                'message' => 'Su turno no se encuentra activo.'
            ];
            return response()->json($respuestaError,400);
        }

        $vehiculo=Precio::where('descripcion',$datos_turno["tipo_de_vehiculo"])->first();

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

        $lineas = Linea::where($conditions)->get();

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
        
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->orWhere($conditions2)->orderBy('fecha')->get();

        $respuestaOK=[
            'status' => 'success',
            'tipo_vehiculo' => $datos_turno["tipo_de_vehiculo"],
            'precio' => $vehiculo->precio,
            'turnos' => $turnos
        ];
        
        return response()->json($respuestaOK,200);

    }


    public function solicitarTurno(Request $request) {
        
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        
        $validator = Validator::make($request->all(), [
            'origen' => 'required|string|max:1',
            'email' => 'required|email:rfc,dns',
            'id_turno' => 'required|integer',
            'tipo_vehiculo' => 'required|string|max:50',
            'nro_turno_rto' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        $nro_turno_rto=$request->input("nro_turno_rto");
        $email_solicitud=$request->input("email");
        $id_turno=$request->input("id_turno");
        $origen=$request->input("origen");
        $tipo_vehiculo=$request->input("tipo_vehiculo");

        $nuevoToken=$this->obtenerToken();

        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => $nuevoToken["mensaje"]
            ];
            return response()->json($respuestaError,400);
        }

        $data=[
            'turno' => $nro_turno_rto
        ];

        try{

            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/turno',$data);

        }catch(\Exception $e){
                
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'RTO no responde al consultar turno'
            ];
            
            return response()->json($respuestaError,400);

        }


        if( $response->getStatusCode()!=200){

            
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'Fallo la consulta al RTO',
                'token' => $nuevoToken,
                'turno' => $nro_turno_rto
            ];
            return response()->json($respuestaError,400);
            
        }else{
            if($response["status"]!='success'){
                    
                $respuestaError=[
                    'status' => 'failed',
                    'mensaje' => 'Consulta con status no exitoso'
                ];
                return response()->json($respuestaError,400);
            }
        }


        $datos_turno=$response["turno"];


        if($datos_turno["email"]!=$email_solicitud){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'Email invalido'
            ];
            return response()->json($respuestaError,400);
        }

         // valido que el turno este pendiente
        if($datos_turno["estado"]!="PENDIENTE"){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'Su turno no se encuentra activo.'
            ];
            return response()->json($respuestaError,400);
        }


        // valido que el dominio no tenga otro turno pendiente
        $datosturnos=Datosturno::where('dominio',$datos_turno["patente"])->get();
        
        foreach($datosturnos as $datosturno){
            if($datosturno->turno->estado=="R"){
                $respuesta=[
                    'status' => 'failed',
                    'mensaje' => "Existe un turno reservado pero no confirmado para su dominio."
                ];      
                return response()->json($respuesta,400);
            }
            
        }

         
        $turno=Turno::where('id',$id_turno)->first();

        if(!$turno){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'El turno no existe'
            ];
            return response()->json($respuestaError,400);
        }

        $fecha_actual=new DateTime();

        if(!($turno->estado=="D" || ($turno->estado=="R" && $turno->vencimiento<$fecha_actual))){
	        
            $respuestaError=[
                        'status' => 'failed',
                        'mensaje' => "El turno ya no se encuentra disponible. Refresque la pagina."
                    ];

            return response()->json($respuestaError,400);

        }
        


        // if($turno->estado!="D"){
           
        //     $conditions=[
        //         "tipo_vehiculo" => $turno->linea->tipo_vehiculo
        //     ];


        //     $lineas = Linea::where($conditions)->get();

        //     if (count($lineas)>0){

        //         $listado_lineas=array();
        //         foreach($lineas as $linea){
        //             array_push($listado_lineas,$linea->id);
        //         }

        //         $conditions2=[
        //             "fecha" => $turno->fecha,
        //             "hora" => $turno->hora,
        //             "estado" => "D"
        //         ];
                
        //         $posibles_turnos=Turno::where($conditions2)->whereIn('id_linea',$listado_lineas)->get();

                
        //         if (count($posibles_turnos)>0){
        //             $turno=$posibles_turnos->first();
        //         }else{
                    
        //             $respuesta=[
        //                 'status' => 'failed',
        //                 'mensaje' => "El turno ya no se encuentra disponible"
        //             ];
                    
        //             return $respuesta;
        //         }

        //     }else{
                
        //         $respuesta=[
        //                 'status' => 'failed',
        //                 'mensaje' => "No se encontraron lineas para la planta y el vehiculo ingresados"
        //             ];
        //         return $respuesta;
        //     }

        // } // fin turno no disponible


        $fecha=getDate();
        if(strlen($fecha["mon"])==1) $mes='0'.$fecha["mon"]; else $mes=$fecha["mon"];
        $dia_actual=$fecha["year"]."-".$mes."-".$fecha["mday"];

        $vehiculo=Precio::where('descripcion',$tipo_vehiculo)->first();
        $precio_float=$vehiculo->precio.'.00';
        // $fecha_vencimiento=date("d-m-Y",strtotime($dia_actual."+ 12 hours"));

        
        $fecha_vencimiento=$fecha_actual->modify('+12 hours');


    
        $url_request='https://api.yacare.com/v1/payment-orders-managment/payment-order';
        // $url_request='https://core.demo.yacare.com/api-homologacion/v1/payment-orders-managment/payment-order';
            
        // conseguir token yacare
        // $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxNDQ4IiwiaWF0IjoxNjEzMzQ3NjY1LCJleHAiOjE2NDQ5MDQ2MTcsIk9JRCI6MTQ0OCwiVElEIjoiWUFDQVJFX0FQSSJ9.ElFX4Bo1H-qyuuVZA0RW6JpDH7HjltV8cJP_qzDpNerD-24BdZB8QlD65bGdy2Vc0uT0FzYmsev9vlVz9hQykg';
        
        $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiI0NDg5IiwiaWF0IjoxNjEzNzYzNjI4LCJleHAiOjE2NDUzMjA1ODAsIk9JRCI6NDQ4OSwiVElEIjoiWUFDQVJFX0FQSSJ9.wpkLqfoHe5l6d2seKI3cvdDQj1A4-B2WXcxNC08fTC-1b_WvxONdn61TwSF2FF81X_BngS3R0gvpaw5RV6s44g';
        
        $nombre_completo=$datos_turno["nombre"].' '.$datos_turno["apellido"];

        $referencia=$id_turno.$fecha_actual->format('dmYHis');


        $datos_post=[
            "buyer" => [
                "email" => $email_solicitud,
                "name" => $nombre_completo,
                "surname" => ""
            ],
            "expirationTime" => 600,
            "items" => [
                [
                "name" => "Turno RTO Centro Este",
                "quantity" => "1",
                "unitPrice" => $precio_float
                ]
            ],
            "notificationURL" => "https://centroeste.reviturnos.com.ar/api/auth/notif",
            "redirectURL" => "https://turnos.rtorivadavia.com.ar/confirmado",
            "reference" => $referencia
        ];

        
            
        
        $headers_yacare=[
            'Authorization' => $token_request
        ];

        try{
            
            $response = Http::withHeaders($headers_yacare)->post($url_request,$datos_post);

        }catch(\Exception $e){

            $error=[
                "tipo" => "YACARE",
                "descripcion" => "Fallo la solicitud de pago",
                "fix" => "NA",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $nro_turno_rto
            ];

            Logerror::insert($error);

        }

        if( $response->getStatusCode()!=200){

            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'Fallo la solicitud de pago'
            ];
            return response()->json($respuestaError,400);
            
        }


        $id_cobro=$response["paymentOrderUUID"];
        // $id_cobro="";


        
        // ACTUALIZO EL ESTADO DEL TURNO A RESERVADO
        $data_reserva=[
            'estado' => "R",
            'vencimiento' => $fecha_vencimiento,
            'id_cobro_yac' => $id_cobro
        ];

        // $data_reserva=[
        //     'estado' => "C"
        // ];

        $res_reservar=Turno::where('id',$turno->id)->update($data_reserva);
        if(!$res_reservar){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => 'Fallo al realizar la reserva'
            ];
            return response()->json($respuestaError,400);
        }

        $aux_carga_datos_turno=[
            'nombre' => $nombre_completo,
            'dominio' => $datos_turno["patente"],
            'email' => $email_solicitud,
            'tipo_vehiculo' => $datos_turno["tipo_de_vehiculo"],
            'marca' => $datos_turno["marca"],
            'modelo' => $datos_turno["modelo"],
            'anio' => $datos_turno["anio"],
            'combustible' => $datos_turno["combustible"],
            'inscr_mendoza' => $datos_turno["inscripto_en_mendoza"],
            'id_turno' => $turno->id,
            'nro_turno_rto' => $nro_turno_rto
        ];

        if($turno->estado=="D"){

            $res_guardar_datos=Datosturno::insert($aux_carga_datos_turno);

            if(!$res_guardar_datos){

                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "Fallo el alta de los datos del turno.",
                    "fix" => "REVISAR",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => $nro_turno_rto,
                    "servicio" => "solicitarTurno"
                ];

                Logerror::insert($error);

            }

        }else{
            // voy a tener que hacer update del registro
            $res_actualizar_datos=Datosturno::where('id_turno',$turno->id)->update($aux_carga_datos_turno);

            if(!$res_actualizar_datos){

                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "Fallo el update de los datos del turno.",
                    "fix" => "REVISAR",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => $nro_turno_rto,
                    "servicio" => "solicitarTurno"
                ];

                Logerror::insert($error);

            }
        }

        // alta en tabla datos_turno
        


        $datos_mail=new TurnoRto;
        $datos_mail->id=$turno->id;
        $datos_mail->fecha=$turno->fecha;
        $datos_mail->hora=$turno->hora;
        $datos_mail->url_pago=$response["paymentURL"];
        // $datos_mail->url_pago="";
        $datos_mail->dominio=$datos_turno["patente"];
        $datos_mail->nombre=$nombre_completo;


        try{
            
            Mail::to($email_solicitud)->send(new TurnoRtoM($datos_mail));

        }catch(\Exception $e){
            
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al enviar datos del turno al cliente",
                "fix" => "MAIL",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $nro_turno_rto,
                "servicio" => "solicitarTurno"
            ];

            Logerror::insert($error);

        }

        $nuevoToken=$this->obtenerToken();

        if($nuevoToken["status"]=='failed'){

            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al obtener token previo a confirmar el turno",
                "fix" => "CONFIRM",
                "id_turno" => $turno->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

        }

        try{

            $response_rto = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/confirmar',array('turno' => $nro_turno_rto));

            if( $response_rto->getStatusCode()!=200){

                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "Fallo al confirmar turno al RTO",
                    "fix" => "CONFIRM",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => $nro_turno_rto,
                    "servicio" => "notification"
                ];

                Logerror::insert($error);
                        
            }

        }catch(\Exception $e){

            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al confirmar turno al RTO",
                "fix" => "CONFIRM",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $nro_turno_rto,
                "servicio" => "notification"
            ];

            Logerror::insert($error);
                        

        }


        $respuesta=[
                'status' => 'OK',
                'url_pago' => $response["paymentURL"]
            ];

        return response()->json($respuesta,200);

    }

    
    



    // public function reservar($id){
    //     $turno=Turno::where('id',$id)->update(array('estado' => "R"));

    // }

    // public function confirmar($id){
    //     $turno=Turno::where('id',$id)->update(array('estado' => "C"));

    // }

    // public function pagar($id){
    //     $turno=Turno::where('id',$id)->update(array('estado' => "P"));

    // }

    // public function revertir($id){
    //     $turno=Turno::where('id',$id)->update(array('estado' => "F"));

    // }

    // public function disponibilizar($id){
        
    //     $data=[
    //         'estado' => "D",
    //         'id_cobro' => ""
    //     ];
    //     $turno=Turno::where('id',$id)->update($data);

    // }

    

}
