<?php

declare(strict_types=1);

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeEditController;
use App\Http\Controllers\RecipeIndexController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShoppingListController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/recipes', [RecipeIndexController::class, 'show'])->name('recipes.index');
Route::get('/recipes/{recipe:slug}', [RecipeController::class, 'show'])->name('recipes.show');
Route::get('/recipes/{recipe:slug}/edit', [RecipeEditController::class, 'edit'])->name('recipes.edit');
Route::post('/recipes/{recipe:slug}/edit', [RecipeEditController::class, 'update'])->name('recipes.update');
Route::post('/recipes/{recipe:slug}/edit/parse', [RecipeEditController::class, 'parse'])->name('recipes.edit.parse');
Route::post('/recipes/{recipe:slug}/edit/serialize', [RecipeEditController::class, 'serialize'])->name('recipes.edit.serialize');
Route::post('/recipes/{recipe:slug}/edit/preview', [RecipeEditController::class, 'preview'])->name('recipes.edit.preview');
Route::get('/recipes/{recipe:slug}/cook', [RecipeController::class, 'cook'])->name('recipes.cook');
Route::get('/search', [SearchController::class, 'index'])->name('search');

Route::get('/shopping-list', [ShoppingListController::class, 'show'])->name('shopping-list');
Route::post('/shopping-list/calculate', [ShoppingListController::class, 'calculate'])
    ->name('shopping-list.calculate');
