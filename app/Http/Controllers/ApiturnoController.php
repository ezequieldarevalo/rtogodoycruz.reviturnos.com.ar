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
use Validator;
use App\Exceptions\MyOwnException;
use Exception;
use Http;
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

            
            // conseguir credenciales rto mendoza
            // $data=[
            //      'email' => '@rtorivadavia.com.ar',
            //      'password' => ''
            // ];

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
    
    public function respuestaYacare(){
        
        $respuesta=[
            'id' => 35,
            'type' => 'payment_request',
            'state' => 'pending',
            'created_at' => '2019-02-22T15:20:49-03:00',
            'payer_name' => 'nombre_pagador',
            'description' => 'concepto_del_pago',
            'first_due_date' => '2020-01-25T00:00:00-03:00',
            'first_total' => 200.99,
            'barcode' => '29680000002000000000350002000019138000000004',
            'checkout_url' => 'https://checkout.pagos360.com/payment-request/9455caf6-36ce-11e9-96fd-fb95450d3057',
            'barcode_url' => 'https://admin.pagos360.com/api/payment-request/barcode/9455caf6-36ce-11e9-96fd-fb95450d3057',
            'pdf_url' => 'https://admin.pagos360.com/api/payment-request/pdf/9455caf6-36ce-11e9-96fd-fb95450d3057'
        ];

        return response()->json($respuesta,200);

    }

    
    
    public function obtenerTurnos(Request $request){
   		
   		
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        // valido los datos ingresados
        $validator = Validator::make($request->all(), [
            'tipoVehiculo' => 'required|string|max:1',
            'origen' => 'required|string|max:1'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Datos inválidos"
            ];
                    
            return $respuesta;
        }

        $vehiculo=$request->input("tipoVehiculo");
        $origen=$request->input("origen");

        if(!Vehiculo::where('codigo',$vehiculo)->first()){
            $respuesta=[
                'codigo_error' => 2,
                'mensaje_error' => "El tipo de vehiculo no existe"
            ];

            return $respuesta;
        }

        $dia_actual=date("Y-m-d");

        $conditions=[
            "tipo_vehiculo" => $vehiculo
        ];

        $lineas = Linea::where($conditions)->get();

        $lineas_turnos=array();
        foreach($lineas as $linea){
            array_push($lineas_turnos,$linea->id);
        }

        $conditions=[
            ['estado','=','D'],
            ['origen','=',$origen],
            ['fecha','>=',$dia_actual]
        ];
        
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->orderBy('fecha')->get();
        
        return response()->json($turnos,200);
    }

    public function obtenerTurnosProv(Request $request){
   		
   		
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        // valido los datos ingresados
        $validator = Validator::make($request->all(), [
            'tipoVehiculo' => 'required|string|max:1',
            'tipoVehiculoPrecio' => 'required|integer',
            'origen' => 'required|string|max:1'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Datos inválidos"
            ];
                    
            return $respuesta;
        }

        $vehiculo=$request->input("tipoVehiculo");
        $id_vehiculo_especifico=$request->input("tipoVehiculoPrecio");
        $origen=$request->input("origen");

        if(!Vehiculo::where('codigo',$vehiculo)->first()){
            $respuesta=[
                'codigo_error' => 2,
                'mensaje_error' => "El tipo de vehiculo no existe"
            ];

            return $respuesta;
        }

        $dia_actual=date("Y-m-d");

        $conditions=[
            "tipo_vehiculo" => $vehiculo
        ];

        $lineas = Linea::where($conditions)->get();

        $lineas_turnos=array();
        foreach($lineas as $linea){
            array_push($lineas_turnos,$linea->id);
        }

        $conditions=[
            ['estado','=','D'],
            ['origen','=',$origen],
            ['fecha','>=',$dia_actual]
        ];

        $precio=Precio::find($id_vehiculo_especifico)->precio;
        
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->orderBy('fecha')->get();

        $respuesta=[
            'turnos' => $turnos,
            'precio' => $precio
        ];
        
        return response()->json($respuesta,200);
    }

    public function pendiente($id){
        $turno=Turno::where('id',$id)->update(array('estado' => "P"));

    }

    public function confirmar($id){
        $turno=Turno::where('id',$id)->update(array('estado' => "C"));

    }

    public function disponibilizar($id){
        $turno=Turno::where('id',$id)->update(array('estado' => "D"));

    }

    public function solicitarTurno(Request $request) {
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        // valido los datos ingresados
        $validator = Validator::make($request->all(), [
            'nro_turno_rto' => 'required|integer',
            'origen' => 'required|string|max:1',
            'email' => 'required|email:rfc,dns',
            'id_turno' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Datos inválidos"
            ];
                    
            return $respuesta;
        }

        $nro_turno_rto=$request->input("nro_turno_rto");
        $email_solicitud=$request->input("email");
        $id_turno=$request->input("id_turno");
        $origen=$request->input("origen");

        $nuevoToken=$this->obtenerToken();

        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'mensaje' => $nuevoToken["mensaje"]
            ];
            return response()->json($respuestaError,400);
        }

        $data=[
            'turno' => $nro_turno_rto
        ];

        try{

            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.renzovinci.com.ar/api/v1/auth/turno',$data);

        }catch(\Exception $e){
                
            $respuestaError=[
                'mensaje' => 'RTO no responde al consultar turno'
            ];
            
            return response()->json($respuestaError,400);

        }


        if( $response->getStatusCode()!=200){

            
            $respuestaError=[
                'mensaje' => 'Fallo la consulta al RTO'
            ];
            return response()->json($respuestaError,400);
            
        }else{
            if($response["status"]!='success'){
                    
                $respuestaError=[
                    'mensaje' => 'Consulta con status no exitoso'
                ];
                return response()->json($respuestaError,400);
            }
        }

        $datos_turno=$response["turno"];

        if($datos_turno["email"]!=$email_solicitud){
            $respuestaError=[
                'mensaje' => 'Email invalido'
            ];
            return response()->json($respuestaError,400);
        }

        
        if($datos_turno["departamento"]!="Rivadavia"){
            $respuestaError=[
                'mensaje' => 'Planta incorrecta'
            ];
            return response()->json($respuestaError,400);
        }

        // valido que el dominio no tenga otro turno pendiente
        $datosturnos=Datosturno::where('dominio',$datos_turno["patente"])->get();
        
        foreach($datosturnos as $datosturno){
            if($datosturno->turno->estado=="P"){
                $respuesta=[
                    'mensaje' => "Existe otro turno activo para el dominio indicado"
                ];      
                return response()->json($respuesta,400);
            }
        }

        $turno=Turno::find($request->input("id_turno"));

        if($turno->estado!="D"){
           
            $conditions=[
                "tipo_vehiculo" => $turno->linea->tipo_vehiculo
            ];


            $lineas = Linea::where($conditions)->get();

            if (count($lineas)>0){


                
                $listado_lineas=array();
                foreach($lineas as $linea){
                    array_push($listado_lineas,$linea->id);
                }

                $conditions2=[
                    "fecha" => $turno->fecha,
                    "hora" => $turno->hora,
                    "estado" => "D"
                ];
                
                $posibles_turnos=Turno::where($conditions2)->whereIn('id_linea',$listado_lineas)->get();

                
                if (count($posibles_turnos)>0){
                    $turno=$posibles_turnos->first();
                }else{
                    
                    $respuesta=[
                        'codigo_error' => 2001,
                        'mensaje_error' => "El turno ya no se encuentra disponible"
                    ];
                    
                    return $respuesta;
                }

            }else{
                
                $respuesta=[
                        'codigo_error' => 2002,
                        'mensaje_error' => "No se encontraron lineas para la planta y el vehiculo ingresados"
                    ];
                return $respuesta;
            }

        } // fin turno no disponible


        $fecha=getDate();
        if(strlen($fecha["mon"])==1) $mes='0'.$fecha["mon"]; else $mes=$fecha["mon"];
        $dia_actual=$fecha["year"]."-".$mes."-".$fecha["mday"];

        //ver bien el tema del precio, tendria que venir especificado bien el tipo de vehiculo de la consulta en el sistema de rto del estado
        // $infoTipoVehiculo=Precio::find($request->input("tipoVehiculo"));
        // $precio_float=$infoTipoVehiculo->precio.'.00';
        $precio_float=$turno->linea->precio.".00";
        $fecha_vencimiento=date("d-m-Y",strtotime($dia_actual."+ 2 days"));

        if($turno->origen == "T"){

            $url_request='https://api.yacare.com/v1/payment-orders-managment/payment-order';
            
            // conseguir token yacare
            // $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiI0NDU5IiwiaWF0IjoxNjEzNjY5OTA2LCJleHAiOjE2NDUyMjY4NTgsIk9JRCI6NDQ1OSwiVElEIjoiWUFDQVJFX0FQSSJ9.8vVyQ9Eh4f5-IqScABBb6mTYeHiva7cUbD2ZMnfdZSvk4SjPrroI60uZbfInhoEXfUrzP8l-CYwtX4iEFS8e0g';
            
            $datos_post='{
                "buyer": {
                    "email": "'.$email_solicitud.'",
                    "name": "'.$datos_turno["nombre"].'",
                    "surname": "'.$datos_turno["apellido"].'"
                },
                "expirationTime": 28800,
                "items": [
                    {
                    "name": "Turno RTO Rivadavia",
                    "quantity": "1",
                    "unitPrice": "'.$precio_float.'"
                    }
                ],
                "notificationURL": "https://acaretorno.com",
                "redirectURL": "https://notificaciones.com",
                "reference": '.$id_turno.'
                }
            ';
            
            $response=$this->sendRequest($url_request,$token_request,$datos_post);
            
            if( $response==false){
                $respuesta=[
                            'codigo_error' => 2003,
                            'mensaje_error' => $datos_request
                        ];
                return response()->json($respuesta,200);
            }else{
                $response_js=json_decode($response);
                $id_cobro=$response_js->paymentOrderUUID;

            }

            
        }else{
            $id_cobro="";
        }

                // actualizo el estado del turno a pendiente
        $this->pendiente($turno->id);

        // alta en tabla datos_turno
        Datosturno::insert(array(
                'nombre' => $datos_turno["nombre"]." ".$datos_turno["apellido"],
                'dominio' => $datos_turno["patente"],
                'email' => $datos_turno["email"],
                'id_turno' => $turno->id
        ));

        // alta en tabla cobros
        Cobro::insert(array(
                'fecha_cobro' => date("Y-m-d"),
                'monto' => $precio,
                'metodo' => "W",
                'descripcion' => "Pagos 360",
                'id_turno' => $turno->id,
                "id_cobro" => $id_cobro
        ));


        if($turno->origen == "T"){
            $respuesta=[
                'status' => 'OK',
                'url_pago' => $response["checkout_url"]
            ];
        }else{
            $respuesta=[
                'status' => 'OK',
                'mensaje' => 'Turno confirmado desde aplicacion'
            ];
        }

        return response()->json($respuesta,200);

        
    }


    public function solicitarTurnoProv(Request $request) {
        
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'codigo_error' => 2000,
                'mensaje_error' => "Debe enviar datos en formato json"
            ];
                    
            return $respuesta;
        }

        // valido los datos ingresados
        // $validator = Validator::make($request->all(), [
        //     'origen' => 'required|string|max:1',
        //     'email' => 'required|email:rfc,dns',
        //     'id_turno' => 'required|integer',
        //     'dominio' => 'required|regex:/^(([a-zA-Z]{2}[0-9]{3}[a-zA-Z]{2})|([a-zA-Z]{1}[0-9]{3}[a-zA-Z]{3})|([a-zA-Z]{3}[0-9]{3})|([0-9]{3}[a-zA-Z]{3}))$/i',
        //     'nombre' => 'required|string|max:50',
        //     'tipoVehiculo' => 'required|integer',
        // ]);

        // if ($validator->fails()) {
            
        //     $respuesta=[
        //         'codigo_error' => 2000,
        //         'mensaje_error' => "Datos inválidos",
        //         'solicitud' => [
        //             'origen' => $request->input("origen"),
        //             'email' => $request->input("email"),
        //             'id_turno' => $request->input("id_turno"),
        //             'dominio' => $request->input("dominio"),
        //             'nombre' => $request->input("nombre"),
        //             'tipoVehiculo' => $request->input("tipoVehiculo")
        //         ]
        //     ];
                    
        //     return response()->json($respuesta,400);
        // }

        $nro_turno_rto=$request->input("nro_turno_rto");
        $email_solicitud=$request->input("email");
        $id_turno=$request->input("id_turno");
        $origen=$request->input("origen");
        $dominio=$request->input("dominio");
        $nombre=$request->input("nombre");

        // valido que el dominio no tenga otro turno pendiente
        $datosturnos=Datosturno::where('dominio',$dominio)->get();
        
        foreach($datosturnos as $datosturno){
            if($datosturno->turno->estado=="P"){
                $respuesta=[
                    'mensaje' => "Existe otro turno activo para el dominio indicado"
                ];      
                return response()->json($respuesta,400);
            }
        }

        // valido que el email no tenga otro turno pendiente
        $datosturnos=Datosturno::where('email',$email_solicitud)->get();
        
        foreach($datosturnos as $datosturno){
            if($datosturno->turno->estado=="P"){
                $respuesta=[
                    'mensaje' => "Existe otro turno activo para el email indicado"
                ];      
                return response()->json($respuesta,400);
            }
        }

        $turno=Turno::find($request->input("id_turno"));

        if($turno->estado!="D"){
           
            $conditions=[
                "tipo_vehiculo" => $turno->linea->tipo_vehiculo
            ];


            $lineas = Linea::where($conditions)->get();

            if (count($lineas)>0){

                $listado_lineas=array();
                foreach($lineas as $linea){
                    array_push($listado_lineas,$linea->id);
                }

                $conditions2=[
                    "fecha" => $turno->fecha,
                    "hora" => $turno->hora,
                    "estado" => "D"
                ];
                
                $posibles_turnos=Turno::where($conditions2)->whereIn('id_linea',$listado_lineas)->get();

                
                if (count($posibles_turnos)>0){
                    $turno=$posibles_turnos->first();
                }else{
                    
                    $respuesta=[
                        'codigo_error' => 2001,
                        'mensaje_error' => "El turno ya no se encuentra disponible"
                    ];
                    
                    return $respuesta;
                }

            }else{
                
                $respuesta=[
                        'codigo_error' => 2002,
                        'mensaje_error' => "No se encontraron lineas para la planta y el vehiculo ingresados"
                    ];
                return $respuesta;
            }

        } // fin turno no disponible


        $fecha=getDate();
        if(strlen($fecha["mon"])==1) $mes='0'.$fecha["mon"]; else $mes=$fecha["mon"];
        $dia_actual=$fecha["year"]."-".$mes."-".$fecha["mday"];

        $infoTipoVehiculo=Precio::find($request->input("tipoVehiculo"));
        $precio_float=$infoTipoVehiculo->precio.'.00';
        $fecha_vencimiento=date("d-m-Y",strtotime($dia_actual."+ 2 days"));

        if($turno->origen == "T"){

            $url_request='https://api.yacare.com/v1/payment-orders-managment/payment-order';
            
            // conseguir token yacare
            // $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiI0NDU5IiwiaWF0IjoxNjEzNjY5OTA2LCJleHAiOjE2NDUyMjY4NTgsIk9JRCI6NDQ1OSwiVElEIjoiWUFDQVJFX0FQSSJ9.8vVyQ9Eh4f5-IqScABBb6mTYeHiva7cUbD2ZMnfdZSvk4SjPrroI60uZbfInhoEXfUrzP8l-CYwtX4iEFS8e0g';
            
            $datos_post='{
                "buyer": {
                    "email": "'.$email_solicitud.'",
                    "name": "'.$nombre.'",
                    "surname": ""
                },
                "expirationTime": 28800,
                "items": [
                    {
                    "name": "Turno RTO Rivadavia",
                    "quantity": "1",
                    "unitPrice": "'.$precio_float.'"
                    }
                ],
                "notificationURL": "https://acaretorno.com",
                "redirectURL": "https://notificaciones.com",
                "reference": '.$id_turno.'
                }
            ';
            
            $response=$this->sendRequest($url_request,$token_request,$datos_post);
            
            if($response["status"]=="ERR"){

                $respuesta=[
                        'codigo_error' => 2002,
                        'mensaje' => $response["mensaje"]
                    ];

                return response()->json($respuesta,400);

            }else{

                $response_js=$response["resultado"];
                $id_cobro=$response_js->paymentOrderUUID;
            }

            
        }else{
            $id_cobro="";
        }

        // actualizo el estado del turno a pendiente
        $this->pendiente($turno->id);

        // alta en tabla datos_turno
        Datosturno::insert(array(
                'nombre' => $nombre,
                'dominio' => $dominio,
                'email' => $email_solicitud,
                'id_turno' => $turno->id
        ));

        // alta en tabla cobros
        Cobro::insert(array(
                'fecha_cobro' => date("Y-m-d"),
                'monto' => $infoTipoVehiculo->precio,
                'metodo' => "W",
                'descripcion' => "Pagos 360",
                'id_turno' => $turno->id,
                "id_cobro" => $id_cobro
        ));

        
        $datos_mail=new TurnoRto;
        $datos_mail->id=$turno->id;
        $datos_mail->fecha=$turno->fecha;
        $datos_mail->hora=$turno->hora;
        $datos_mail->url=$response_js->paymentURL;
        $datos_mail->dominio=$dominio;
        $datos_mail->nombre=$nombre;


        Mail::to($email_solicitud)->send(new TurnoRtoM($datos_mail));


        if($turno->origen == "T"){
            $respuesta=[
                'status' => 'OK',
                'url_pago' => $response_js->paymentURL
            ];
        }else{
            $respuesta=[
                'status' => 'OK',
                'mensaje' => 'Turno confirmado desde aplicacion'
            ];
        }

        return response()->json($respuesta,200);

    }

    public function sendRequest($url,$token,$datos_post){
        
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $datos_post,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: '.$token)
            ),
        );

        $response = curl_exec($ch);
        
        if (empty($response)) {
            
            curl_close($ch); // close cURL handler

            $respuesta=[
                'status' => 'ERR',
                'mensaje' => 'La plataforma de pagos no responde'
            ];

            return -1;

        } else {

            $info = curl_getinfo($ch);

            if (empty($info['http_code'])) {

                curl_close($ch);

                $respuesta=[
                    'status' => 'ERR',
                    'mensaje' => 'La plataforma de pagos no responde'
                ];
                
                return $respuesta;

            } else {

                if($info['http_code']!=200){

                    curl_close($ch);
                
                    $respuesta=[
                        'status' => 'ERR',
                        'mensaje' => 'El turno seleccionado ya no se encuentra disponible'
                    ];

                    return $respuesta;

                }else{

                    $resultado=json_decode($response);

                    $respuesta=[
                        'status' => 'OK',
                        'resultado' => $resultado
                    ];

                    curl_close($ch);

                    return $respuesta;

                }
            }

        }

    }

    

}
