<?php

declare(strict_types=1);

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/recipes/{recipe:slug}', [RecipeController::class, 'show'])->name('recipes.show');
Route::get('/search', [SearchController::class, 'index'])->name('search');
