<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Maestro\Workflow\Http\Controllers\WorkflowController;

/*
|--------------------------------------------------------------------------
| Maestro API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the MaestroServiceProvider within a route
| group with the "api" middleware applied and the configured prefix.
|
| Default prefix: /api/maestro
|
| You can customize the middleware and prefix in the maestro.php config.
|
*/

Route::get('/workflows', [WorkflowController::class, 'index'])->name('maestro.workflows.index');
Route::get('/workflows/{workflowId}', [WorkflowController::class, 'show'])->name('maestro.workflows.show');
Route::get('/workflows/{workflowId}/detail', [WorkflowController::class, 'detail'])->name('maestro.workflows.detail');
Route::post('/workflows/{workflowId}/pause', [WorkflowController::class, 'pause'])->name('maestro.workflows.pause');
Route::post('/workflows/{workflowId}/resume', [WorkflowController::class, 'resume'])->name('maestro.workflows.resume');
Route::post('/workflows/{workflowId}/cancel', [WorkflowController::class, 'cancel'])->name('maestro.workflows.cancel');
Route::post('/workflows/{workflowId}/retry', [WorkflowController::class, 'retry'])->name('maestro.workflows.retry');
Route::post('/workflows/{workflowId}/trigger', [WorkflowController::class, 'trigger'])->name('maestro.workflows.trigger');
