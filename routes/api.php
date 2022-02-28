<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SimulacionController;
use App\Http\Controllers\ApiturnoController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PagosController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/



Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group([
    'prefix' => 'auth'
], function () {
    Route::post('login', [AuthController::class, 'login']);
    // Route::post('signup', [AuthController::class, 'signUp']);
    Route::post('solTur', [ApiturnoController::class, 'solicitarTurno']);
    Route::post('confQuote', [ApiturnoController::class, 'confirmQuote']);
    Route::post('valTur', [ApiturnoController::class, 'validateQuote']);
    Route::post('getQuotes', [ApiturnoController::class, 'getAvailableQuotes']);
    Route::post('notif', [PagosController::class, 'notification']);
    Route::post('notifMeli', [PagosController::class, 'notificationMeli']);
    // Route::post('regAte', [AdminController::class, 'registrarAtencion']);
    

    

    Route::group([
      'middleware' => 'auth:api'
    ], function() {
        Route::get('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::post('verify', [SimulacionController::class, 'verify']);
        Route::get('turDiaAct', [AdminController::class, 'obtenerTurnosDiaActual']);
        Route::get('turDiaFut', [AdminController::class, 'obtenerTurnosDiaFuturo']);
        Route::get('tipVeh', [AdminController::class, 'obtenerTiposVehiculo']);
        Route::post('tur', [AdminController::class, 'obtenerDatosTurno']);
        Route::post('creTur', [AdminController::class, 'crearTurno']);
        Route::post('regPag', [AdminController::class, 'registrarPago']);
        Route::get('turId', [AdminController::class, 'buscarTurnoPorId']);
    Route::get('turDom', [AdminController::class, 'buscarTurnoPorDominio']);
    Route::get('obtTurRep', [AdminController::class, 'obtenerTurnosParaReprog']);
    Route::post('repTur', [AdminController::class, 'reprogramarTurno']);
        
        
    });
});