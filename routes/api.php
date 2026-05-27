<?php
 
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TermooController;
 
/*
|--------------------------------------------------------------------------
| API Routes — Termoo
|--------------------------------------------------------------------------
|
| Rotas compatíveis com o front-end do professor (termorest.conradosal.com):
|
|  POST /api/jogos                        → inicia uma nova partida
|  POST /api/jogos/{idJogo}/tentativas    → valida uma tentativa
|
*/
 
Route::post('/jogos',                          [TermooController::class, 'iniciarJogo']);
Route::post('/jogos/{idJogo}/tentativas',      [TermooController::class, 'validarTentativaPorRota']);