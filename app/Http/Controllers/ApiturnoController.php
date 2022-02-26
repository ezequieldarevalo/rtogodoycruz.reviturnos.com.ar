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
use Config;

class ApiturnoController extends Controller
{
    public function getRtoUrl(){
        return config('rto.url');
    }

    public function getYacareUrl(){
        return config('yacare.url');
    }

    public function getYacareToken(){
        return config('yacare.token');
    }

    public function getYacareNotifUrl(){
        return config('yacare.notif_url');
    }

    public function getYacareRedirectUrl(){
        return config('yacare.redirect_url');
    }

    public function getMPUrl(){
        return config('mercadopago.url');
    }

    public function getMPToken(){
        return config('mercadopago.token');
    }

    public function getMPNotifUrl(){
        return config('mercadopago.notif_url');
    }

    public function getMPRedirectUrl(){
        return config('mercadopago.redirect_url');
    }

    public function log($type, $description, $fix, $quote_id, $rto_quote_id, $service ){
        $error=[
            "tipo" => $type,
            "descripcion" => $description,
            "fix" => $fix,
            "id_turno" => $quote_id,
            "nro_turno_rto" => $rto_quote_id,
            "servicio" => $service
        ];
        Logerror::insert($error);
    }

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
                 'email' => 'rtogodoycruz@gmail.com',
                 'password' => 'Rto93228370330'
            ];
            try{
                $response = Http::withOptions(['verify' => false])->post($this->getRtoUrl()."api/v1/auth/login", $data);
                if($response->getStatusCode()!=200){
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
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/turno',$data);
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
        // $dia_actual=date("Y-m-d");
        $dia_actual=new DateTime();
        // $primer_dia_turnos=$dia_actual->modify('+2 days');

        $conditions=[
            "tipo_vehiculo" => $vehiculo->tipo_vehiculo
        ];
        $lineas = Linea::where($conditions)->get();
        $lineas_turnos=array();
        foreach($lineas as $linea){
            array_push($lineas_turnos,$linea->id);
        }
        $fecha_actual=new DateTime();
        $fecha_actual_formateada=$fecha_actual->format('Y-m-d');
        $conditions=[
            ['estado','=','D'],
            ['origen','=','T'],
            ['fecha','>=',$fecha_actual_formateada]
        ];
        $conditions2=[
            ['estado','=','R'],
            ['origen','=','T'],
            ['fecha','>=',$fecha_actual_formateada],
            ['vencimiento','<',$dia_actual]            
        ];
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->orWhere($conditions2)->whereIn('id_linea',$lineas_turnos)->orderBy('fecha')->get();
        $respuestaOK=[
            'status' => 'success',
            'tipo_vehiculo' => $datos_turno["tipo_de_vehiculo"],
            'precio' => $vehiculo->precio,
            'turnos' => $turnos
        ];
        return response()->json($respuestaOK,200);
    }

    public function getAvailableQuotes(Request $request){
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
            return response()->json($respuestaError,404);
        }
        // preparo los datos a postear a RTO Mendoza
        $data=[
            'turno' => $nro_turno_rto
        ];
        // ejecuto la consulta del turno a la plataforma RTO
        try{
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/turno',$data);
        }catch(\Exception $e){    
            $respuestaError=[
                'status' => 'failed',
                'message' => 'RTO no responde al consultar turno'
            ];
            return response()->json($respuestaError,404);
        }
        // valido la respuesta de RTO
        if( $response->getStatusCode()!=200){
            $respuestaError=[
                'reason' => 'NOT_IN_RTO'
            ];
            return response()->json($respuestaError,404);
        }else{
            if($response['status']!='success'){      
                $respuestaError=[
                    'reason' => 'NOT_IN_RTO'
                ];
                return response()->json($respuestaError,404);
            }
        }
        // si el status code es 200 y el status es success obtengo los datos del turno
        $datos_turno=$response["turno"];
        // valido que el turno este pendiente
        if($datos_turno["estado"]!="PENDIENTE"){
            $respuestaError=[
                'reason' => 'INACTIVE_QUOTE'
            ];
            return response()->json($respuestaError,404);
        }
        $vehiculo=Precio::where('descripcion',$datos_turno["tipo_de_vehiculo"])->first();
        if(!$vehiculo){
            $respuestaError=[
                'reason' => 'INVALID_VEHICLE'
            ];
            return response()->json($respuestaError,404);
        }
        // $dia_actual=date("Y-m-d");
        $dia_actual=new DateTime();
        // $primer_dia_turnos=$dia_actual->modify('+2 days');
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
            ['vencimiento','<',$dia_actual]            
        ];
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->whereIn('id_linea',$lineas_turnos)->orderBy('fecha')->orderBy('hora')->get();
        $dias=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->whereIn('id_linea',$lineas_turnos)->distinct()->orderBy('fecha')->get(['fecha']);
        $array_dias=array();
        foreach($dias as $dia){
            array_push($array_dias,$dia->fecha.'T00:00:00');
        }
        $respuestaOK=[
            'status' => 'success',
            'tipo_vehiculo' => $datos_turno["tipo_de_vehiculo"],
            'precio' => $vehiculo->precio,
            'dias' => $array_dias,
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
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/turno',$data);
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
        $fecha=getDate();
        if(strlen($fecha["mon"])==1)
            $mes='0'.$fecha["mon"];
        else 
            $mes=$fecha["mon"];
        $dia_actual=$fecha["year"]."-".$mes."-".$fecha["mday"];
        $vehiculo=Precio::where('descripcion',$tipo_vehiculo)->first();
        $precio_float=$vehiculo->precio.'.00';
        $fecha_vencimiento=$fecha_actual->modify('+12 hours');
        $url_request=$this->getYacareUrl().'v1/payment-orders-managment/payment-order';
        $token_request=$this->getYacareToken();
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
                "name" => "Turno RTVO Centro Express",
                "quantity" => "1",
                "unitPrice" => $precio_float
                ]
            ],
            "notificationURL" => $this->getYacareNotifUrl(),
            "redirectURL" => $this->getYacareRedirectUrl(),
            "reference" => $referencia
        ];
        $headers_yacare=[
            'Authorization' => $token_request
        ];
        try{
            $response = Http::withHeaders($headers_yacare)->post($url_request,$datos_post);
        }catch(\Exception $e){
            $this->log('YACARE', 'Falló la solicitud de pago', 'NA', $turno->id, $nro_turno_rto);
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
                $this->log("CRITICO", "Fallo el alta de los datos del turno", "REVISAR", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
        }else{
            // voy a tener que hacer update del registro
            $res_actualizar_datos=Datosturno::where('id_turno',$turno->id)->update($aux_carga_datos_turno);
            if(!$res_actualizar_datos){
                $this->log("CRITICO", "Fallo el update de los datos del turno", "REVISAR", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
        }
        // alta en tabla datos_turno
        $datos_mail=new TurnoRto;
        $datos_mail->id=$turno->id;
        $datos_mail->fecha=$turno->fecha;
        $datos_mail->hora=$turno->hora;
        $datos_mail->url_pago=$response["paymentURL"];
        $datos_mail->dominio=$datos_turno["patente"];
        $datos_mail->nombre=$nombre_completo;
        try{
            Mail::to($email_solicitud)->send(new TurnoRtoM($datos_mail));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al enviar datos del turno al cliente", "MAIL", $turno->id, $nro_turno_rto, "solicitarTurno");
        }
        $nuevoToken=$this->obtenerToken();
        if($nuevoToken["status"]=='failed'){
            $this->log("CRITICO", "Fallo al obtener token previo a confirmar el turno", "CONFIRM", $turno->id, $nro_turno_rto, "solicitarTurno");
        }
        try{
            $response_rto = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/confirmar',array('turno' => $nro_turno_rto));
            if( $response_rto->getStatusCode()!=200){
                $this->log("CRITICO", "Fallo al confirmar turno al RTO", "CONFIRM", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al confirmar turno al RTO", "CONFIRM", $turno->id, $nro_turno_rto, "solicitarTurno");
        }
        $respuesta=[
                'status' => 'OK',
                'url_pago' => $response["paymentURL"]
            ];
        return response()->json($respuesta,200);
    }

    public function confirmQuote(Request $request) {
        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'reason' => 'INVALID_CONTENT_TYPE'
            ];      
            return response()->json($respuesta,400);
        }
        $validator = Validator::make($request->all(), [
            'origen' => 'required|string|max:1',
            'email' => 'required|string|max:150',
            'id_turno' => 'required|integer',
            'tipo_vehiculo' => 'required|string|max:50',
            'nro_turno_rto' => 'required|integer',
            'plataforma_pago' => 'required|string|max:20'
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
        $plataforma_pago=$request->input("plataforma_pago");
        $nuevoToken=$this->obtenerToken();
        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'reason' => 'TOKEN'
            ];
            return response()->json($respuestaError,500);
        }
        $data=[
            'turno' => $nro_turno_rto
        ];
        try{
            $res_info_turno = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/turno',$data);
        }catch(\Exception $e){   
            $respuestaError=[
                'reason' => 'RTO_NOT_WORKING'
            ];
            return response()->json($respuestaError,404);
        }
        if( $res_info_turno->getStatusCode()!=200){
            $respuestaError=[
                'reason' => 'RTO_NOT_FOUND'
            ];
            return response()->json($respuestaError,404);  
        }else{
            if($res_info_turno["status"]!='success'){ 
                $respuestaError=[
                    'reason' => 'RTO_NOT_FOUND'
                ];
                return response()->json($respuestaError,404);
            }
        }
        $datos_turno=$res_info_turno["turno"];
        if($datos_turno["email"]!=$email_solicitud){
            $respuestaError=[
                'reason' => 'INVALID_EMAIL'
            ];
            return response()->json($respuestaError,404);
        }
        // valido que el turno este pendiente
        if($datos_turno["estado"]!="PENDIENTE"){
            $respuestaError=[
                'reason' => 'INACTIVE_QUOTE'
            ];
            return response()->json($respuestaError,404);
        }
        $fecha_actual=new DateTime();
        // valido que el dominio no tenga otro turno pendiente
        $datosturnos=Datosturno::where('dominio',$datos_turno["patente"])->get();
        foreach($datosturnos as $datosturno){
            if($turno->estado=="R" && $turno->vencimiento<$fecha_actual){
                $respuestaError=[
                    'reason' => 'EXISTS_QUOTE_DOMAIN'
                ];
                return response()->json($respuestaError,404);
            }
            
        }
        $turno=Turno::where('id',$id_turno)->first();
        if(!$turno){
            $respuestaError=[
                'reason' => 'INEXISTENT_QUOTE'
            ];
            return response()->json($respuestaError,404);
        }
        if(!($turno->estado=="D" || ($turno->estado=="R" && $turno->vencimiento<$fecha_actual))){
            $respuestaError=[
                'reason' => 'RECENTLY_RESERVED_QUOTE'
            ];
            return response()->json($respuestaError,404);
        }
        $fecha=getDate();
        if(strlen($fecha["mon"])==1) $mes='0'.$fecha["mon"]; else $mes=$fecha["mon"];
        $dia_actual=$fecha["year"]."-".$mes."-".$fecha["mday"];
        $vehiculo=Precio::where('descripcion',$tipo_vehiculo)->first();
        $precio_float=$vehiculo->precio.'.00';
        // $fecha_vencimiento=date("d-m-Y",strtotime($dia_actual."+ 12 hours"));
        $fecha_vencimiento=$fecha_actual->modify('+5 minutes');
        $referencia=$id_turno.$fecha_actual->format('dmYHis').$datos_turno["patente"];
        if($plataforma_pago=='yacare'){
            $url_request=$this->getYacareUrl()."payment-orders-managment/payment-order";
            $token_request=$this->getYacareToken();
            $nombre_completo=$datos_turno["nombre"].' '.$datos_turno["apellido"];
            $datos_post=[
                "buyer" => [
                    "email" => $email_solicitud,
                    "name" => $nombre_completo,
                    "surname" => ""
                ],
                "expirationTime" => 120,
                "items" => [
                    [
                    "name" => "Turno RTO Godoy Cruz",
                    "quantity" => "1",
                    "unitPrice" => $precio_float
                    ]
                ],
                "notificationURL" => $this->getYacareNotifUrl(),
                "redirectURL" => $this->getYacareRedirectUrl(),
                "reference" => $referencia
            ];
            $headers_yacare=[
                'Authorization' => $token_request
            ];
            try{
                $res_yacare = Http::withHeaders($headers_yacare)->post($url_request,$datos_post);
            }catch(\Exception $e){
                $this->log("YACARE", "Fallo la solicitud de pago", "NA", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
            if( $res_yacare->getStatusCode()!=200){
                $respuestaError=[
                    'reason' => 'YACARE_ERROR'
                ];
                return response()->json($respuestaError,404);
            }
            $id_cobro='Y-'.$res_yacare["paymentOrderUUID"];
            $url_pago=$res_yacare["paymentURL"];
        }else{
            $fecha_vencimiento_aux_mp=$fecha_vencimiento;
            $dia_vencimiento_mp=$fecha_vencimiento_aux_mp->format('Y-m-d');
            $hora_vencimiento_mp=$fecha_vencimiento_aux_mp->format('H:i:s');
            $fecha_vencimiento_mp=$dia_vencimiento_mp.'T'.$hora_vencimiento_mp.'.000-00:00';
            $url_request=$this->getMPUrl()."checkout/preferences";
            $token_request="Bearer ".$this->getMPToken();
            $headers_mercadopago=[
                'Authorization' => $token_request
            ];
            $datos_post=[
                "external_reference" => $referencia,
                "notification_url" => $this->getMPNotifUrl(),
                "payer" => [
                    "name" => $datos_turno["nombre"],
                    "surname" => $datos_turno["apellido"],
                    "email" => $email_solicitud
                ],
                "items" => [
                    [
                        "title" => "RTO: ".$referencia,
                        "quantity" => 1,
                        "unit_price" => $vehiculo->precio,
                        "currency_id" => "ARS"
                    ]
                ],
                // "back_urls" => [
                //     "success" => "https://turnos.reviturnos.com.ar/confirmed/rivadavia"
                // ],
                "payment_methods" => [
                    "excluded_payment_methods" => [
                        [
                            "id" => "bapropagos"
                        ],
                        [
                            "id" => "rapipago"
                        ],
                        [
                            "id" => "pagofacil"
                        ],
                        [
                            "id" => "cargavirtual"
                        ],
                        [
                            "id" => "redlink"
                        ],
                        [
                            "id" => "cobroexpress"
                        ]
                    ]
                ],
                "expires" => true,
                "expiration_date_to"=> $fecha_vencimiento_mp
            ];
            try{
                $res_mp = Http::withHeaders($headers_mercadopago)->post($url_request,$datos_post);
            }catch(\Exception $e){
                $this->log("MERCADO PAGO", "Fallo la solicitud de pago", "na", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
            if( $res_mp->getStatusCode()!=201){
                $respuestaError=[
                    'reason' => 'MELI_ERROR'
                ];
                return response()->json($respuestaError,404);
            }
            $id_cobro=$referencia;
            $url_pago=$res_mp["init_point"];
        }
        // ACTUALIZO EL ESTADO DEL TURNO A RESERVADO
        $data_reserva=[
            'estado' => "R",
            'vencimiento' => $fecha_vencimiento,
            'id_cobro_yac' => $id_cobro
        ];
        $res_reservar=Turno::where('id',$turno->id)->update($data_reserva);
        if(!$res_reservar){
            $respuestaError=[
                'reason' => 'BOOKING_FAILED'
            ];
            return response()->json($respuestaError,404);
        }
        $aux_carga_datos_turno=[
            'nombre' => $datos_turno["nombre"].' '.$datos_turno["apellido"],
            'dominio' => $datos_turno["patente"],
            'email' => $email_solicitud,
            'tipo_vehiculo' => $datos_turno["tipo_de_vehiculo"],
            // 'marca' => $datos_turno["marca"],
            // 'modelo' => $datos_turno["modelo"],
            // 'anio' => $datos_turno["anio"],
            'marca' => "SIN ESPECIFICAR",
            'modelo' => "SIN ESPECIFICAR",
            'anio' => 2000,
            'combustible' => $datos_turno["combustible"],
            'inscr_mendoza' => $datos_turno["inscripto_en_mendoza"],
            'id_turno' => $turno->id,
            'nro_turno_rto' => $nro_turno_rto
        ];
        if($turno->estado=="D"){
            $res_guardar_datos=Datosturno::insert($aux_carga_datos_turno);
            if(!$res_guardar_datos){
                $this->log("CRITICO", "Fallo el alta de los datos del turno", "REVISAR", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
        }else{
            // voy a tener que hacer update del registro
            $res_actualizar_datos=Datosturno::where('id_turno',$turno->id)->update($aux_carga_datos_turno);
            if(!$res_actualizar_datos){
                $this->log("CRITICO", "Fallo el update de los datos del turno", "REVISAR", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
        }
        // alta en tabla datos_turno
        $datos_mail=new TurnoRto;
        $datos_mail->id=$turno->id;
        $datos_mail->fecha=$turno->fecha;
        $datos_mail->hora=$turno->hora;
        $datos_mail->url_pago=$url_pago;
        $datos_mail->dominio=$datos_turno["patente"];
        $datos_mail->nombre=$datos_turno["nombre"].' '.$datos_turno["apellido"];
        try{
            Mail::to($email_solicitud)->send(new TurnoRtoM($datos_mail));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al enviar datos del turno al cliente", "MAIL", $turno->id, $nro_turno_rto, "solicitarTurno");
        }
        $nuevoToken=$this->obtenerToken();
        if($nuevoToken["status"]=='failed'){
            $this->log("CRITICO", "Fallo al obtener token previo a confirmar el turno", "CONFIRM", $turno->id, "", "notification");
        }
        // try{
        //     $response_rto = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($this->getRtoUrl().'api/v1/auth/confirmar',array('turno' => $nro_turno_rto));
        //     if( $response_rto->getStatusCode()!=200){
        //         $error=[
        //             "tipo" => "CRITICO",
        //             "descripcion" => "Fallo al confirmar turno al RTO",
        //             "fix" => "CONFIRM",
        //             "id_turno" => $turno->id,
        //             "nro_turno_rto" => $nro_turno_rto,
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
        //         "nro_turno_rto" => $nro_turno_rto,
        //         "servicio" => "notification"
        //     ];
        //     Logerror::insert($error);
        // }
        $respuesta=[
                'url_pago' => $url_pago
            ];
        return response()->json($respuesta,200);
    }
}