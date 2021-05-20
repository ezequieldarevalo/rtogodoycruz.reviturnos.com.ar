<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Turno;
use App\Models\Linea;
use App\Models\Planta;
use App\Models\Feriado;
use App\Models\Franco;
use App\Models\Fd;
use App\Models\Lune;
use App\Models\Config;

class CargaInicial extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mantenimiento:cargainicial';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comando para realizar la carga inicial de turnos';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
		// importo configuraciones de la planta
		$config=Config::first();

		// establesco array con dias de la semana para utilizar como indices
        $dias_semana = array("domingo","lunes","martes","miercoles","jueves","viernes","sabado");

        //obtengo el dia de hoy
        $dia_actual=date("Y-m-d");

        //calculo el primer dia de turnos el cual corresponde a dos dias posteriores al actual
        $fecha_proceso=date("Y-m-d",strtotime($dia_actual));

        //calculo el ultimo dia de turnos
        $ultimo_dia=date("Y-m-d",strtotime($dia_actual."+ ".$config->cant_dias_disponibles." days"));

        //	importo dias fin de semana
		$fds=Fd::all();

        $dias_fds=array();
		foreach($fds as $d){
            array_push($dias_fds,$d->nro_dia);
        }

        //	importo dia lunes
		$lunes=Lune::all();

		
		// paso a un array lo obtenido
		$dias_lunes=array();
		foreach($lunes as $dia_lunes){
            array_push($dias_lunes,$dia_lunes->nro_dia);
        }


        // obtengo feriados
        $dias_feriados=array();
        $feriados=Feriado::whereBetween('fecha',[$dia_actual,$ultimo_dia])->get();
        foreach($feriados as $feriado){
            array_push($dias_feriados,$feriado->fecha);
        }


        // obtengo francos
        $dias_nolaborales=array();
        $nolaborales=Franco::all();
        foreach($nolaborales as $nolaboral){
            array_push($dias_nolaborales,$nolaboral->dia);
        }


        // inicializo array donde guardare los dias a mostrar
        $dias_laborales=array();
		$dias_laborales_fds=array();
        $dias_laborales_lunes=array();


        // obtengo la cantidad de dias a futuro que se mostraran
        // para la planta correspondiente
        $maximo=$config->cant_dias_disponibles-3;

        // obtengo el ultimo dia de turnos dado
        $res_ultimo_dia=Turno::where('origen',"T")->orderByDesc('fecha')->first();

        if($res_ultimo_dia){
            $ultimo_dia_turnos=$res_ultimo_dia->fecha;
        }else{
            $ultimo_dia_turnos=date("Y-m-d",strtotime($fecha_proceso."- 1 days"));
        }

        // echo $ultimo_dia_turnos;

        for($i=0;$i<=$maximo;$i++){
        
            $dia_semana=date('w',strtotime($fecha_proceso));

            // si el dia es feriado o no laboral no hago nada
            // si no es feriado ni laboral entonces lo agrego al nuevo array
            if(in_array($fecha_proceso, $dias_feriados) OR in_array($dia_semana, $dias_nolaborales)){
                ;
            }else{

                if($fecha_proceso>$ultimo_dia_turnos){
                    echo "Dia semana: ".$dia_semana."\n";
                    if(in_array($dia_semana, $dias_fds)){
                        array_push($dias_laborales_fds,$fecha_proceso);
                    }else{
                        if(in_array($dia_semana,$dias_lunes)){
                           array_push($dias_laborales_lunes,$fecha_proceso); 
                        }else{
                            array_push($dias_laborales,$fecha_proceso);
                        }
                    }
                }


            }

            $fecha_proceso=date("Y-m-d",strtotime($fecha_proceso."+ 1 days"));

        }

        

        $lineas=Linea::get();

   
                

        foreach($dias_laborales as $dia_laboral){

            foreach($lineas as $linea){

                $this->disponibilizarFranjas($linea,$dia_laboral,false,false);
                      
            } // fin foreach lineas

        }
		
		foreach($dias_laborales_fds as $dia_laboral){

            foreach($lineas as $linea){

                $this->disponibilizarFranjas($linea,$dia_laboral,true,false);
                      
            } // fin foreach lineas

        }

        foreach($dias_laborales_lunes as $dia_laboral){

            foreach($lineas as $linea){

                $this->disponibilizarFranjas($linea,$dia_laboral,false,true);
                      
            } // fin foreach lineas

        } 

    return "Carga de turnos finalizada.";

    }

    public function disponibilizarFranjas($linea,$dia,$fds,$lunes){
                
        if($fds){
			
			$this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1_fds,"T",$dia,$linea->desde_franja_1_fds,$linea->hasta_franja_1_fds);
			// $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_1,$linea->hasta_franja_1);
					
			// if($linea->cant_franjas==2){

			// 	$this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1_fds,"T",$dia,$linea->desde_franja_2_fds,$linea->hasta_franja_2_fds);
			// 	// $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_2,$linea->hasta_franja_2);
			// }
			
		}else{

            if($lunes){
                $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1_lunes,"T",$dia,$linea->desde_franja_1_lunes,$linea->hasta_franja_1_lunes);     
                // $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_1,$linea->hasta_franja_1);
                        
                if($linea->cant_franjas==2){

                    $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1_lunes,"T",$dia,$linea->desde_franja_2_lunes,$linea->hasta_franja_2_lunes);
                    // $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_2,$linea->hasta_franja_2);
                }
            }else{
                $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1,"T",$dia,$linea->desde_franja_1,$linea->hasta_franja_1);
                // $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_1,$linea->hasta_franja_1);
                        
                if($linea->cant_franjas==2){

                    $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_1,"T",$dia,$linea->desde_franja_2,$linea->hasta_franja_2);
                    // $this->disponibilizarFranja($linea->id,$linea->tope_por_hora_2,"A",$dia,$linea->desde_franja_2,$linea->hasta_franja_2);
                }
            }
			
		}
                
    }

    public function disponibilizarFranja($idLinea,$topePorHora,$origen,$dia,$inicio,$fin){
            
        $maxIter=$fin-$inicio;
            
        for($i=0;$i<$maxIter;$i++){

            // aca tengo que parsear la fecha que vengo trayendo a un tipo date compatible
            // con el de la tabla
                
                
            //
            $this->disponibilizarHoraTurnos($dia,$inicio,$topePorHora,$origen,$idLinea);
            $inicio++;
        }
    }

    public function disponibilizarHoraTurnos($diaTurno,$horaTurno,$topePorHora,$origen,$idLinea){

        $frecuencia=60/$topePorHora*100;
        $minTurno=0;
        while($minTurno<6000){
                    
            Turno::insert(array(
                    'fecha' => $diaTurno,
                    'hora' => $minTurno+$horaTurno*10000,
                    'estado' => "D",
                    'origen' => $origen,
                    'observaciones' => "Proceso diario",
                    'id_linea' => $idLinea,
                    'id_cobro_yac' => ""
            ));
            $minTurno=$minTurno+$frecuencia;
        }
            
    }

}