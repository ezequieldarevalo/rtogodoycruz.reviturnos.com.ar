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
		// importo configuraciones de la planta
		$config=Config::first();

		// establesco array con dias de la semana para utilizar como indices
        $week_days = array("sunday","monday","tuesday","wednesday","thursday","friday","saturday");

        

        //obtengo el dia de hoy
        $current_date=date("Y-m-d");

        //calculo el primer dia de turnos el cual corresponde a dos dias posteriores al actual
        $process_date=date("Y-m-d",strtotime($current_date));

        //calculo el ultimo dia de turnos
        // $last_day=date("Y-m-d",strtotime($current_date."+ ".$config->cant_dias_disponibles." days"));
        $last_day=date("Y-m-d",strtotime($current_date."+ 3 days"));

        //	importo dias
		$days_config=Day::all();

		// paso a un array lo obtenido
		$days=array();
		foreach($days_config as $day_config){
            array_push($days,$day_config);
        }
        // echo "lunes_desde ";
        // echo $days[1]->lunes_desde;
        
        
        // obtengo feriados
        $holiday_days=array();
        $holidays=Feriado::whereBetween('fecha',[$current_date,$last_day])->get();
        foreach($holidays as $holiday){
            array_push($holiday_days,$holiday->fecha);
        }

        // obtengo la cantidad de dias a futuro que se mostraran
        // para la planta correspondiente
        $maximo=$config->cant_dias_disponibles;

        $monday_days=array();
        $tuesday_days=array();
        $wednesday_days=array();
        $thursday_days=array();
        $friday_days=array();
        $saturday_days=array();
        $sunday_days=array();

        // creo array solo con dias laborales (no feriados)
        for($i=1;$i<=$maximum;$i++){

            echo "\r\n";
            echo $i;
            echo "\r\n";
        
            $week_day=date('w',strtotime($process_date));

            echo $week_days[$week_day];
            echo "\r\n";

            if(!in_array($process_date, $holiday_days)){
                switch($week_days[$week_day]){
                    case 'sunday': array_push($sunday_days,$process_date);break;
                    case 'monday': array_push($monday_days,$process_date);break;
                    case 'tuesday': array_push($tuesday_days,$process_date);break;
                    case 'wednesday': array_push($wednesday_days,$process_date);break;
                    case 'thursday': array_push($thursday_days,$process_date);break;
                    case 'friday': array_push($friday_days,$process_date);break;
                    case 'saturday': array_push($saturday_days,$process_date);break;
                }
            }

            $process_date=date("Y-m-d",strtotime($process_date."+ 1 days"));

        }

        $lines=Linea::get();

        foreach($monday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"monday",$days);   
            }
        }

        foreach($tuesday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"tuesday",$days);    
            }
        }

        foreach($wednesday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"wednesday",$days);       
            }
        }

        foreach($thursday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"thursday",$days);       
            }
        }

        foreach($friday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"friday",$days);      
            }
        }

        foreach($saturday_days as $day){
            echo "pepe";
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"saturday",$days);      
            }
        }

        foreach($sunday_days as $day){
            foreach($lines as $line){
                $this->disponibilizarFranjas($line,$day,"sunday",$days);   
            }
        } 

        echo "Carga de turnos finalizada.";

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
                echo "Turno creado.";
                echo "\r\n";
            }else{
                echo "Turno existente.";
                echo "\r\n";
            }
            $minTurno=$minTurno+$frecuencia;
        }  
    }
}
