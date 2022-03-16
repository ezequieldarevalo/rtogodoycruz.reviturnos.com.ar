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
    public $rto_login_url='api/v1/auth/login';

    public $rto_quote_url='api/v1/auth/turno';

    public $rto_quote_confirm_url='api/v1/auth/confirmar';

    public $yacare_payments_url='payment-orders-managment/payment-order';

    public $mp_preferences_url="checkout/preferences";

    public function getRtoUrl(){
        return config('rto.url');
    }

    public function getRTOConfirmQuotes(){
        return config('rto.confirm_quotes');
    }

    public function getRtoUser(){
        return config('rto.user');
    }

    public function getRtoPassword(){
        return config('rto.password');
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

    public function getPaymentExpirationMinutes(){
        return config('plant.expiration_minutes');
    }

    public function getValidatePendingQuotes(){
        return config('plant.validate_pending_quotes');
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
    public function getRtoToken(){
        $currentToken = Token::first();
        $currentDate=date("Y-m-d H:i:s");
        if($currentDate<$currentToken["fecha_expiracion"]){
            $success_response=[
                'status' => 'success',
                'token' => $currentToken["token"]
            ];
            return $success_response;
        }else{
            $rto_plant_credentials=[
                 'email' => $this->getRtoUser(),
                 'password' => $this->getRtoPassword()
            ];
            try{
                $url_request=$this->getRtoUrl().$this->rto_login_url;
                $res_rto_login = Http::withOptions(['verify' => false])->post($url_request, $rto_plant_credentials);
                if($res_rto_login->getStatusCode()!=200){
                    $error_response=[
                        'status' => 'failed',
                        'token' => ''
                    ];
                    return $error_response;
                }else{
                    $newToken=[
                        'token' => $res_rto_login["access_token"],
                        'fecha_expiracion' => $res_rto_login["expires_at"]
                    ];
                    $updateToken=Token::where('id',1)->update($newToken);
                    $success_response=[
                        'status' => 'success',
                        'token' => $newToken["token"]
                    ];
                    return $success_response;
                }
            }catch(\Exception $e){
                $error_response=[
                    'status' => 'failed',
                    'message' => 'No response from RTO when trying to login'
                ];
                return $error_response;
            }
        }
    }

    public function validateQuote(Request $request){
        if($request->header('Content-Type')!="application/json"){
            $respuestaError=[
                'status' => 'failed',
                'message' => "Debe enviar datos en formato json"
            ];    
            return response()->json($respuestaError,400);
        }
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
        $nuevoToken=$this->getRtoToken();
        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'status' => 'failed',
                'message' => $nuevoToken["message"]
            ];
            return response()->json($respuestaError,400);
        }
        $data=[
            'turno' => $nro_turno_rto
        ];
        try{
            $request_url=$this->getRtoUrl().$this->rto_quote_url;
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($request_url,$data);
        }catch(\Exception $e){
            $respuestaError=[
                'status' => 'failed',
                'message' => 'RTO no responde al consultar turno'
            ];
            return response()->json($respuestaError,400);
        }
        if($response->getStatusCode()!=200){
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
        $datos_turno=$response["turno"];
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
        $dia_actual=new DateTime();
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
            $error_response=[
                'status' => 'failed',
                'message' => "Data must be in json format"
            ];
            return response()->json($error_response,400);
        }
        // valido que el dato numero de turno sea un entero y se encuentre presente
        $validator = Validator::make($request->all(), [
            'nro_turno_rto' => 'required|integer'
        ]);
        if ($validator->fails()) { 
            $error_response=[
                'status' => 'failed',
                'message' => "Invalid data"
            ];      
            return response()->json($error_response,400);
        }
        $rto_quote_number=$request->input('nro_turno_rto');
        // obtengo token de plataforma RTO
        $newToken=$this->getRtoToken();
        if($newToken["status"]=='failed'){
            $error_response=[
                'status' => 'failed',
                'message' => $newToken["message"]
            ];
            return response()->json($error_response,404);
        }
        // preparo los datos a postear a RTO Mendoza
        $post_data=[
            'turno' => $rto_quote_number
        ];
        // ejecuto la consulta del turno a la plataforma RTO
        try{
            $request_url=$this->getRtoUrl().$this->rto_quote_url;
            $res_quote_data = Http::withOptions(['verify' => false])->withToken($newToken["token"])->post($request_url,$post_data);
        }catch(\Exception $e){    
            $response_error=[
                'reason' => 'No response from RTO when retrieving quote data'
            ];
            return response()->json($response_error,404);
        }
        // valido la respuesta de RTO
        if( $res_quote_data->getStatusCode()!=200){
            $error_response=[
                'reason' => 'NOT_IN_RTO'
            ];
            return response()->json($error_response,404);
        }else{
            if($res_quote_data['status']!='success'){      
                $error_response=[
                    'reason' => 'NOT_IN_RTO'
                ];
                return response()->json($error_response,404);
            }
        }
        // si el status code es 200 y el status es success obtengo los datos del turno
        $quote_data=$res_quote_data["turno"];
        // valido que el turno este pendiente
        if($quote_data["estado"]!="PENDIENTE"){
            $error_response=[
                'reason' => 'INACTIVE_QUOTE'
            ];
            return response()->json($error_response,404);
        }
        $vehicle=Precio::where('descripcion',$quote_data["tipo_de_vehiculo"])->first();
        if(!$vehicle){
            $error_response=[
                'reason' => 'INVALID_VEHICLE'
            ];
            return response()->json($error_response,404);
        }
        $date_vs_expiration=new DateTime();
        $date=getDate();
        if(strlen($date["mon"])==1) $month='0'.$date["mon"]; else $month=$date["mon"];
        $currentDay=$date["year"]."-".$month."-".$date["mday"];
        $conditions=[
            "tipo_vehiculo" => $vehicle->tipo_vehiculo
        ];
        $lines = Linea::where($conditions)->get();
        $quote_lines=array();
        foreach($lines as $line){
            array_push($quote_lines,$line->id);
        }
        $available_conditions=[
            ['estado','=','D'],
            ['origen','=','T'],
            ['fecha','>',$currentDay]
        ];
        $expired_conditions=[
            ['estado','=','R'],
            ['origen','=','T'],
            ['fecha','>',$currentDay],
            ['vencimiento','<',$date_vs_expiration]            
        ];
        $quotes=Turno::select('id', 'fecha', 'hora')->whereIn('id_linea',$quote_lines)
            ->where($available_conditions)
            ->orWhere($expired_conditions)
            ->whereIn('id_linea',$quote_lines)
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();
        $days=Turno::whereIn('id_linea',$quote_lines)
            ->where($available_conditions)
            ->orWhere($expired_conditions)
            ->whereIn('id_linea',$quote_lines)
            ->distinct()
            ->orderBy('fecha')
            ->get(['fecha']);
        $days_array=array();
        foreach($days as $day){
            array_push($days_array,$day->fecha.'T00:00:00');
        }
        $success_response=[
            'status' => 'success',
            'tipo_vehiculo' => $quote_data["tipo_de_vehiculo"],
            'precio' => $vehicle->precio,
            'dias' => $days_array,
            'turnos' => $quotes
        ];
        return response()->json($success_response,200);
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
        $nuevoToken=$this->getRtoToken();
        if($nuevoToken["status"]=='failed'){
            $respuestaError=[
                'status' => 'failed',
                'mensaje' => $nuevoToken["message"]
            ];
            return response()->json($respuestaError,400);
        }
        $data=[
            'turno' => $nro_turno_rto
        ];
        try{
            $request_url=$this->getRtoUrl().$this->rto_quote_url;
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($request_url,$data);
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
        // foreach($datosturnos as $datosturno){
        //     if($datosturno->turno->estado=="R"){
        //         $respuesta=[
        //             'status' => 'failed',
        //             'mensaje' => "Existe un turno reservado pero no confirmado para su dominio."
        //         ];      
        //         return response()->json($respuesta,400);
        //     } 
        // }
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
        $url_request=$this->getYacareUrl().$this->yacare_payments_url;
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
            'id_cobro_yac' => 'Y-'.$id_cobro
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
            'marca' => $datos_turno["marca"] || "SIN ESPECIFICAR",
            'modelo' => $datos_turno["modelo"] || "SIN ESPECIFICAR",
            'anio' => $datos_turno["anio"] || 2000,
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
        $nuevoToken=$this->getRtoToken();
        if($nuevoToken["status"]=='failed'){
            $this->log("CRITICO", "Fallo al obtener token previo a confirmar el turno", "CONFIRM", $turno->id, $nro_turno_rto, "solicitarTurno");
        }
        try{
            $request_url=$this->getRtoUrl().$this->rto_quote_confirm_url;
            $response_rto = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($request_url,array('turno' => $nro_turno_rto));
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
            $error_response=[
                'reason' => 'INVALID_CONTENT_TYPE'
            ];      
            return response()->json($error_response,400);
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
            $error_response=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];      
            return response()->json($error_response,400);
        }
        $rto_quote_number=$request->input("nro_turno_rto");
        $request_email=$request->input("email");
        $quote_id=$request->input("id_turno");
        $origin=$request->input("origen");
        $vehicle_type=$request->input("tipo_vehiculo");
        $payment_platform=$request->input("plataforma_pago");
        $newToken=$this->getRtoToken();
        if($newToken["status"]=='failed'){
            $error_response=[
                'reason' => 'TOKEN'
            ];
            return response()->json($error_response,500);
        }
        $data=[
            'turno' => $rto_quote_number
        ];
        try{
            $request_url=$this->getRtoUrl().$this->rto_quote_url;
            $res_quote_data = Http::withOptions(['verify' => false])->withToken($newToken["token"])->post($request_url,$data);
        }catch(\Exception $e){   
            $error_response=[
                'reason' => 'RTO_NOT_WORKING'
            ];
            return response()->json($error_response,404);
        }
        if( $res_quote_data->getStatusCode()!=200){
            $error_response=[
                'reason' => 'RTO_NOT_FOUND'
            ];
            return response()->json($error_response,404);  
        }else{
            if($res_quote_data["status"]!='success'){ 
                $error_response=[
                    'reason' => 'RTO_NOT_FOUND'
                ];
                return response()->json($error_response,404);
            }
        }
        $quote_data=$res_quote_data["turno"];
        if($quote_data["email"]!=$request_email){
            $error_response=[
                'reason' => 'INVALID_EMAIL'
            ];
            return response()->json($error_response,404);
        }
        // valido que el turno este pendiente
        if($quote_data["estado"]!="PENDIENTE"){
            $error_response=[
                'reason' => 'INACTIVE_QUOTE'
            ];
            return response()->json($error_response,404);
        }
        $currentDate=new DateTime();
        // valido que el dominio no tenga otro turno pendiente
        if($this->getValidatePendingQuotes()){
            $duplicated_domain_list=Datosturno::where('dominio',$quote_data["patente"])->get();
            foreach($duplicated_domain_list as $duplicated_domain){
                $duplicated_domain_quote=Turno::where('id',$duplicated_domain["id_turno"])->first();
                if($duplicated_domain_quote){
                    // $duplicate_expiration_date=strtotime($duplicated_domain_quote->vencimiento);
                    $duplicate_expiration_date = DateTime::createFromFormat('Y-m-d H:i:s', $duplicated_domain_quote->vencimiento);
                    if($duplicated_domain_quote->estado=="R" && $duplicate_expiration_date>$currentDate){
                        $this->log("DALEEE", "Estado: ".$duplicated_domain_quote->estado.", vencimiento: ".$duplicated_domain_quote->vencimiento.", currentDate: ".$currentDate->format('dmYHis'), "NA", 0, "", "solicitarTurnopepe");
                        $error_response=[
                            'reason' => 'DOMAIN_WITH_PENDING_QUOTE'
                        ];
                        return response()->json($error_response,404);
                    }
                }
            }
        }
        $quote=Turno::where('id',$quote_id)->first();
        if(!$quote){
            $error_response=[
                'reason' => 'INEXISTENT_QUOTE'
            ];
            return response()->json($error_response,404);
        }
        if(!($quote->estado=="D" || ($quote->estado=="R" && $quote->vencimiento<$currentDate))){
            $error_response=[
                'reason' => 'RECENTLY_RESERVED_QUOTE'
            ];
            return response()->json($error_response,404);
        }
        $date=getDate();
        if(strlen($date["mon"])==1) $month='0'.$date["mon"]; else $month=$date["mon"];
        $currentDay=$date["year"]."-".$month."-".$date["mday"];
        $vehicle=Precio::where('descripcion',$vehicle_type)->first();
        $float_price=$vehicle->precio.'.00';
        $expiration_minutes=$this->getPaymentExpirationMinutes();
        $expiration_date=$currentDate->modify('+'.$expiration_minutes.' min');
        $reference=$quote_id.$currentDate->format('dmYHis').$quote_data["patente"];
        if($payment_platform=='yacare'){
            $request_url=$this->getYacareUrl().$this->yacare_payments_url;
            $headers_yacare=[
                'Authorization' => $this->getYacareToken()
            ];
            $complete_name=$quote_data["nombre"].' '.$quote_data["apellido"];
            $datos_post=[
                "buyer" => [
                    "email" => $request_email,
                    "name" => $complete_name,
                    "surname" => ""
                ],
                "expirationTime" => $expiration_minutes,
                "items" => [
                    [
                    "name" => "Turno RTO Godoy Cruz",
                    "quantity" => "1",
                    "unitPrice" => $float_price
                    ]
                ],
                "notificationURL" => $this->getYacareNotifUrl(),
                "redirectURL" => $this->getYacareRedirectUrl(),
                "reference" => $reference
            ];
            try{
                $res_yacare = Http::withHeaders($headers_yacare)->post($request_url,$datos_post);
            }catch(\Exception $e){
                $this->log("YACARE", "Fallo la solicitud de pago", "NA", $turno->id, $nro_turno_rto, "solicitarTurno");
            }
            if($res_yacare->getStatusCode()!=200){
                $error_response=[
                    'reason' => 'YACARE_ERROR'
                ];
                return response()->json($error_response,404);
            }
            $payment_id='Y-'.$res_yacare["paymentOrderUUID"];
            $payment_url=$res_yacare["paymentURL"];
        }else{
            $mp_aux_expiration_date=$expiration_date;
            $mp_expiration_day=$mp_aux_expiration_date->format('Y-m-d');
            $mp_expiration_time=$mp_aux_expiration_date->format('H:i:s');
            $mp_expiration_date=$mp_expiration_day.'T'.$mp_expiration_time.'.000-03:00';
            $request_url=$this->getMPUrl().$this->mp_preferences_url;
            $headers_mercadopago=[
                'Authorization' => "Bearer ".$this->getMPToken()
            ];
            $datos_post=[
                "external_reference" => $reference,
                "notification_url" => $this->getMPNotifUrl(),
                "payer" => [
                    "name" => $quote_data["nombre"],
                    "surname" => $quote_data["apellido"],
                    "email" => $request_email
                ],
                "items" => [
                    [
                        "title" => "RTO: ".$reference,
                        "quantity" => 1,
                        "unit_price" => $vehicle->precio,
                        "currency_id" => "ARS"
                    ]
                ],
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
                "expiration_date_to"=> $mp_expiration_date
            ];
            try{
                $res_mp = Http::withHeaders($headers_mercadopago)->post($request_url,$datos_post);
            }catch(\Exception $e){
                $this->log("MERCADO PAGO", "Fallo la solicitud de pago", "na", $quote->id, $rto_quote_number, "solicitarTurno");
            }
            if($res_mp->getStatusCode()!=201){
                $error_response=[
                    'reason' => 'MELI_ERROR'
                ];
                return response()->json($error_response,404);
            }
            $payment_id=$reference;
            $payment_url=$res_mp["init_point"];
        }
        // ACTUALIZO EL ESTADO DEL TURNO A RESERVADO
        $reservation_data=[
            'estado' => "R",
            'vencimiento' => $expiration_date,
            'id_cobro_yac' => $payment_id
        ];
        $res_reserve=Turno::where('id',$quote->id)->update($reservation_data);
        if(!$res_reserve){
            $error_response=[
                'reason' => 'BOOKING_FAILED'
            ];
            return response()->json($error_response,404);
        }
        $quote_data_aux_loader=[
            'nombre' => $quote_data["nombre"].' '.$quote_data["apellido"],
            'dominio' => $quote_data["patente"],
            'email' => $request_email,
            'tipo_vehiculo' => $quote_data["tipo_de_vehiculo"],
            'marca' => $quote_data["marca"] || "SIN ESPECIFICAR",
            'modelo' => $quote_data["modelo"] || "SIN ESPECIFICAR",
            'anio' => $quote_data["anio"] || 2000,
            'combustible' => $quote_data["combustible"],
            'inscr_mendoza' => $quote_data["inscripto_en_mendoza"],
            'id_turno' => $quote->id,
            'nro_turno_rto' => $rto_quote_number
        ];
        if($quote->estado=="D"){
            $res_save_quote_data=Datosturno::insert($quote_data_aux_loader);
            if(!$res_save_quote_data){
                $this->log("CRITICO", "Fallo el alta de los datos del turno", "REVISAR", $quote->id, $rto_quote_number, "solicitarTurno");
            }
        }else{
            // voy a tener que hacer update del registro
            $res_update_quote_data=Datosturno::where('id_turno',$quote->id)->update($quote_data_aux_loader);
            if(!$res_update_quote_data){
                $this->log("CRITICO", "Fallo el update de los datos del turno", "REVISAR", $quote->id, $rto_quote_number, "solicitarTurno");
            }else{
                $res_save_quote_data=Datosturno::insert($quote_data_aux_loader);
                if(!$res_save_quote_data){
                    $this->log("CRITICO", "Fallo el alta de los datos del turno", "REVISAR", $quote->id, $rto_quote_number, "solicitarTurno");
                }
            }
        }
        // alta en tabla datos_turno
        $mail_data=new TurnoRto;
        $mail_data->id=$quote->id;
        $mail_data->fecha=$quote->fecha;
        $mail_data->hora=$quote->hora;
        $mail_data->url_pago=$payment_url;
        $mail_data->dominio=$quote_data["patente"];
        $mail_data->nombre=$quote_data["nombre"].' '.$quote_data["apellido"];
        try{
            Mail::to($request_email)->send(new TurnoRtoM($mail_data));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al enviar datos del turno al cliente", "MAIL", $quote->id, $rto_quote_number, "solicitarTurno");
        }
        $newToken=$this->getRtoToken();
        if($newToken["status"]=='failed'){
            $this->log("CRITICO", "Fallo al obtener token previo a confirmar el turno", "CONFIRM", $quote->id, "", "notification");
        }
        if($this->getRtoConfirmQuotes()){
            try{
                $request_url=$this->getRtoUrl().$this->rto_quote_confirm_url;
                $response_rto = Http::withOptions(['verify' => false])->withToken($newToken["token"])->post($request_url,array('turno' => $rto_quote_number));
                if( $response_rto->getStatusCode()!=200){
                    $this->log("CRITICO", "Fallo al confirmar turno al RTO", "CONFIRM", $quote->id, $rto_quote_number, "notification");
                }
            }catch(\Exception $e){
                $this->log("CRITICO", "Fallo al confirmar turno al RTO", "CONFIRM", $quote->id, $rto_quote_number, "notification");
            }
        }
        $success_response=[
                'url_pago' => $payment_url
            ];
        return response()->json($success_response,200);
    }
}
