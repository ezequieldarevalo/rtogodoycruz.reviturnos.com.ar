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
use App\Models\PagoRto;
use App\Mail\PagoRtoM;
use Config;
use DateTime;

class PagosController extends Controller
{

    public $mp_payments_url='v1/payments/';

    public $yacare_transactions_url='operations-managment/operations?transaction=';

    public $rto_login_url='api/v1/auth/login';

    public $change_date_suffix='/changeDate';

    public function getQuotesFrontUrl(){
        return config('app.quotes_front_url');
    }

    public function getRtoUrl(){
        return config('rto.url');
    }

    public function getRTOConfirmQuotes(){
        return config('rto.confirm_quotes');
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

    public function getPlantName(){
        return config('app.plant_name');
    }

    public function getFormattedPlantName($name){
        if($name=='lasheras') return 'Revitotal - Las Heras';
        if($name=='maipu') return 'Revitotal - Maipu';
        if($name=='godoycruz') return 'Godoy Cruz';
        if($name=='sanmartin') return 'San Martin - Mendoza';
        if($name=='rivadavia') return 'Rivadavia';
        return '';
    }

    public function getChangeDateUrl($quote_id){
        return $this->getQuotesFrontUrl().$this->change_date_suffix.'/'.$this->getPlantName().'/'.$quote_id;
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
                $request_url=$this->getRtoUrl().$this->rto_login_url;
                $response = Http::withOptions(['verify' => false])->post($request_url,$data);
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
        $this->log("NOTIF YAC", "Llego notificacion con id: ".$request->input("id"), "GETSTATE", 0, "", "notification");
        // ALMACENO EL INPUT EN LA VARIABLE ID_COBRO
        ///////////////////////
        $yacare_prefix='Y-';
        $id_cobro=$request->input("id");;
        $id_cobro_yac=$yacare_prefix.$request->input("id");
        // BUSCO EL TURNO CON EL ID DE COBRO
        ///////////////////////
        $turno=Turno::where('id_cobro_yac',$id_cobro_yac)->first();
        // SI LA BUSQUEDA NO DA RESULTADO, REGISTRO EL ERROR
        ///////////////////////
        if(!$turno){
            $this->log("TABLA", "Fallo la consulta en la tabla turnos con el id de cobro: ".$id_cobro, "NA", 0, "", "notification");
            $respuesta=[
                'status' => 'OK'
            ];      
            return response()->json($respuesta,200);
        }
        $this->log("NOTIF YAC", "Obtuve id de turno desde el id de pago :".$request->input("id"), "GETSTATE", $turno->id, "", "notification");
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
                'status' => 'OK'
            ];      
            return response()->json($respuesta,200);
        }
        $this->log("NOTIF YAC", "Consulte estado de un pago, el resultado fue: ".$response->status(), "GETSTATE", $turno->id, "", "notification");
        // SI YACARE DA ERROR ENTONCES LO REGISTRO
        /////////////////////////////////////////////////////////////////////
        
        if( $response->status()!=200){
            $this->log("YACARE", "Fallo la consulta de estado del id: ".$id_cobro.". Posible pago no registrado.", "REVISAR", 0, "", "notification");
            $respuesta=[
                'status' => 'OK'
            ];       
            return response()->json($respuesta,200);
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
            $plant_name=$this->getPlantName();
            $formatted_plant_name=$this->getFormattedPlantName($plant_name);
            $datos_mail=new PagoRto;
            $datos_mail->id=$turno->id;
            $datos_mail->fecha=$turno->fecha;
            $datos_mail->hora=$turno->hora;
            $datos_mail->dominio=$datos_turno->dominio;
            $datos_mail->nombre=$datos_turno->nombre;
            $datos_mail->plant_name=$formatted_plant_name;
            $datos_mail->change_date_url=$this->getChangeDateUrl($turno->id);
            try{
                Mail::to($datos_turno->email)->send(new PagoRtoM($datos_mail));
            }catch(\Exception $e){
                $this->log("CRITICO", "Fallo al enviar confirmacion por pago del turno al cliente", "MAIL", 0, "", "notification");
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


    public function notificationMeli(Request $request){
        // ALMACENO EL INPUT EN LA VARIABLE ID_COBRO
        ///////////////////////
        $id_cobro=$request->input("data.id");
        // VOY A BUSCAR LOS DATOS DEL PAGO
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
                $plant_name=$this->getPlantName();
                $formatted_plant_name=$this->getFormattedPlantName($plant_name);
                $datos_mail=new PagoRto;
                $datos_mail->id=$turno->id;
                $datos_mail->fecha=$turno->fecha;
                $datos_mail->hora=$turno->hora;
                $datos_mail->dominio=$datos_turno->dominio;
                $datos_mail->nombre=$datos_turno->nombre;
                $datos_mail->plant_name=$formatted_plant_name;
                $datos_mail->change_date_url=$this->getChangeDateUrl($turno->id);
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

    public function testMail(Request $request){

        try{
            $plant_name=$this->getPlantName();
            $formatted_plant_name=$this->getFormattedPlantName($plant_name);
            $datos_mail=new PagoRto;
            $datos_mail->id=123284;
            $datos_mail->fecha="2023-03-06";
            $datos_mail->hora="10:00:00";
            $datos_mail->dominio="AF012QH";
            $datos_mail->nombre="Ezequiel Arevalo";
            $datos_mail->plant_name=$formatted_plant_name;
            $datos_mail->change_date_url=$this->getChangeDateUrl(123284);
            Mail::to("ezequiel.d.arevalo@gmail.com")->send(new PagoRtoM($datos_mail));
        }catch(\Exception $e){
            $this->log("CRITICO", "Fallo al enviar confirmacion por pago del turno al cliente", "MAIL", $turno->id, $datos_turno->nro_turno_rto, "notification");
        }
        $respuesta=[
            'status' => 'OK'
        ];      
        return response()->json($respuesta,200);
        
    }
}
