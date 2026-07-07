<?php

use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

// Templates — manage WhatsApp message templates for the current workspace.
Route::get('/templates',            [TemplateController::class, 'index'])->name('templates.index');
Route::get('/templates/categories', [TemplateController::class, 'categories'])->name('templates.categories');
Route::post('/templates',           [TemplateController::class, 'store'])->name('templates.store');
Route::get('/templates/{id}',       [TemplateController::class, 'show'])->whereNumber('id')->name('templates.show');
Route::put('/templates/{id}',       [TemplateController::class, 'update'])->whereNumber('id')->name('templates.update');
Route::delete('/templates/{id}',    [TemplateController::class, 'destroy'])->whereNumber('id')->name('templates.destroy');
