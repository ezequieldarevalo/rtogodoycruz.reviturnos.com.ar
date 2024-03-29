<html>
<head>
<style>

html{
    width: 100%;
}

.cont{
    margin-top: 30px;
}

.titulo-general{
    font-size: 20px;
    font-weight: bold;
    text-decoration: underline;
}

.aviso-importante{
    font-size: 16px;
    font-weight: bold;
}

.titulo-dato{
    width: 80px;
    font-size: 15px;
    background-color: grey;
    border-radius: 4px;
    padding: 3px;
    display: inline-block;
    text-align:center;
}

.linea-dato{
    margin-bottom: 10px;
}

.dato{
    font-size: 14px
}

.dato-alert{
    color: red;
    font-weight: bold;
    font-size: 16px
}

p{
    line-height: 25px;
}
</style>
</head>

<body>
    <div class="cont">
        <span class="dato">Usted acaba de reservar un turno para realizar la RTO en la planta {{ $turnomail->plant_name }}</span>
        <br/>
        <div class="aviso-importante">RECUERDE QUE SU TURNO SOLO SERÁ CONFIRMADO UNA VEZ REALIZADO EL PAGO DEL MISMO</div>
        <br/>
        <div class="aviso-importante">Recuerde también que si su turno corresponde al año 2024, deberá abonar la diferencia por la tarifa vigente al momento de la inspección en el establecimiento.</div>
        <br/>
        <br/>
        <div class="titulo-general">DETALLES DE SU TURNO</div>
        <br/>
        
        <div class="linea-dato">
            <div class="titulo-dato">Nombre</div><span class="dato"> &nbsp;{{ $turnomail->nombre }}</span>
        </div>
        <div class="linea-dato">
            <span class="titulo-dato">Dominio</span><span class="dato"> &nbsp;{{ $turnomail->dominio }}</span>
        </div>
        <div class="linea-dato">
            <span class="titulo-dato">Fecha</span><span class="dato"> &nbsp;{{ $turnomail->fecha }}</span>
        </div>
        <div class="linea-dato">
            <span class="titulo-dato">Hora</span><span class="dato"> &nbsp;{{ $turnomail->hora }}</span>
        </div>
        <div class="linea-dato">
            <span class="titulo-dato">Id de turno</span><span class="dato"> &nbsp;{{ $turnomail->id }}</span>
        </div>
        <!-- if payments is active -->
        @if (!$turnomail->no_payment)
        <p>
            <span class="dato">Recuerde que a partir de este momento cuenta con {{ $turnomail->time_to_pay }}hs para realizar el pago, de lo contrario, será automáticamente cancelado.</span><br/><span class="dato">Para realizar el pago si no lo hizo, puede hacerlo haciendo <a href="#">click aquí</a>.</span>
        </p>
        @endif

        <p>
            <span class="dato">Si lo desea, puede cancelar su turno haciendo <a href="{{ $turnomail->cancel_quote_url }}">click aquí</a></span>
        </p>

    </div>
</body>
</html>
