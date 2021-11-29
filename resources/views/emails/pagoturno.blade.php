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
        <span class="dato">Su turno para realizar la RTO en la planta Godoy Cruz RTVO Centro Express ha sido confirmado.</span>
        <br/><br/>
        <div class="titulo-general">Detalles de su turno</div>
        
        <span class="titulo-dato">Nombre:</span><span class="dato"> &nbsp;{{ $turnomail->nombre }}</span><br/>
        <span class="titulo-dato">Dominio:</span><span class="dato"> &nbsp;{{ $turnomail->dominio }}</span><br/>
        <span class="titulo-dato">Fecha:</span><span class="dato"> &nbsp;{{ $turnomail->fecha }}</span><br/>
        <span class="titulo-dato">Hora:</span><span class="dato">&nbsp; {{ $turnomail->hora }}</span><br/>
        <span class="titulo-dato">Id de turno:</span><span class="dato">&nbsp; {{ $turnomail->id }}</span><br/>

		<span class="dato">Muchas gracias por confiar en nosotros.</span><br/>

    </div>
</body>
</html>