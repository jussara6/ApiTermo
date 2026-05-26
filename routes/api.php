<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TermooController;

Route::post('/jogos', [TermooController::class, 'iniciarJogo']);

Route::post('/jogos/{idJogo}/tentativas', [TermooController::class, 'validarTentativa']);