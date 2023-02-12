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
        <span class="dato">Su turno para realizar la RTO en la planta Godoy Cruz RTVO Centro Express ha sido confirmado.</span>
        <br/><br/>
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

        <p>
            <span class="dato">Para reprogramar su turno puede hacer haciendo <a href="{{ $turnomail->change_date_url }}">Click aquí</a></span>
        </p>


    </div>
</body>
</html>