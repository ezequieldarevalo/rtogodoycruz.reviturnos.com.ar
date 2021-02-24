<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SimulacionController;
use App\Http\Controllers\ApiturnoController;

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
    Route::post('signup', [AuthController::class, 'signUp']);
    Route::get('respYac', [ApiturnoController::class, 'respuestaYacare']);
    Route::get('getToken', [ApiturnoController::class, 'solicitarToken']);
    Route::post('getTurnos', [ApiturnoController::class, 'obtenerTurnos']);
    Route::post('getTurnosProv', [ApiturnoController::class, 'obtenerTurnosProv']);
    Route::post('solTur', [ApiturnoController::class, 'solicitarTurno']);
    Route::post('solTurProv', [ApiturnoController::class, 'solicitarTurnoProv']);
    Route::post('token', [ApiturnoController::class, 'obtenerToken']);
    Route::post('valTur', [ApiturnoController::class, 'validarTurno']);

    Route::group([
      'middleware' => 'auth:api'
    ], function() {
        Route::get('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::post('verify', [SimulacionController::class, 'verify']);
        Route::post('obtTur', [ApiturnoController::class, 'obtenerTurnos']);
    });
});