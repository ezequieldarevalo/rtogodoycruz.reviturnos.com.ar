<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Turno;
use App\Models\Linea;
use App\Models\Planta;
use App\Models\Feriado;
use App\Models\Config;
use App\Models\Day;

class QuoteCreator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mantenimiento:quotecreator';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea o actualiza turnos';

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

    private $english_to_spanish=[
        "sunday" => "domingo",
        "monday" => "lunes",
        "tuesday" => "martes",
        "wednesday" => "miercoles",
        "thursday" => "jueves",
        "friday" => "viernes",
        "saturday" => "sabado"
    ];
    
    public function handle()
    {

        $weekdays = array("sunday","monday","tuesday","wednesday","thursday","friday","saturday");
		// importo configuraciones de la planta
		$config=Config::first();
        //obtengo el dia de hoy
        $current_date=date("Y-m-d");
        //calculo el primer dia de turnos el cual corresponde a dos dias posteriores al actual
        $process_date=date("Y-m-d",strtotime($current_date));
        //calculo el ultimo dia de turnos
        $last_day=date("Y-m-d",strtotime($current_date."+ ".$config->cant_dias_disponibles." days"));
        //	importo dias
		$days_config=Day::all();
		// paso a un array lo obtenido
		$days=array();
		foreach($days_config as $day_config){
            array_push($days,$day_config);
        }        
        // obtengo feriados
        $holiday_days=array();
        $holidays=Feriado::whereBetween('fecha',[$current_date,$last_day])->get();
        foreach($holidays as $holiday){
            array_push($holiday_days,$holiday->fecha);
        }
        // obtengo la cantidad de dias a futuro que se mostraran
        // para la planta correspondiente
        $maximum=$config->cant_dias_disponibles;
        $workdays=array();
        // creo array solo con dias laborales (no feriados)
        for($i=1;$i<=$maximum;$i++){
        
            $weekday=date('w',strtotime($process_date));
            $day_info=[
                "date" => $process_date,
                "weekday" => $weekdays[$weekday]
            ];
            if(!in_array($process_date, $holiday_days)){
                array_push($workdays,$day_info);
            }
            $process_date=date("Y-m-d",strtotime($process_date."+ 1 days"));

        }
        $lines=Linea::get();
        foreach($workdays as $workday){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$workday["date"],$workday["weekday"],$days);   
            }
        }
        return 0;
    }

    public function getFromTime($month,$days,$day_name){
        
        $key=$this->english_to_spanish[$day_name]."_desde";
        return $days[$month][$key];
    }

    public function getToTime($month,$days,$day_name){
        
        $key=$this->english_to_spanish[$day_name]."_hasta";
        return $days[$month][$key];
    }

    public function disponibilizarFranjas($line,$day,$day_name,$days){
        
        $current_month=(int)date('m',strtotime($day));
        $from_time=$this->getFromTime($current_month,$days,$day_name);
        $to_time=$this->getToTime($current_month,$days,$day_name);

		// dejo abierto para agregar una posible segunda franja horaria a futuro

        if($from_time!=0 || $to_time!=0){
            $this->disponibilizarFranja($line->id,$line->tope_por_hora_1,"T",$day,$from_time,$to_time);
        }
    }

    public function disponibilizarFranja($idLinea,$topePorHora,$origen,$dia,$inicio,$fin){
            
        $maxIter=$fin-$inicio; 
        for($i=0;$i<$maxIter;$i++){
            $this->disponibilizarHoraTurnos($dia,$inicio,$topePorHora,$origen,$idLinea);
            $inicio++;
        }
    }

    public function disponibilizarHoraTurnos($diaTurno,$horaTurno,$topePorHora,$origen,$idLinea){

        $frecuencia=60/$topePorHora*100;
        $minTurno=0;
        while($minTurno<6000){
            $time=$minTurno+$horaTurno*10000;
            $conditions=[
                ['fecha' ,'=', $diaTurno],
                ['hora' ,'=', $time],
                ['id_linea' ,'=', $idLinea]
            ];
            $exists=Turno::where($conditions)->first();
            if(!$exists){
                Turno::insert(array(
                    'fecha' => $diaTurno,
                    'hora' => $time,
                    'estado' => "D",
                    'origen' => $origen,
                    'observaciones' => "Proceso diario",
                    'id_linea' => $idLinea,
                    'id_cobro_yac' => ""
                ));
            }
            $minTurno=$minTurno+$frecuencia;
        }  
    }
}
