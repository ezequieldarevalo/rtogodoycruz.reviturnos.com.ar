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

    public $mp_search_preference_url="/v1/payments/search";

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

    public function getPaymentCashExpirationMinutes(){
        return config('plant.cash_expiration_minutes');
    }

    public function getMarginPostExpirationMinutes(){
        return config('plant.margin_post_expiration_minutes');
    }

    public function getExcludedPaymentMethods(){
        return config('mercadopago.excluded_payment_methods');
    }

    public function getCashExcludedPaymentMethods(){
        return config('mercadopago.cash_excluded_payment_methods');
    }

    public function getPlantName(){
        return config('app.plant_name');
    }

    public function getNoPayment(){
        return config('app.no_payment');
    }

    public function minutesToHours($minutes){
        return floor($minutes / 60);
    }

    public function getFormattedPlantName($name){
        if($name=='lasheras') return 'Revitotal - Las Heras';
        if($name=='maipu') return 'Revitotal - Maipu';
        if($name=='godoycruz') return 'Godoy Cruz';
        if($name=='sanmartin') return 'San Martin - Mendoza';
        if($name=='rivadavia') return 'Rivadavia';
        return '';
    }

    public function getIgnoreLines(){
        return config('plant.ignore_lines');
    }

    public function getNotificationManagerUrl(){
        return config('app.notification_manager_url');
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

    public function processYACNotification($id_cobro){
		$id_cobro_yac='Y-'.$id_cobro;
		$turno=Turno::where('id_cobro_yac',$id_cobro_yac)->first();
		// SI LA BUSQUEDA NO DA RESULTADO, REGISTRO EL ERROR
		///////////////////////
		if(!$turno){
		    $this->log("TABLA", "Fallo la consulta en la tabla turnos con el id de cobro: ".$id_cobro, "NA", 0, "", "notification");
		    $respuesta=[
		        'status' => 'failed',
		        'code' => 'ID_NOT_FOUND'
		    ];      
		    return $respuesta;
		}
		$this->log("NOTIF YAC", "Obtuve id de turno desde el id de pago :".$id_cobro, "GETSTATE", $turno->id, "", "notification");
		/////////////////////////////////////////////////////////////////////
		// CONSULTO A YACARE LOS DATOS DEL PAGO
		/////////////////////////////////////////////////////////////////////
		$request_url=$this->getYacareUrl().$this->yacare_transactions_url.$id_cobro;
		$headers_yacare=[
		    'Authorization' => $this->getYacareToken()
		];
		try{
		    $response = Http::withHeaders($headers_yacare)->get($request_url);
		}catch(\Exception $e){
		    $this->log("YACARE", "Fallo la consulta de estado del id: ".$id_cobro, "NA", 0, "", "notification");
		    $respuesta=[
		        'status' => 'failed',
		        'code' => 'YACARE_QUERY_FAILED'
		    ];      
		    return $respuesta;
		}
		$this->log("NOTIF YAC", "Consulte estado de un pago, el resultado fue: ".$response->status(), "GETSTATE", $turno->id, "", "notification");
		// SI YACARE DA ERROR ENTONCES LO REGISTRO
		/////////////////////////////////////////////////////////////////////
		
		if( $response->status()!=200){
		    $this->log("YACARE", "Fallo la consulta de estado del id: ".$id_cobro.". Posible pago no registrado.", "REVISAR", 0, "", "notification");
		    $respuesta=[
		        'status' => 'failed',
		        'code' => 'YACARE_ID_NOT_FOUND'
		    ];      
		    return $respuesta;
		}
		// ALMACENO DATOS DEL PAGO EN LA VARIABLE CORRESPONDIENTE
		/////////////////////////////////////////////////////////////////////
		
		$datos_pago=$response[0];
		
		$this->log("AAAAAAA", "Pruebo obtener status: ".$datos_pago["status"]["id"], "REVISAR", $turno->id, "", "notification");
		// SI ESTA PAGA CAMBIO ESTADO DEL TURNO A PAGADO Y REGISTRO EL COBRO EN LA TABLA
		/////////////////////////////////////////////////////////////////////
		
		if($datos_pago["status"]["id"]=="P"){
		    $listado_intentos=$datos_pago["payments"];
		    // OBTENGO ID DEL TURNO EN LA RTO PARA CONFIRMAR A RTO MENDOZA
		    /////////////////////////////////////////////////////////////////////
		    $datos_turno=Datosturno::where('id_turno',$turno->id)->first();
		    if(!$datos_turno){
		        $this->log("CRITICO", "No se encuentran datos del turno para confirmar a la RTO.", "CONFIRM", $turno->id, "", "notification");
		    }
		    // ACTUALIZO ESTADO DEL TURNO
		    $res_pagar=Turno::where('id',$turno->id)->update(array('estado' => "P"));
		    if(!$res_pagar){
		        $this->log("CRITICO", "Fallo al actualizar el estado del turno a pagado", "REVISAR", $turno->id, $datos_turno->nro_turno_rto, "notification");
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
		                $this->log("CRITICO", "El cobro no pudo registrarse", "REVISAR", $turno->id, $datos_turno->nro_turno_rto, "notification");
		            }
		            break;
		        }
		    }
		    $datos_mail=new PagoRto;
		    $datos_mail->id=$turno->id;
		    $datos_mail->fecha=$turno->fecha;
		    $datos_mail->hora=$turno->hora;
		    $datos_mail->url_pago="";
		    $datos_mail->dominio=$datos_turno->dominio;
		    $datos_mail->nombre=$datos_turno->nombre;
		    try{
		        Mail::to($datos_turno->email)->send(new PagoRtoM($datos_mail));
		    }catch(\Exception $e){
		        $this->log("CRITICO", "Fallo al enviar confirmacion por pago del turno al cliente", "MAIL", 0, "", "notification");
		    }
		    $respuesta=[
		        'status' => 'ok'
		    ];
		    return $respuesta;
		}
		$respuesta=[
		        'status' => 'ok'
		    ];
		return $respuesta;
	}

    function processMPNotification($id_cobro){	
		$request_url=$this->getMPUrl().$this->mp_payments_url.$id_cobro.'?access_token='.$this->getMPToken();
		try{
		    $response = Http::get($request_url);
		}catch(\Exception $e){
		    $this->log("YACARE", "Fallo la consulta de estado del id: ".$id_cobro, "NA", 0, "", "notification");
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
		    $this->log("TABLA", "Fallo la consulta en la tabla turnos con el id de cobro: ".$id_cobro, "NA", 0, "", "notification");
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
		        $this->log("CRITICO", "No se encuentran datos del turno para confirmar a la RTO.", "CONFIRM", $turno->id, "", "notification");
		    }
		    // ACTUALIZO ESTADO DEL TURNO
		    $res_pagar=Turno::where('id',$turno->id)->update(array('estado' => "P"));
		    if(!$res_pagar){
		        $this->log("CRITICO", "Fallo al actualizar el estado del turno a pagado", "REVISAR", $turno->id, $datos_turno->nro_turno_rto, "notification");
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
		        $this->log("CRITICO", "El cobro no pudo registrarse", "REVISAR", $turno->id, $datos_turno->nro_turno_rto, "notification");
		    }
		    try{
		        $datos_mail=new PagoRto;
		        $datos_mail->id=$turno->id;
		        $datos_mail->fecha=$turno->fecha;
		        $datos_mail->hora=$turno->hora;
		        $datos_mail->url_pago="";
		        $datos_mail->dominio=$datos_turno->dominio;
		        $datos_mail->nombre=$datos_turno->nombre;
		        Mail::to($datos_turno->email)->send(new PagoRtoM($datos_mail));
		    }catch(\Exception $e){
		        $this->log("CRITICO", "Fallo al enviar confirmacion por pago del turno al cliente", "MAIL", $turno->id, $datos_turno->nro_turno_rto, "notification");
		    }
		    $respuesta=[
		        'status' => 'OK'
		    ];      
		    return response()->json($respuesta,200);
		}
		if($response["status"]=='pending' && ($response["payment_method_id"]=='rapipago' || $response["payment_method_id"]=='pagofacil')){
		    
		    $new_date_of_expiration=DateTime::createFromFormat('Y-m-d\TH:i:s.vP', $response["date_of_expiration"]);
		    $new_date_of_expiration->setTimezone(new \DateTimeZone('-0300'));
		    $new_date_of_expiration_formatted=$new_date_of_expiration->format('Y-m-d H:i:s');
		    // actualizar la tabla turnos con la nueva fecha de vencimiento
		    $res_pagar=Turno::where('id',$turno->id)->update(array('vencimiento' => $new_date_of_expiration_formatted));
		    $respuesta=[
		        'status' => 'OK'
		    ];      
		    return response()->json($respuesta,200);

		}
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
            'tipoVehiculo' => 'required|string|max:100'
        ]);
        if ($validator->fails()) { 
            $error_response=[
                'status' => 'failed',
                'message' => "Invalid data"
            ];      
            return response()->json($error_response,400);
        }
        $rto_quote_number=0;
        $tipo_vehiculo=$request->input('tipoVehiculo');
        $ignore_lines=$this->getIgnoreLines();
        $plant_name=$this->getPlantName();

        $vehicle=Precio::where('descripcion',$tipo_vehiculo)->first();
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
        if($ignore_lines){
            $lines = Linea::get();
        }else{
            $lines = Linea::where($conditions)->get();
        }
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
        if($plant_name!='sanmartin'){
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
        }else{
            $quotes=Turno::select('id', 'fecha', 'hora')->whereIn('id_linea',$quote_lines)
            ->where($available_conditions)
            ->whereIn('id_linea',$quote_lines)
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();
        $days=Turno::whereIn('id_linea',$quote_lines)
            ->where($available_conditions)
            ->whereIn('id_linea',$quote_lines)
            ->distinct()
            ->orderBy('fecha')
            ->get(['fecha']);
        }
        
        $days_array=array();
        foreach($days as $day){
            array_push($days_array,$day->fecha.'T00:00:00');
        }
        $success_response=[
            'status' => 'success',
            'tipo_vehiculo' => $tipo_vehiculo,
            'precio' => $vehicle->precio,
            'dias' => $days_array,
            'turnos' => $quotes
        ];
        return response()->json($success_response,200);
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
            'nombre' => 'required|string|max:200',
            'dominio' => 'required|string|max:20',
            'anio' => 'required|string|max:20',
            'telefono' => 'required|string|max:20',
            'combustible' => 'required|string|max:20',
            'id_turno' => 'required|integer',
            'tipo_vehiculo' => 'required|string|max:50',
            'plataforma_pago' => 'required|string|max:20'
        ]);
        if ($validator->fails()) {
            $error_response=[
                'status' => 'failed',
                'mensaje' => "Datos inválidos"
            ];      
            return response()->json($error_response,400);
        }

        
        $rto_quote_number=0;
        $request_email=$request->input("email");
        $request_name=$request->input("nombre");
        $request_domain=$request->input("dominio");
        $request_year=$request->input("anio");
        $request_phone=$request->input("telefono");
        $request_fuel=$request->input("combustible");
        $quote_id=$request->input("id_turno");
        $origin=$request->input("origen");
        $vehicle_type=$request->input("tipo_vehiculo");
        $payment_platform=$request->input("plataforma_pago");
        $plant_name=$this->getPlantName();
        $formatted_plant_name=$this->getFormattedPlantName($plant_name);

        

        $currentDate=new DateTime();
        // valido que el dominio no tenga otro turno pendiente
        if($this->getValidatePendingQuotes()){
            $duplicated_domain_list=Datosturno::where('dominio',$request_domain)->get();
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
        if($quote->estado=="R" && $payment_platform=='yacare'){
            $request_url=$this->getNotificationManagerUrl().'/notif'.'/'.$quote->id_cobro_yac;
            $response = Http::get($request_url);
            if($response->getStatusCode()==200){
                $this->processYACNotification($quote->id_cobro_yac);
                $quote=Turno::where('id',$quote_id)->first();
                if($quote->estado=='P'){
                    $error_response=[
                        'reason' => 'RECENTLY_RESERVED_QUOTE'
                    ];
                    return response()->json($error_response,404);
                }
            }
        }
        if($quote->estado=="R" && $payment_platform=='mercadoPago'){
            $request_url=$this->getMPUrl().$this->mp_search_preference_url.'?external_reference='.$quote->id_cobro_yac;
            $res_payment = Http::get($request_url);
            if($res_payment->getStatusCode()==200){
                $payment=$res_payment["results"][0];
                $payment_status=$payment["status"];
                $payment_id=$payment["id"];
                if($payment_status=='approved'){
                    $this->processMPNotification($payment_id);
                    $quote=Turno::where('id',$quote_id)->first();
                    if($quote->estado=='P'){
                        $error_response=[
                            'reason' => 'RECENTLY_RESERVED_QUOTE'
                        ];
                        return response()->json($error_response,404);
                    }
                }
            }
        }

        if($plant_name!='sanmartin'){
            $date=getDate();
            if(strlen($date["mon"])==1) $month='0'.$date["mon"]; else $month=$date["mon"];
            $currentDay=$date["year"]."-".$month."-".$date["mday"];
            $vehicle=Precio::where('descripcion',$vehicle_type)->first();
            $float_price=$vehicle->precio.'.00';
            $expiration_minutes=$this->getPaymentExpirationMinutes();
            $expiration_date=clone $currentDate;
            $expiration_date->modify('+'.$expiration_minutes.' minutes');
            $reference=$quote_id.$currentDate->format('dmYHis').$request_domain;
            if($payment_platform=='yacare'){
                $request_url=$this->getYacareUrl().$this->yacare_payments_url;
                $headers_yacare=[
                    'Authorization' => $this->getYacareToken()
                ];
                $complete_name=$request_name;
                $datos_post=[
                    "buyer" => [
                        "email" => $request_email,
                        "name" => $complete_name,
                        "surname" => ""
                    ],
                    "expirationTime" => $expiration_minutes,
                    "items" => [
                        [
                        "name" => "Turno RTO ".$formatted_plant_name,
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
                    $this->log("YACARE", "Fallo la solicitud de pago", "NA", $turno->id, $rto_quote_number, "solicitarTurno");
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
                $mp_aux_expiration_date=clone $currentDate;
                $mp_aux_expiration_date->modify('+ '.$this->getPaymentCashExpirationMinutes().' minutes');
                $mp_aux_cash_expiration_date=clone $currentDate;
                $mp_aux_cash_expiration_date->modify('+ '.$this->getPaymentCashExpirationMinutes().' minutes');
                $mp_expiration_day=$mp_aux_expiration_date->format('Y-m-d');
                $mp_expiration_time=$mp_aux_expiration_date->format('H:i:s');
                $mp_expiration_date=$mp_expiration_day.'T'.$mp_expiration_time.'.000-03:00';
                $mp_cash_expiration_day=$mp_aux_cash_expiration_date->format('Y-m-d');
                $mp_cash_expiration_time=$mp_aux_cash_expiration_date->format('H:i:s');
                $mp_cash_expiration_date=$mp_cash_expiration_day.'T'.$mp_cash_expiration_time.'.000-03:00';
                $request_url=$this->getMPUrl().$this->mp_preferences_url;
                $headers_mercadopago=[
                    'Authorization' => "Bearer ".$this->getMPToken()
                ];
                $excluded_payment_methods=[];
                if($plant_name=='lasheras' || $plant_name=='maipu'){
                    
                    $cash_methods_limit_minutes=$this->getPaymentCashExpirationMinutes()+$this->getMarginPostExpirationMinutes();
                    
                    $mp_cash_methods_limit_time=clone $expiration_date;
                    $mp_cash_methods_limit_time->modify('+'.$cash_methods_limit_minutes.' minutes');
                    $mp_cash_methods_limit_time_formatted=$mp_cash_methods_limit_time->format('Y-m-dH:i:s');
                    $quote_date=$quote->fecha.$quote->hora;
                    $allow_cash_methods=$mp_cash_methods_limit_time_formatted<$quote_date;
                    
                    if($allow_cash_methods) {
                        $excluded_payment_methods=$this->getCashExcludedPaymentMethods();
                    }else{
                        $excluded_payment_methods=$this->getExcludedPaymentMethods();
                    }
                }else{
                    $excluded_payment_methods=$this->getExcludedPaymentMethods();
                }
                $datos_post=[
                    "external_reference" => $reference,
                    "notification_url" => $this->getMPNotifUrl(),
                    "payer" => [
                        "name" => $request_name,
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
                    "payment_methods" => $excluded_payment_methods,
                    "expires" => true,
                    "expiration_date_to"=> $mp_expiration_date,
                    "date_of_expiration" => $mp_cash_expiration_date,
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
            $reservation_data=[
                'estado' => "R",
                'vencimiento' => $expiration_date,
                'id_cobro_yac' => $payment_id
            ];
        }else{
            $reservation_data=[
                'estado' => "C"
            ];
            $payment_url='';
            $expiration_minutes=0;
        }
        
        // ACTUALIZO EL ESTADO DEL TURNO A RESERVADO

        
        
        $res_reserve=Turno::where('id',$quote->id)->update($reservation_data);
        if(!$res_reserve){
            $error_response=[
                'reason' => 'BOOKING_FAILED'
            ];
            return response()->json($error_response,404);
        }

        
        $quote_data_aux_loader=[
            'nombre' => $request_name,
            'dominio' => $request_domain,
            'email' => $request_email,
            'tipo_vehiculo' => $vehicle_type,
            'marca' => "SIN ESPECIFICAR",
            'modelo' => "SIN ESPECIFICAR",
            'anio' => $request_year,
            'combustible' => $request_fuel,
            'inscr_mendoza' => "SI",
            'id_turno' => $quote->id,
            'nro_turno_rto' => $rto_quote_number,
            'telefono' => $request_phone
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
        $mail_data->url=$payment_url;
        $mail_data->dominio=$request_domain;
        $mail_data->nombre=$request_name;
        $mail_data->plant_name=$formatted_plant_name;
        $mail_data->no_payment=$this->getNoPayment();
        $mail_data->time_to_pay=$this->minutesToHours($expiration_minutes);

        try{
            Mail::to($request_email)->send(new TurnoRtoM($mail_data));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al enviar datos del turno al cliente", "MAIL", $quote->id, $rto_quote_number, "solicitarTurno");
        }
        
        $success_response=[
                'url_pago' => $payment_url
            ];
        return response()->json($success_response,200);
    }

    public function getAvailableQuotesForReschedule(Request $request){


        if($request->header('Content-Type')!="application/json"){
            $respuesta=[
                'reason' => 'INVALID_FORMAT'
            ];
                    
            return response()->json($respuesta,400);
        }

        // valido que el dato numero de turno sea un entero y se encuentre presente
        $validator = Validator::make($request->all(), [
            'id_turno' => 'required|integer'
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'reason' => 'INVALID_DATA'
            ];
                    
            return response()->json($respuesta,400);
        }

        $id_turno=$request->input('id_turno');

        $datos_turno=Datosturno::where('id_turno',$id_turno)->first();

        $fecha_tope_reschedule=new DateTime();
        $fecha_tope_reschedule->modify('-3 hours');

        if(!$datos_turno){
            $respuestaError=[
                'reason' => 'INEXISTENT QUOTE'
            ];
            
            return response()->json($respuestaError,404);
        }

        $turno=Turno::find($id_turno);

        if(!$turno){
            $respuestaError=[
                'reason' => 'INEXISTENT QUOTE'
            ];
            
            return response()->json($respuestaError,404);
        }else{
            if($turno->estado!='C'){

                $respuestaError=[
                    'reason' => 'NOT CONFIRMED QUOTE'
                ];
                return response()->json($respuestaError,404);

            }else{
                // que no sea viejo
                if($turno->fecha<=$fecha_tope_reschedule->format('Y-m-d')){
                    $respuestaError=[
                        'reason' => 'OLD QUOTE'
                    ];
                    return response()->json($respuestaError,404);
                }
            }
        }

        $vehiculo=Precio::where('descripcion',$datos_turno->tipo_vehiculo)->first();

        // $dia_actual=date("Y-m-d");
        $dia_actual=new DateTime();
        $primer_dia_turnos=$dia_actual->modify('+2 days');

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
            ['fecha','>=',$primer_dia_turnos]
        ];
        
        $turnos=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->whereIn('id_linea',$lineas_turnos)->orderBy('fecha')->orderBy('hora')->get();

        $dias=Turno::whereIn('id_linea',$lineas_turnos)->where($conditions)->whereIn('id_linea',$lineas_turnos)->distinct()->orderBy('fecha')->get(['fecha']);

        $array_dias=array();
        foreach($dias as $dia){
            array_push($array_dias,$dia->fecha.'T00:00:00');
        }

        $respuestaOK=[
            'status' => 'success',
            'tipo_vehiculo' => $datos_turno->tipo_vehiculo,
            'precio' => $vehiculo->precio,
            'dias' => $array_dias,
            'turnos' => $turnos,
            'fecha' => $turno->fecha.'T00:00:00',
            'hora' => $turno->hora
        ];
        
        return response()->json($respuestaOK,200);

    }

    public function changeQuoteDate(Request $request){

        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'reason' => 'INVALID_FORMAT'
            ];
                    
            return response()->json($respuesta,400);
        }

        $validator = Validator::make($request->all(), [
            'id_turno_ant' => 'required|integer',
            'id_turno_nuevo' => 'required|integer',
            'email' => 'required|string|max:150',
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'reason' => 'INVALID_DATA'
            ];
                    
            return response()->json($respuesta,400);
        }

        $email=$request->input("email");
        $id_turno_ant=$request->input("id_turno_ant");
        $id_turno_nuevo=$request->input("id_turno_nuevo");

        $turno_anterior=Turno::find($id_turno_ant);
        $turno_nuevo=Turno::find($id_turno_nuevo);

        if($turno_anterior->estado!="P" && $turno_anterior->estado!="C"){

            $respuesta=[
                'reason' => 'INVALID_STATUS'
            ];
                    
            return response()->json($respuesta,404);

        }

        if($turno_anterior->datos->email!=$email){

            $respuesta=[
                'reason' => "INVALID_EMAIL"
            ];
                    
            return response()->json($respuesta,404);

        }

        if(!($turno_nuevo->estado=="D")){
	        
            $respuesta=[
                'reason' => 'INVALID_STATUS'
            ];
                    
            return response()->json($respuesta,404);

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

        // vuelvo a obtener nuevo turno por sus datos nuevos de la tabla de datos
        $turno_nuevo=Turno::find($id_turno_nuevo);

        //enviar correo

        $datos_mail=new TurnoRto;
        $datos_mail->id=$turno_nuevo->id;
        $dia=substr($turno_nuevo->fecha,8,2);
        $mes=substr($turno_nuevo->fecha,5,2);
        $anio=substr($turno_nuevo->fecha,0,4);
        $datos_mail->fecha=$dia.'/'.$mes.'/'.$anio;
        $datos_mail->hora=substr($turno_nuevo->hora,0,5).' HS.';
        $datos_mail->url_pago="";
        $datos_mail->dominio=$turno_nuevo->datos->dominio;
        $datos_mail->nombre=$turno_nuevo->datos->nombre;
        $datos_mail->url_reprog=$this->getFrontData()['base_url'].$this->repro_url_suffix.$turno_nuevo->id;
        $datos_mail->url_cancel=$this->getFrontData()['base_url'].$this->cancel_url_suffix.$turno_nuevo->id;


        try{
            
            Mail::to($email)->send(new ReprogRtoQuote($datos_mail));

        }catch(\Exception $e){
            
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al enviar datos del turno al cliente",
                "fix" => "MAIL",
                "id_turno" => $turno_nuevo->id,
                "nro_turno_rto" => $turno_nuevo->datos->nro_turno_rto,
                "servicio" => "enviarCorreo"
            ];

            Logerror::insert($error);

        }

        $respuesta=[
            'status' => 'success'
        ];

        return response()->json($respuesta,200);

    }
    
    public function cancelQuote(Request $request){


        if($request->header('Content-Type')!="application/json"){   
            $respuesta=[
                'reason' => 'INVALID_FORMAT'
            ];
                    
            return response()->json($respuesta,400);
        }

        $validator = Validator::make($request->all(), [
            'id_turno' => 'required|integer',
            'email' => 'required|string|max:150',
        ]);

        if ($validator->fails()) {
            
            $respuesta=[
                'reason' => 'INVALID_DATA'
            ];
                    
            return response()->json($respuesta,400);
        }

        $email=$request->input("email");
        $id_turno=$request->input("id_turno");

        $turno=Turno::find($id_turno);

        if($turno->estado!="C"){

            $respuesta=[
                'reason' => 'INVALID_STATUS'
            ];
                    
            return response()->json($respuesta,404);

        }

        if($turno->datos->email!=$email){

            $respuesta=[
                'reason' => "INVALID_EMAIL"
            ];
                    
            return response()->json($respuesta,404);

        }

        //borrar registro en datos_turno

        $borrar_datos_tabla=Datosturno::where('id_turno',$turno->id)->delete();

        //liberar turno en tabla turnos

        $update_fields=[
            'estado'=>'D',
            'updated_at' => NULL
        ];

        $res_liberar_turno=Turno::where('id',$turno->id)->update($update_fields);
            
        if(!$res_liberar_turno){
                
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al liberar el turno",
                "fix" => "REVISAR",
                "id_turno" => $turno->id,
                "nro_turno_rto" => "NA",
                "servicio" => "cancelar turno"
            ];

            Logerror::insert($error);

        }

        //enviar cancelacion a rto

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
            $url=$this->getRtoData()['base_url'].'/api/v1/auth/rechazar/turno';
            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post($url,array('turno' => $turno->datos->nro_turno_rto));

            if( $response->getStatusCode()!=200){

                $error=[
                    "tipo" => "CRITICO",
                    "descripcion" => "Fallo al cancelar turno al RTO",
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
                "descripcion" => "Fallo al cancelar turno al RTO",
                "fix" => "CONFIRM",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $nro_turno_rto,
                "servicio" => "notification"
            ];

            Logerror::insert($error);
                        

        }

        //enviar correo

        $datos_mail=new CancelTurnoRto;
        $datos_mail->id=$turno->id;
        $dia=substr($turno->fecha,8,2);
        $mes=substr($turno->fecha,5,2);
        $anio=substr($turno->fecha,0,4);
        $datos_mail->fecha=$dia.'/'.$mes.'/'.$anio;
        $datos_mail->hora=substr($turno->hora,0,5).' HS.';
        $datos_mail->dominio=$turno->datos->patente;
        $datos_mail->nombre=$turno->datos->nombre;


        try{
            
            Mail::to($email)->send(new CancelRtoQuote($datos_mail));

        }catch(\Exception $e){
            
            $error=[
                "tipo" => "CRITICO",
                "descripcion" => "Fallo al enviar datos del turno al cliente",
                "fix" => "MAIL",
                "id_turno" => $turno->id,
                "nro_turno_rto" => $turno->datos->nro_turno_rto,
                "servicio" => "enviarCorreo"
            ];

            Logerror::insert($error);

        }

        $respuesta=[
            'status' => 'success'
        ];

        return response()->json($respuesta,200);

    }

    public function testFindNotification(Request $request){
        $paymentId=$request->input('paymentId');
        $request_url='http://localhost:3001/notif/'.$paymentId;
        $response = Http::get($request_url);
        if($response->getStatusCode()!=200){
            $respuesta=[
                'status' => 'failed'
            ];
            return response()->json($respuesta,404);
        }
        $respuesta=[
            'status' => 'success'
        ];
        return response()->json($respuesta,200);
    }
}
