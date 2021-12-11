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
                 'email' => 'rtogodoycruz@gmail.com',
                 'password' => 'Rto93228370330'
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
        // $nuevoToken=[
        //     'status' => 'success',
        //     'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiYWRmZDg4MGNjMzA2MjkyMTY3NWQ5NWQzNjFjY2ViOWJmNjIxZWRiNGE3Njk1YTIzNzRjMTYyMzgzNDk0YTJmZmEyZjA2NGZhZDFkMjRmMjQiLCJpYXQiOjE2MjU0MDEyODIsIm5iZiI6MTYyNTQwMTI4MiwiZXhwIjoxNjU2OTM3MjgyLCJzdWIiOiI1Iiwic2NvcGVzIjpbXX0.J5tnKOeZefum5f5vsovdb8O0TRjWHZocbSJvuN3G0HyDkRWiCE0grP_eRmX0XHPLr8WlZ9V1g7KUtrVGPiGQ3UsVv4QPV4ntava_Yju68lcU1qW3I4nOT1NcTdbu6sP3Uov_kNpFpqwGV8T8qivgdUIG0R3kUfuSK-2oYlUemPeVtvLaHj5HHXLu6uPQnw-_M97jJ8q65YXAcxiUrHKbu2Hvws4vtV8UkDwXJfWx3TK7p-PiiinTBgT1QgE5_F1ZAR6ikvnJ3T1jhRzvZrW2PatWUJbA1EFyu_qUAi24wbP1B__w6_8dAW7PA3_-RHrphIJTuEKvfsDAHOWhPUq293GP2cZWry4EAW50pgCZDxh_bE_b4g5sYVptc44ALFtoiQhz8vD58lM3zxfVWxTh6c8uNzhbmjVjvQJl4kgZYEkzgfPxHqTC418A_bZSbb0t6RxbqSJmZYg8RVvaBMyTSSpz5m9hFPT8WqgVdKLeIe3USNDVM-Qi_Rd74id5UixVnKt4zulXRgYiWKvr2AQY9pzIeyrMeHEvj53FV8zJpBkKsyHyq0zkr0kLgst5rPccweYmYco51VBJofpuFMT7nLiu8jyL6Y-6Y4OnS8X1VSXSozz8HmR6n7sWOlEPsZbo41IObFwYzsCtcDoTM9TP6gZIJIbdyUf1_vMoqyH9CR4'
        // ];


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

            $response = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/turno',$data);
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
        
        $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxMDc4OSIsImlhdCI6MTYzNzkzMzMwOCwiZXhwIjoxNjY5NDkwMjYwLCJPSUQiOjEwNzg5LCJUSUQiOiJZQUNBUkVfQVBJIn0.66FWRwSDonmK-5GiIDOPMSDSnLL0ZB4PI5m8J8mrmFJQsbqgQwLUB7voz2AqxdBOHEYTjuraitmSEXxvbHNsIg';
        
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
            "notificationURL" => "https://rtogodoycruz.reviturnos.com.ar/api/auth/notif",
            "redirectURL" => "https://turnosrtogc.reviturnos.com.ar/confirmado",
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
        // $nuevoToken=[
        //     'status' => 'success',
        //     'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiYWRmZDg4MGNjMzA2MjkyMTY3NWQ5NWQzNjFjY2ViOWJmNjIxZWRiNGE3Njk1YTIzNzRjMTYyMzgzNDk0YTJmZmEyZjA2NGZhZDFkMjRmMjQiLCJpYXQiOjE2MjU0MDEyODIsIm5iZiI6MTYyNTQwMTI4MiwiZXhwIjoxNjU2OTM3MjgyLCJzdWIiOiI1Iiwic2NvcGVzIjpbXX0.J5tnKOeZefum5f5vsovdb8O0TRjWHZocbSJvuN3G0HyDkRWiCE0grP_eRmX0XHPLr8WlZ9V1g7KUtrVGPiGQ3UsVv4QPV4ntava_Yju68lcU1qW3I4nOT1NcTdbu6sP3Uov_kNpFpqwGV8T8qivgdUIG0R3kUfuSK-2oYlUemPeVtvLaHj5HHXLu6uPQnw-_M97jJ8q65YXAcxiUrHKbu2Hvws4vtV8UkDwXJfWx3TK7p-PiiinTBgT1QgE5_F1ZAR6ikvnJ3T1jhRzvZrW2PatWUJbA1EFyu_qUAi24wbP1B__w6_8dAW7PA3_-RHrphIJTuEKvfsDAHOWhPUq293GP2cZWry4EAW50pgCZDxh_bE_b4g5sYVptc44ALFtoiQhz8vD58lM3zxfVWxTh6c8uNzhbmjVjvQJl4kgZYEkzgfPxHqTC418A_bZSbb0t6RxbqSJmZYg8RVvaBMyTSSpz5m9hFPT8WqgVdKLeIe3USNDVM-Qi_Rd74id5UixVnKt4zulXRgYiWKvr2AQY9pzIeyrMeHEvj53FV8zJpBkKsyHyq0zkr0kLgst5rPccweYmYco51VBJofpuFMT7nLiu8jyL6Y-6Y4OnS8X1VSXSozz8HmR6n7sWOlEPsZbo41IObFwYzsCtcDoTM9TP6gZIJIbdyUf1_vMoqyH9CR4'
        // ];

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

            $res_info_turno = Http::withOptions(['verify' => false])->withToken($nuevoToken["token"])->post('https://rto.mendoza.gov.ar/api/v1/auth/turno',$data);

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

        
        $fecha_vencimiento=$fecha_actual->modify('+2 days');
        $referencia=$id_turno.$fecha_actual->format('dmYHis').$datos_turno["patente"];
        // $referencia=$id_turno.$fecha_actual->format('dmYHis');

        

        if($plataforma_pago=='yacare'){
        
            $url_request='https://api.yacare.com/v1/payment-orders-managment/payment-order';
            // $url_request='https://core.demo.yacare.com/api-homologacion/v1/payment-orders-managment/payment-order';
                
            // conseguir token yacare
            // $token_request_desa='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxNDQ4IiwiaWF0IjoxNjEzMzQ3NjY1LCJleHAiOjE2NDQ5MDQ2MTcsIk9JRCI6MTQ0OCwiVElEIjoiWUFDQVJFX0FQSSJ9.ElFX4Bo1H-qyuuVZA0RW6JpDH7HjltV8cJP_qzDpNerD-24BdZB8QlD65bGdy2Vc0uT0FzYmsev9vlVz9hQykg';
                
            $token_request='eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiIxMDc4OSIsImlhdCI6MTYzNzkzMzMwOCwiZXhwIjoxNjY5NDkwMjYwLCJPSUQiOjEwNzg5LCJUSUQiOiJZQUNBUkVfQVBJIn0.66FWRwSDonmK-5GiIDOPMSDSnLL0ZB4PI5m8J8mrmFJQsbqgQwLUB7voz2AqxdBOHEYTjuraitmSEXxvbHNsIg';
            
            $nombre_completo=$datos_turno["nombre"].' '.$datos_turno["apellido"];

            $datos_post=[
                "buyer" => [
                    "email" => $email_solicitud,
                    "name" => $nombre_completo,
                    "surname" => ""
                ],
                "expirationTime" => 2880,
                "items" => [
                    [
                    "name" => "Turno RTO Rivadavia",
                    "quantity" => "1",
                    "unitPrice" => $precio_float
                    ]
                ],
                "notificationURL" => "https://rtogodoycruz.reviturnos.com.ar/api/auth/notif",
                "redirectURL" => "https://turnosrtogc.reviturnos.com.ar/confirmed",
                "reference" => $referencia
            ];
            
            $headers_yacare=[
                'Authorization' => $token_request
            ];

            try{
                
                $res_yacare = Http::withHeaders($headers_yacare)->post($url_request,$datos_post);

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

            if( $res_yacare->getStatusCode()!=200){

                $respuestaError=[
                    'reason' => 'YACARE_ERROR'
                ];
                return response()->json($respuestaError,404);
                
            }


            $id_cobro='Y-'.$res_yacare["paymentOrderUUID"];
            $url_pago=$res_yacare["paymentURL"];

        }else{

            $fecha_vencimiento_aux_mp=$fecha_actual->modify('+21 hours');
            $dia_vencimiento_mp=$fecha_vencimiento_aux_mp->format('Y-m-d');
            $hora_vencimiento_mp=$fecha_vencimiento_aux_mp->format('H:i:s');
            $fecha_vencimiento_mp=$dia_vencimiento_mp.'T'.$hora_vencimiento_mp.'.000-00:00';
            // $url_request="https://api.mercadopago.com/checkout/preferences";
            // $token_request="Bearer APP_USR-5150441327591477-070520-9c02fe96f0c292d0fa40340ab964b8bc-15129767";
            $url_request="https://api.test.mercadopago.com/checkout/preferences";
            $token_request="Bearer APP_USR-515044132759147dfsdf7-070520-9c02fe96f0c292d0fa40340ab964b8bc-15129767";

            $headers_mercadopago=[
                'Authorization' => $token_request
            ];

            $datos_post=[
                "external_reference" => $referencia,
                "notification_url" => "https://rtogodoycruz.reviturnos.com.ar/api/auth/notifMeli",
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
                        ]
                    ]
                ],
                "expires" => true,
                "expiration_date_to" => $fecha_vencimiento_mp,
                "date_of_expiration"=> $fecha_vencimiento_mp
            ];

            try{
                
                $res_mp = Http::withHeaders($headers_mercadopago)->post($url_request,$datos_post);

            }catch(\Exception $e){

                $error=[
                    "tipo" => "MERCADO PAGO",
                    "descripcion" => "Fallo la solicitud de pago",
                    "fix" => "NA",
                    "id_turno" => $turno->id,
                    "nro_turno_rto" => $nro_turno_rto
                ];

                Logerror::insert($error);

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
        $datos_mail->url_pago=$url_pago;
        $datos_mail->dominio=$datos_turno["patente"];
        $datos_mail->nombre=$datos_turno["nombre"].' '.$datos_turno["apellido"];


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
        // $nuevoToken=[
        //     'status' => 'success',
        //     'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiIxIiwianRpIjoiYWRmZDg4MGNjMzA2MjkyMTY3NWQ5NWQzNjFjY2ViOWJmNjIxZWRiNGE3Njk1YTIzNzRjMTYyMzgzNDk0YTJmZmEyZjA2NGZhZDFkMjRmMjQiLCJpYXQiOjE2MjU0MDEyODIsIm5iZiI6MTYyNTQwMTI4MiwiZXhwIjoxNjU2OTM3MjgyLCJzdWIiOiI1Iiwic2NvcGVzIjpbXX0.J5tnKOeZefum5f5vsovdb8O0TRjWHZocbSJvuN3G0HyDkRWiCE0grP_eRmX0XHPLr8WlZ9V1g7KUtrVGPiGQ3UsVv4QPV4ntava_Yju68lcU1qW3I4nOT1NcTdbu6sP3Uov_kNpFpqwGV8T8qivgdUIG0R3kUfuSK-2oYlUemPeVtvLaHj5HHXLu6uPQnw-_M97jJ8q65YXAcxiUrHKbu2Hvws4vtV8UkDwXJfWx3TK7p-PiiinTBgT1QgE5_F1ZAR6ikvnJ3T1jhRzvZrW2PatWUJbA1EFyu_qUAi24wbP1B__w6_8dAW7PA3_-RHrphIJTuEKvfsDAHOWhPUq293GP2cZWry4EAW50pgCZDxh_bE_b4g5sYVptc44ALFtoiQhz8vD58lM3zxfVWxTh6c8uNzhbmjVjvQJl4kgZYEkzgfPxHqTC418A_bZSbb0t6RxbqSJmZYg8RVvaBMyTSSpz5m9hFPT8WqgVdKLeIe3USNDVM-Qi_Rd74id5UixVnKt4zulXRgYiWKvr2AQY9pzIeyrMeHEvj53FV8zJpBkKsyHyq0zkr0kLgst5rPccweYmYco51VBJofpuFMT7nLiu8jyL6Y-6Y4OnS8X1VSXSozz8HmR6n7sWOlEPsZbo41IObFwYzsCtcDoTM9TP6gZIJIbdyUf1_vMoqyH9CR4'
        // ];

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
                'url_pago' => $url_pago
            ];

        return response()->json($respuesta,200);

    }

    

}
