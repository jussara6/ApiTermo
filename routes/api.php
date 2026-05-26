<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TermooController;

Route::post('/iniciar-jogo', [TermooController::class, 'iniciarJogo']);

Route::post('/validar-tentativa', [TermooController::class, 'validarTentativa']);