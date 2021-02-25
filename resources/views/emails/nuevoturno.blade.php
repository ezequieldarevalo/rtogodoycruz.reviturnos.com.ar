<html>
<head>
<style>

html{
    width: 100%;
}

.cont{
    margin-left: 20px;
}

.titulo{
    font-size: 25px;
}

.dato{
    font-size: 20px;
    font-weight: bold
}
</style>
</head>

<body>
    <div class="cont">
        <p>Usted acaba de reservar un turno para realizar la RTO en la planta RTO Rivadavia</p>
        
        <div class="titulo">Detalles de su turno:</div>
        
        <span class="dato">Nombre:&nbsp;</span> {{ $turnomail->nombre }}<br/>
        <span class="dato">Dominio:&nbsp;</span> {{ $turnomail->dominio }}<br/>
        <span class="dato">Fecha:&nbsp;</span> {{ $turnomail->fecha }}<br/>
        <span class="dato">Hora:&nbsp;</span> {{ $turnomail->hora }}<br/>
        <span class="dato">Id de turno:&nbsp;</span> {{ $turnomail->id }}<br/>
        
        <p>Recuerde que cuenta con 48hs para realizar el pago, de lo contrario se deshabilitará.</p>
        <p>Para realizar el pago si no lo hizo, puedo hacerlo haciendo <a href="{{ $turnomail->url_pago }}">click aquí</a>.</p>


    </div>
</body>
</html>




