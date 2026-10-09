<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Reports\ReportCommentController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportNotificationController;
use App\Http\Controllers\Reports\ReportReferenceController;
use App\Http\Controllers\Reports\TodayReportController;
use App\Http\Controllers\UserManagement\ReferenceController;
use App\Http\Controllers\UserManagement\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'kps-backend',
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('/me', [AuthenticatedSessionController::class, 'show'])
        ->middleware(['auth:sanctum', 'active_kps_membership']);

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth:sanctum');
});

Route::middleware(['auth:sanctum', 'active_kps_membership'])->group(function () {
    Route::get('/navigation', NavigationController::class);

    Route::get('/reports/today', TodayReportController::class);
    Route::get('/reports/references', ReportReferenceController::class)
        ->middleware('permission:reports.view');
    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view');
    Route::post('/reports', [ReportController::class, 'store'])
        ->middleware(['report_participant', 'permission:reports.create']);
    Route::post('/reports/{id}/comments', [ReportCommentController::class, 'store'])
        ->whereNumber('id')
        ->middleware(['permission:reports.view', 'permission:reports.comment']);
    Route::patch('/reports/{id}/notifications/read', [ReportNotificationController::class, 'update'])
        ->whereNumber('id');
    Route::get('/reports/{id}', [ReportController::class, 'show'])
        ->whereNumber('id')
        ->middleware('permission:reports.view');
    Route::patch('/reports/{id}', [ReportController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['report_participant', 'permission:reports.create']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])
        ->whereNumber('id');

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.view,organization');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware([
            'permission:users.create,organization',
            'permission:users.assign_role,organization',
        ]);
    Route::get('/users/{id}', [UserController::class, 'show'])
        ->whereNumber('id')
        ->middleware('permission:users.view,organization');
    Route::patch('/users/{id}', [UserController::class, 'update'])
        ->whereNumber('id')
        ->middleware('permission:users.update,organization');
    Route::patch('/users/{id}/role', [UserController::class, 'updateRole'])
        ->whereNumber('id')
        ->middleware('permission:users.assign_role,organization');
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus'])
        ->whereNumber('id')
        ->middleware('permission:users.disable,organization');

    Route::prefix('reference')->middleware('permission:users.view,organization')->group(function () {
        Route::get('/roles', [ReferenceController::class, 'roles']);
        Route::get('/departments', [ReferenceController::class, 'departments']);
        Route::get('/locations', [ReferenceController::class, 'locations']);
    });
});
