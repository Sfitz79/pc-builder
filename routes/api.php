<?php

use App\Http\Controllers\Api\BuildRecommendationController;
use App\Http\Controllers\Api\PcComponentController;
use App\Http\Controllers\Api\PriceComparisonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// PC Component Scraping APIs
Route::prefix('components')->group(function () {
    Route::get('/search/amazon', [PcComponentController::class, 'searchAmazon']);
    Route::get('/search/newegg', [PcComponentController::class, 'searchNewegg']);
    Route::get('/search/ebay', [PcComponentController::class, 'searchEbay']);
    Route::get('/storage/pricing', [PcComponentController::class, 'getStoragePricing']);
    Route::get('/storage/cheapest/ssds', [PcComponentController::class, 'getCheapestSSDs']);
    Route::get('/storage/cheapest/hdds', [PcComponentController::class, 'getCheapestHDDs']);
});

// Price Comparison & Build Tools
Route::prefix('build')->group(function () {
    Route::post('/recommend', [BuildRecommendationController::class, 'recommend']);
    Route::post('/compare-prices', [PriceComparisonController::class, 'comparePrices']);
    Route::get('/search/buywhere', [PriceComparisonController::class, 'searchBuyWhere']);
    Route::post('/check-compatibility', [PriceComparisonController::class, 'checkCompatibility']);
    Route::post('/summary', [PriceComparisonController::class, 'getBuildSummary']);
});
