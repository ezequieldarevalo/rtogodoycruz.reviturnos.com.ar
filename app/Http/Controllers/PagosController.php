<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Turno;
use App\Models\Cobro;
use App\Models\Datosturno;
use App\Models\Token;
use App\Models\Logerror;
use Validator;
use App\Exceptions\MyOwnException;
use Exception;
use Http;
use App\Mail\TurnoRtoM;
use SteamCondenser\Exceptions\SocketException;
use Illuminate\Support\Facades\Mail;

class PagosController extends Controller
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
                
                $response = Http::withOptions(['verify' => false])->post('https://rto.renzovinci.com.ar/api/v1/auth/login',$data);

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
                        'token' => $newToken["fecha_expiracion"]
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


    public function notification(Request $request){


        // VALIDACIONES
        ///////////////////////
        $validator = Validator::make($request->all(), [
            'id' => 'required|string|max:50'
        ]);

        if ($validator->fails()) {

            $error=[
                "tipo" => "NOTIF YAC",
                "descripcion" => $request->input("id"),
                "fix" => "GETSTATE",
                "id_turno" => 0,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);
            
            $respuesta=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];
                    
            return response()->json($respuesta,400);
        }

        
        // ALMACENO EL INPUT EN LA VARIABLE ID_COBRO
        ///////////////////////
        $id_cobro=$request->input("id");


        // BUSCO EL TURNO CON EL ID DE COBRO
        ///////////////////////
        $turno=Turno::where('id_cobro_yac',$id_cobro)->first();
        

        // SI LA BUSQUEDA NO DA RESULTADO, REGISTRO EL ERROR
        ///////////////////////
        if(!$turno){
            
            $error=[
                "tipo" => "TABLA",
                "descripcion" => "Fallo la consulta en la tabla turnos con el id de cobro: ".$id_cobro,
                "fix" => "NA",
                "id_turno" => 0,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);
        }


        /////////////////////////////////////////////////////////////////////
        // CONSULTO A YACARE LOS DATOS DEL PAGO
        /////////////////////////////////////////////////////////////////////
        
        // url produccion
        //$url_request='https://api.yacare.com/v1/payment-orders-managment/payment-order';

        // url desarrollo
        $url_request='https://core.demo.yacare.com/api-homologacion/v1/operations-managment/payments';
        
        // token yacare produccion
        // $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiI0NDU5IiwiaWF0IjoxNjEzNjY5OTA2LCJleHAiOjE2NDUyMjY4NTgsIk9JRCI6NDQ1OSwiVElEIjoiWUFDQVJFX0FQSSJ9.8vVyQ9Eh4f5-IqScABBb6mTYeHiva7cUbD2ZMnfdZSvk4SjPrroI60uZbfInhoEXfUrzP8l-CYwtX4iEFS8e0g';
        
        // token yacare desarrollo
        $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxNDQ4IiwiaWF0IjoxNjEzMzQ3NjY1LCJleHAiOjE2NDQ5MDQ2MTcsIk9JRCI6MTQ0OCwiVElEIjoiWUFDQVJFX0FQSSJ9.ElFX4Bo1H-qyuuVZA0RW6JpDH7HjltV8cJP_qzDpNerD-24BdZB8QlD65bGdy2Vc0uT0FzYmsev9vlVz9hQykg';
            

        $headers_yacare=[
            'Authorization' => $token_request
        ];

        try{
            
            $response = Http::withHeaders($headers_yacare)->post($url_request,$datos_post);

        }catch(\Exception $e){

            $error=[
                "tipo" => "YACARE",
                "descripcion" => "Fallo la consulta de estado del id: ".$id_cobro,
                "fix" => "NA",
                "id_turno" => 0,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);
        }


        // SI YACARE DA ERROR ENTONCES LO REGISTRO
        /////////////////////////////////////////////////////////////////////
        if( $response->getStatusCode()!=200){

            $error=[
                "tipo" => "YACARE",
                "descripcion" => "Fallo la consulta de estado del id: ".$id_cobro.". Posible pago no registrado.",
                "fix" => "REVISAR",
                "id_turno" => 0,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

            Logerror::insert($error);

            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);
            
        }


        // ALMACENO DATOS DEL PAGO EN LA VARIABLE CORRESPONDIENTE
        /////////////////////////////////////////////////////////////////////
        $datos_pago=$response;


        // SI ESTA PAGA CAMBIO ESTADO DEL TURNO A PAGADO Y REGISTRO EL COBRO EN LA TABLA
        /////////////////////////////////////////////////////////////////////
        if($datos_pago["status"]["description"]=="paid"){


            
            // OBTENGO ID DEL TURNO EN LA RTO PARA CONFIRMAR A RTO MENDOZA
            /////////////////////////////////////////////////////////////////////
            $datos_turno=Datosturno::where('id_turno',$turno->id)->first();

            if(!$datos_turno){

                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "No se encuentran datos del turno para confirmar a la RTO.",
                    "fix" => "CONFIRM",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => "",
                    "servicio" => "notification"
                ];

                Logerror::insert($error);

            }else{

                $nuevoToken=$this->obtenerToken();

                if($nuevoToken["status"]=='failed'){

                    $error=[
                        "tipo" => "CRITICO",
                        "descripcion" => "Fallo al obtener token previo a confirmar el turno",
                        "fix" => "CONFIRM",
                        "id_turno" => $turno->id,
                        "nro_turno_rto" => $datos_turno->nro_turno_rto,
                        "servicio" => "notification"
                    ];

                    Logerror::insert($error);

                }

                try{

                    $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.renzovinci.com.ar/api/v1/auth/confirmar',$data);

                    if( $response->getStatusCode()!=200){

                        $error=[
                            "tipo" => "CRITICO",
                            "descripcion" => "Fallo al confirmar turno al RTO",
                            "fix" => "CONFIRM",
                            "id_turno" => $turno->id,
                            "nro_turno_rto" => $datos_turno->nro_turno_rto,
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
                        "nro_turno_rto" => $datos_turno->nro_turno_rto,
                        "servicio" => "notification"
                    ];

                    Logerror::insert($error);
                        

                }

            }

            // ACTUALIZO ESTADO DEL TURNO
            $res_pagar=Turno::where('id',$turno->id)->update(array('estado' => "P"));
            
            if(!$res_pagar){
                
                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "Fallo al actualizar el estado del turno a pagado",
                    "fix" => "REVISAR",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => $datos_turno->nro_turno_rto,
                    "servicio" => "notification"
                ];

                Logerror::insert($error);

            }
            
            // REGISTRO EL PAGO/COBRO
            $res_cobro=Cobro::insert(array(
                'fecha' => $datos_pago["date"],
                'monto' => $datos_pago["amount"],
                'metodo' => $datos_pago["method"],
                'nro_op' => $datos_pago["operationNumber"],
                'origen' => "Yacare",
                'id_turno' => $turno->id
            ));

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
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);

        }


        // SI ESTA EXPIRADA ENTONCES DISPONIBILIZO EL TURNO Y BORRO DATOS
        /////////////////////////////////////////////////////////////////////
        if($datos_pago["status"]["description"]=="expired"){

            // DISPONIBILIZO EL TURNO
            $data=[
                'estado' => "D",
                'id_cobro' => ""
            ];
            $res_disponibilizar=Turno::where('id',$turno->id)->update($data);

            if(!$res_disponibilizar){
                
                $error=[
                        "tipo" => "CRITICO",
                        "descripcion" => "No se pudo disponibilizar el turno",
                        "fix" => "REVISAR",
                        "id_turno" => $turno->id,
                        "nro_turno_rto" => $datos_turno->nro_turno_rto,
                        "servicio" => "notification"
                    ];

                Logerror::insert($error);

            }
            
            // ELIMINO EL REGISTRO DE LOS DATOS
            $borrar_datos_tabla=Datosturno::where('id',$turno->id)->delete();
            
            if(!$borrar_datos_tabla){
                
                $error=[
                        "tipo" => "CRITICO",
                        "descripcion" => "No se han podido borrar los datos de un turno disponibilizado",
                        "fix" => "REVISAR",
                        "id_turno" => $turno->id,
                        "nro_turno_rto" => $datos_turno->nro_turno_rto,
                        "servicio" => "notification"
                    ];

                Logerror::insert($error);

            }

            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);

        }


        // SI ESTA REVERTIDA LA REGISTRO
        /////////////////////////////////////////////////////////////////////
        if($datos_pago["status"]["description"]=="reverted"){

            // REVIERTO PAGO DEL TURNO
            $res_revertir=Turno::where('id',$turno->id)->update(array('estado' => "F"));
            if(!$res_revertir){
                $error=[
                        "tipo" => "CRITICO",
                        "descripcion" => "No se pudo revertir el turno/pago",
                        "fix" => "REVISAR",
                        "id_turno" => $turno->id,
                        "nro_turno_rto" => $datos_turno->nro_turno_rto,
                        "servicio" => "notification"
                    ];

                Logerror::insert($error);
            }

            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);

        }

    
        $respuesta=[
                'status' => 'OK'
            ];
                    
        return response()->json($respuesta,200);



    }



    



    

    

    

}
