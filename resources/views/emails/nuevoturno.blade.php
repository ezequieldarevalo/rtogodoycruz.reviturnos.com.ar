<style>
h3{
    display: inline;
    font-weight: bold;

}
</style>

    <div>
        <p>Usted acaba de reservar un turno para realizar la RTO en la planta de Las Heras de Revitotal</p>
        
        <h2>Detalles de su turno:</h2>
        
        <h3>Nombre:</h3> {{ $turnomail->nombre }}<br/>
        <h3>Dominio:</h3> {{ $turnomail->dominio }}<br/>
        <h3>Fecha:</h3> {{ $turnomail->fecha }}<br/>
        <h3>Hora:</h3> {{ $turnomail->hora }}<br/>
        <h3>Id de turno:</h3> {{ $turnomail->id }}<br/>
        
        <p>Recuerde que cuenta con 48hs para realizar el pago, de lo contrario se deshabilitará.</p>
        <p>Para realizar el pago si no lo hizo, puedo hacerlo haciendo <a href="{{ $turnomail->url_pago }}">click aquí</a>.</p>


    </div>


