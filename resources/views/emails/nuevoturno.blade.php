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
    font-size: 18px;
    font-weight: bold
}

.titulo-dato{
    font-size: 15px;
    text-decoration: underline
}

.dato{
    font-size: 12px
}
</style>
</head>

<body>
    <div class="cont">
        <span class="dato">Usted acaba de reservar un turno para realizar la RTO en la planta Centro Este - Rivadavia</span>
        <br/><br/>
        <div class="titulo-general">Detalles de su turno</div>
        
        <span class="titulo-dato">Nombre:</span><span class="dato"> &nbsp;{{ $turnomail->nombre }}</span><br/>
        <span class="titulo-dato">Dominio:</span><span class="dato"> &nbsp;{{ $turnomail->dominio }}</span><br/>
        <span class="titulo-dato">Fecha:</span><span class="dato"> &nbsp;{{ $turnomail->fecha }}</span><br/>
        <span class="titulo-dato">Hora:</span><span class="dato">&nbsp; {{ $turnomail->hora }}</span><br/>
        <span class="titulo-dato">Id de turno:</span><span class="dato">&nbsp; {{ $turnomail->id }}</span><br/>

		<span class="dato">Recuerde que a partir de este momento cuenta con 48hs para realizar el pago, de lo contrario, será automáticamente cancelado.</span><br/>
        <span class="dato">Si eligió abonar con Rapipago o Pago fácil, debe asistir con el comprobante del pago.</span><br/>
        <span class="dato">Para realizar el pago si no lo hizo, puede hacerlo haciendo <a href="{{ $turnomail->url_pago }}">click aquí</a>.</span>

    </div>
</body>
</html>