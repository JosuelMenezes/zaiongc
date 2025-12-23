<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\PrintJobController;

Route::get('/ping', fn () => ['ok' => true]);
Route::get('/health', fn () => response()->json(['ok' => true]));

// Auth
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Mesas
    Route::get('/tables', [TableController::class, 'index']);
    Route::post('/tables', [TableController::class, 'store']);

    // NOVO: pedido atual da mesa
    Route::get('/tables/{table}/current-order', [TableController::class, 'currentOrder']);

    // Pedidos
    Route::post('/orders/open', [OrderController::class, 'open']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem']);
    Route::post('/orders/{order}/items/{item}/cancel', [OrderController::class, 'cancelItem']);

    // Cozinha
    Route::post('/orders/{order}/send-kitchen', [KitchenController::class, 'send']);

    // Pagamentos
    Route::post('/orders/{order}/payments', [PaymentController::class, 'pay']);

    // Terminais
    Route::get('/terminals', [TerminalController::class, 'index']);
    Route::post('/terminals', [TerminalController::class, 'store']);

    // Turnos (Caixa)
    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::post('/shifts/{shift}/movements', [ShiftController::class, 'movement']);
    Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);
    Route::get('/shifts/{shift}/summary', [ShiftController::class, 'summary']);
    Route::get('/shifts/{shift}/report', [ShiftController::class, 'report']);

    // Print Jobs (pull-based)
    Route::get('/print-jobs', [PrintJobController::class, 'index']);
    Route::post('/print-jobs/claim', [PrintJobController::class, 'claim']);
    Route::post('/print-jobs/{printJob}/ack', [PrintJobController::class, 'ack']);
});
