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


        $error=[
                "tipo" => "NOTIF YAC",
                "descripcion" => "Llego notificacion con id: ".$request->input("id"),
                "fix" => "GETSTATE",
                "id_turno" => 0,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

        Logerror::insert($error);

        
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

        $error=[
                "tipo" => "NOTIF YAC",
                "descripcion" => "Obtuve id de turno desde el id de pago :".$request->input("id"),
                "fix" => "GETSTATE",
                "id_turno" => $turno->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

        Logerror::insert($error);


        /////////////////////////////////////////////////////////////////////
        // CONSULTO A YACARE LOS DATOS DEL PAGO
        /////////////////////////////////////////////////////////////////////
        
        // url produccion
        //$url_request='https://api.yacare.com/v1/operations-managment/operations';
        $url_request='https://api.yacare.com/v1/operations-managment/operations?transaction='.$id_cobro;

        // url desarrollo
        // $url_request='https://core.demo.yacare.com/api-homologacion/v1/operations-managment/operations?transaction='.$id_cobro;
        
        // token yacare produccion
        $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiI0NDg5IiwiaWF0IjoxNjEzNzYzNjI4LCJleHAiOjE2NDUzMjA1ODAsIk9JRCI6NDQ4OSwiVElEIjoiWUFDQVJFX0FQSSJ9.wpkLqfoHe5l6d2seKI3cvdDQj1A4-B2WXcxNC08fTC-1b_WvxONdn61TwSF2FF81X_BngS3R0gvpaw5RV6s44g';
        
        // token yacare desarrollo
        // $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxNDQ4IiwiaWF0IjoxNjEzMzQ3NjY1LCJleHAiOjE2NDQ5MDQ2MTcsIk9JRCI6MTQ0OCwiVElEIjoiWUFDQVJFX0FQSSJ9.ElFX4Bo1H-qyuuVZA0RW6JpDH7HjltV8cJP_qzDpNerD-24BdZB8QlD65bGdy2Vc0uT0FzYmsev9vlVz9hQykg';
            

        $headers_yacare=[
            'Authorization' => $token_request
        ];

        try{
            
            $response = Http::withHeaders($headers_yacare)->get($url_request);

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

        $error=[
                "tipo" => "NOTIF YAC",
                "descripcion" => "Consulte estado de un pago, el resultado fue: ".$response->status(),
                "fix" => "GETSTATE",
                "id_turno" => $turno->id,
                "nro_turno_rto" => "",
                "servicio" => "notification"
            ];

        Logerror::insert($error);


        // SI YACARE DA ERROR ENTONCES LO REGISTRO
        /////////////////////////////////////////////////////////////////////
        if( $response->status()!=200){

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
        $datos_pago=$response[0];

        

        $error=[
            "tipo" => "AAAAAAA",
            "descripcion" => "Pruebo obtener status: ".$datos_pago["status"]["id"],
            "fix" => "REVISAR",
            "id_turno" => $turno->id,
            "nro_turno_rto" => "",
            "servicio" => "notification"
        ];

        Logerror::insert($error);


        // SI ESTA PAGA CAMBIO ESTADO DEL TURNO A PAGADO Y REGISTRO EL COBRO EN LA TABLA
        /////////////////////////////////////////////////////////////////////
        if($datos_pago["status"]["id"]=="P"){


            $listado_intentos=$datos_pago["payments"];

            
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

                ///////////////////////////////////////////////////
                ////SE CAMBIA DE LUGAR LA CONFIRMACION A LA RTO////
                ///////////////////////////////////////////////////

                
                // $nuevoToken=$this->obtenerToken();

                // if($nuevoToken["status"]=='failed'){

                //     $error=[
                //         "tipo" => "CRITICO",
                //         "descripcion" => "Fallo al obtener token previo a confirmar el turno",
                //         "fix" => "CONFIRM",
                //         "id_turno" => $turno->id,
                //         "nro_turno_rto" => "",
                //         "servicio" => "notification"
                //     ];

                //     Logerror::insert($error);

                // }

                // try{

                //     $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/confirmar',array('turno' => $datos_turno->nro_turno_rto));

                //     if( $response->getStatusCode()!=200){

                //         $error=[
                //             "tipo" => "CRITICO",
                //             "descripcion" => "Fallo al confirmar turno al RTO",
                //             "fix" => "CONFIRM",
                //             "id_turno" => $turno->id,
                //             "nro_turno_rto" => $datos_turno->nro_turno_rto,
                //             "servicio" => "notification"
                //         ];

                //         Logerror::insert($error);
                        
                //     }

                // }catch(\Exception $e){

                //     $error=[
                //         "tipo" => "CRITICO",
                //         "descripcion" => "Fallo al confirmar turno al RTO",
                //         "fix" => "CONFIRM",
                //         "id_turno" => $turno->id,
                //         "nro_turno_rto" => $datos_turno->nro_turno_rto,
                //         "servicio" => "notification"
                //     ];

                //     Logerror::insert($error);
                        

                // }

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

            
            foreach($listado_intentos as $pago){
                

                // si el pago esta aprobado
                if($pago["status"]["id"]=="A"){
                    
                    // REGISTRO EL PAGO/COBRO
                    $res_cobro=Cobro::insert(array(
                        'fecha' => $pago["date"],
                        'monto' => $datos_pago["amount"],
                        'metodo' => $pago["type"],
                        'nro_op' => $pago["transactionId"],
                        'origen' => "Yacare",
                        'id_turno' => $turno->id,
                        'id_cobro' => $id_cobro
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

                    break;

                }

            }
            


            $respuesta=[
                'status' => 'OK'
            ];
                    
            return response()->json($respuesta,200);

        }



        // SI ESTA EXPIRADA ENTONCES DISPONIBILIZO EL TURNO Y BORRO DATOS
        /////////////////////////////////////////////////////////////////////
        // if($datos_pago["status"]["id"]=="R"){

        //     // DISPONIBILIZO EL TURNO
        //     $data=[
        //         'estado' => "D",
        //         'id_cobro_yac' => ""
        //     ];
        //     $res_disponibilizar=Turno::where('id',$turno->id)->update($data);

        //     if(!$res_disponibilizar){
                
        //         $error=[
        //                 "tipo" => "CRITICO",
        //                 "descripcion" => "No se pudo disponibilizar el turno",
        //                 "fix" => "REVISAR",
        //                 "id_turno" => $turno->id,
        //                 "nro_turno_rto" => $datos_turno->nro_turno_rto,
        //                 "servicio" => "notification"
        //             ];

        //         Logerror::insert($error);

        //     }
            
        //     // ELIMINO EL REGISTRO DE LOS DATOS
        //     $borrar_datos_tabla=Datosturno::where('id_turno',$turno->id)->delete();
            
        //     if(!$borrar_datos_tabla){
                
        //         $error=[
        //                 "tipo" => "CRITICO",
        //                 "descripcion" => "No se han podido borrar los datos de un turno disponibilizado",
        //                 "fix" => "REVISAR",
        //                 "id_turno" => $turno->id,
        //                 "nro_turno_rto" => $datos_turno->nro_turno_rto,
        //                 "servicio" => "notification"
        //             ];

        //         Logerror::insert($error);

        //     }

        //     $respuesta=[
        //         'status' => 'OK'
        //     ];
                    
        //     return response()->json($respuesta,200);

        // }

    
        $respuesta=[
                'status' => 'OK'
            ];
                    
        return response()->json($respuesta,200);



    }


    public function notificationMeli(Request $request){

        
        // ALMACENO EL INPUT EN LA VARIABLE ID_COBRO
        ///////////////////////
        $id_cobro=$request->input("data.id");


        // VOY A BUSCAR LOS DATOS DEL PAGO
        $url_preffix='https://api.mercadopago.com/v1/payments/';
        $url_access_code='TEST-1963147828445709-052222-3ab1f18bc72827756c825693867919c9-32577613';
        $url_request=$url_preffix.$id_cobro.'?access_token='.$url_access_code;

        try{
            
            $response = Http::get($url_request);

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

        // BUSCO EL TURNO CON LA REFERENCIA TRAIDA DE LOS DATOS DEL PAGO
        ///////////////////////
        $turno=Turno::where('id_cobro_yac',$response["external_reference"])->first();

        
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


        ////// SI EL PAGO ESTA APROBADO
        if($response["status"]=='approved'){

            
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

            $res_cobro=Cobro::insert(array(
                'fecha' => substr($response["date_approved"],0,19),
                'monto' => $response["transaction_amount"],
                'metodo' => $response["payment_type_id"].' - '.$response["payment_method_id"],
                'nro_op' => 'MP-'.$response["id"],
                'origen' => "MercadoPago",
                'id_turno' => $turno->id,
                'id_cobro' => $id_cobro
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

            // break;



        }

    }




    

    

    

}
