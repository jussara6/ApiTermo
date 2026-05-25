<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TermooController;

/*
|--------------------------------------------------------------------------
| API Routes — Termoo
|--------------------------------------------------------------------------
|
| POST /api/iniciar-jogo     → inicia uma nova partida
| POST /api/validar-tentativa → valida uma tentativa do jogador
|
*/

Route::post('/iniciar-jogo',      [TermooController::class, 'iniciarJogo']);
Route::post('/validar-tentativa', [TermooController::class, 'validarTentativa']);