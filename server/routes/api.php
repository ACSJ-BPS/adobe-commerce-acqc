<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\UpgradePortfolioController;
use App\Http\Controllers\UpgradeScanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UpgradePathController;
use App\Http\Controllers\VersionRegistryController;
use Illuminate\Support\Facades\Route;

// ─── Public ──────────────────────────────────────────────────────────────────
Route::prefix('v1')->group(function () {

    Route::post('login',  [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    // ─── Authenticated ────────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Projects
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/members',         [ProjectController::class, 'addMember']);
        Route::delete('projects/{project}/members/{user}', [ProjectController::class, 'removeMember']);

        // Scans
        Route::get('projects/{project}/scans',        [ScanController::class, 'index']);
        Route::post('projects/{project}/scans',       [ScanController::class, 'store']);
        Route::get('projects/{project}/scans/{scan}', [ScanController::class, 'show']);

        // Findings
        Route::get('scans/{scan}/findings',              [FindingController::class, 'index']);
        Route::get('scans/{scan}/findings/export',       [FindingController::class, 'export']);
        Route::patch('findings/{finding}/resolve',       [FindingController::class, 'resolve']);
        Route::patch('findings/{finding}/unresolve',     [FindingController::class, 'unresolve']);

        // Suppressions
        Route::get('projects/{project}/suppressions',                       [SuppressionController::class, 'index']);
        Route::post('projects/{project}/suppressions',                      [SuppressionController::class, 'store']);
        Route::delete('projects/{project}/suppressions/{suppression}',      [SuppressionController::class, 'destroy']);

        // Upgrade scans (per-project)
        Route::get('projects/{project}/upgrade-scans',            [UpgradeScanController::class, 'index']);
        Route::get('projects/{project}/upgrade-scans/{scan}',     [UpgradeScanController::class, 'show']);
        Route::post('projects/{project}/upgrade-scans',           [UpgradeScanController::class, 'store']);

        // Upgrade portfolio, path planning & version registry
        Route::get('upgrade/portfolio-matrix',         [UpgradePortfolioController::class, 'matrix']);
        Route::get('upgrade/path/{from}/{to}',         [UpgradePathController::class, 'show']);
        Route::get('upgrade/version-registry',         [VersionRegistryController::class, 'index']);
        Route::post('upgrade/version-registry/sync',   [VersionRegistryController::class, 'sync']);

        // Admin only
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::apiResource('users', UserController::class);
        });
    });
});
