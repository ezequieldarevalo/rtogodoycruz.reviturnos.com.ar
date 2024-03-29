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
use App\Models\ReprogTurnoRto;
use App\Models\CancelTurnoRto;
use App\Models\Logerror;
use Validator;
use App\Exceptions\MyOwnException;
use Exception;
use Http;
use DateTime;
use App\Mail\TurnoRtoM;
use App\Mail\ReprogTurnoRtoM;
use App\Mail\CancelTurnoRtoM;
use App\Mail\TurnoRtoMReviTemp;
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

    public $change_date_suffix='/changeDate';

    public $cancel_quote_suffix='/cancelQuote';

    public function getQuotesFrontUrl(){
        return config('app.quotes_front_url');
    }

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

    public function getChangeDateUrl($quote_id){
        return $this->getQuotesFrontUrl().$this->change_date_suffix.'/'.$this->getPlantName().'/'.$quote_id;
    }

    public function getCancelQuoteUrl($quote_id){
        return $this->getQuotesFrontUrl().$this->cancel_quote_suffix.'/'.$this->getPlantName().'/'.$quote_id;
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

    public function getFormattedDate($not_formatted_date){
        $date_year=substr($not_formatted_date,0,4);
        $date_month=substr($not_formatted_date,5,2);
        $date_day=substr($not_formatted_date,8,2);
        return $date_day.'/'.$date_month.'/'.$date_year;
    }

    public function getFormattedTime($not_formatted_time){
        return substr($not_formatted_time, 0, 5).'hs.';
    }

    public function getQuotesFromVehicleType($vehicle_type){
        $ignore_lines=$this->getIgnoreLines();
        $plant_name=$this->getPlantName();
        $date_vs_expiration=new DateTime();
        $date=getDate();
        if(strlen($date["mon"])==1) $month='0'.$date["mon"]; else $month=$date["mon"];
        $currentDay=$date["year"]."-".$month."-".$date["mday"];
        $conditions=[
            "tipo_vehiculo" => $vehicle_type
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
        $quotes_info=[
            'dias' => $days_array,
            'turnos' => $quotes
        ];
        return $quotes_info;
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
        $tipo_vehiculo=$request->input('tipoVehiculo');
        

        $vehicle=Precio::where('descripcion',$tipo_vehiculo)->first();
        if(!$vehicle){
            $error_response=[
                'reason' => 'INVALID_VEHICLE'
            ];
            return response()->json($error_response,404);
        }
        $quotes=$this->getQuotesFromVehicleType($vehicle->tipo_vehiculo);
        $success_response=[
            'status' => 'success',
            'tipo_vehiculo' => $vehicle->descripcion,
            'precio' => $vehicle->precio,
            'dias' => $quotes['dias'],
            'turnos' => $quotes['turnos']
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
        $mail_data->fecha=$this->getFormattedDate($quote->fecha);
        $mail_data->hora=$this->getFormattedTime($quote->hora);
        $mail_data->url=$payment_url;
        $mail_data->dominio=$request_domain;
        $mail_data->nombre=$request_name;
        $mail_data->plant_name=$formatted_plant_name;
        $mail_data->no_payment=$this->getNoPayment();
        $mail_data->time_to_pay=$this->minutesToHours($expiration_minutes);
        $mail_data->cancel_quote_url=$this->getCancelQuoteUrl($quote->id);

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
            if($turno->estado!='C' && $turno->estado!='P'){

                $respuestaError=[
                    'reason' => 'NOT CONFIRMED QUOTE',
                    'estado' => $turno->estado
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

        $quotes=$this->getQuotesFromVehicleType($datos_turno->tipo_vehiculo);

        $success_response=[
            'status' => 'success',
            'fecha' => $turno->fecha.'T00:00:00',
            'hora' => $turno->hora,
            'tipo_vehiculo' => $datos_turno->tipo_vehiculo,
            'precio' => $vehiculo->precio,
            'dias' => $quotes['dias'],
            'turnos' => $quotes['turnos']
        ];
        
        return response()->json($success_response,200);

    }

    public function getQuoteForCancel(Request $request){


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
        $fecha_tope_reschedule=new DateTime();
        $fecha_tope_reschedule->modify('-3 hours');

        $quote=Turno::find($id_turno);

        if(!$quote){
            $respuestaError=[
                'reason' => 'INEXISTENT QUOTE'
            ];
            
            return response()->json($respuestaError,404);
        }else{
            if($quote->estado!='R' && $quote->estado!='C'){

                $respuestaError=[
                    'reason' => 'NO CANCELABLE'
                ];
                return response()->json($respuestaError,404);

            }
            // que no sea viejo
            if($quote->fecha<=$fecha_tope_reschedule->format('Y-m-d')){
                $respuestaError=[
                    'reason' => 'OLD QUOTE'
                ];
                return response()->json($respuestaError,404);
            }
            
        }

        $success_response=[
            'status' => 'success',
            'quote' => $quote
        ];
        
        return response()->json($success_response,200);

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

        if($turno_anterior->estado!="P" && $turno_anterior->estado!="C" && $turno_anterior->estado!="T"){

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

        if($turno_nuevo->estado!="D"){
	        
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


        $mail_data=new ReprogTurnoRto;
        $mail_data->id=$turno_nuevo->id;
        $mail_data->fecha=$this->getFormattedDate($turno_nuevo->fecha);
        $mail_data->hora=$this->getFormattedTime($turno_nuevo->hora);
        $mail_data->dominio=$turno_nuevo->datos->dominio;
        $mail_data->nombre=$turno_nuevo->datos->nombre;
        $mail_data->plant_name=$this->getFormattedPlantName($this->getPlantName());
        $mail_data->change_date_url=$this->getChangeDateUrl($turno_nuevo->id);
        
        try{
            Mail::to($turno_nuevo->datos->email)->send(new ReprogTurnoRtoM($mail_data));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al ReprogTurnoRtoMenviar datos del turno reprogramado al cliente", "MAIL", $turno_nuevo->id, 0, "reprogramarTurno");
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

        if($turno->estado!='R' && $turno->estado!='C'){

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

        $mail_data=new CancelTurnoRto;
        $mail_data->id=$turno->id;
        $mail_data->fecha=$this->getFormattedDate($turno->fecha);
        $mail_data->hora=$this->getFormattedTime($turno->hora);
        $mail_data->dominio=$turno->datos->dominio;
        $mail_data->nombre=$turno->datos->nombre;
        $mail_data->plant_name=$this->getFormattedPlantName($this->getPlantName());

        try{
            
            Mail::to($email)->send(new CancelTurnoRtoM($mail_data));

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
}
