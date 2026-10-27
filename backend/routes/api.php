<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AI\AiDailyReportController;
use App\Http\Controllers\AI\AiExecutiveDashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NavigationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Reports\ReportCommentController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportNotificationController;
use App\Http\Controllers\Reports\ReportReferenceController;
use App\Http\Controllers\Reports\TodayReportController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\UserManagement\ReferenceController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Controllers\WeeklyPlanning\GoogleDocsEmployeeMappingController;
use App\Http\Controllers\WeeklyPlanning\GoogleDocsFolderRegistryController;
use App\Http\Controllers\WeeklyPlanning\GoogleDocsPreviewController;
use App\Http\Controllers\WeeklyPlanning\GoogleDocsSourceController;
use App\Http\Controllers\WeeklyPlanning\WeeklyPlanController;
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
    Route::prefix('account')->group(function () {
        Route::patch('/profile', [AccountController::class, 'updateProfile']);
        Route::patch('/password', [AccountController::class, 'updatePassword']);
    });

    Route::get('/navigation', NavigationController::class);
    Route::get('/dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view');

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

    Route::get('/tasks/references', [TaskController::class, 'references'])
        ->middleware('permission:tasks.view');
    Route::get('/tasks', [TaskController::class, 'index'])
        ->middleware('permission:tasks.view');
    Route::post('/tasks', [TaskController::class, 'store'])
        ->middleware('permission:tasks.manage');
    Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus'])
        ->whereNumber('id')
        ->middleware('permission:tasks.view');
    Route::get('/tasks/{id}', [TaskController::class, 'show'])
        ->whereNumber('id')
        ->middleware('permission:tasks.view');
    Route::patch('/tasks/{id}', [TaskController::class, 'update'])
        ->whereNumber('id')
        ->middleware('permission:tasks.manage');

    Route::prefix('weekly-plans')->group(function (): void {
        Route::post('/google-docs/preview', GoogleDocsPreviewController::class)
            ->middleware([
                'permission:weekly_plans.manage_draft,organization',
                'throttle:google-docs-preview',
            ]);
        Route::post('/google-docs/import', GoogleDocsSourceController::class)
            ->middleware([
                'permission:weekly_plans.manage_draft,organization',
                'throttle:google-docs-preview',
            ]);
        Route::put('/google-docs/folder', [GoogleDocsFolderRegistryController::class, 'update'])
            ->middleware('permission:weekly_plans.google_docs.configure,organization');
        Route::delete('/google-docs/folder', [GoogleDocsFolderRegistryController::class, 'destroy'])
            ->middleware('permission:weekly_plans.google_docs.configure,organization');
        Route::get('/references', [WeeklyPlanController::class, 'references'])
            ->middleware('permission:weekly_plans.view,organization');
        Route::get('/template', [WeeklyPlanController::class, 'template'])
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::get('/', [WeeklyPlanController::class, 'index'])
            ->middleware('permission:weekly_plans.view,organization');
        Route::post('/import', [WeeklyPlanController::class, 'import'])
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::get('/{id}/google-docs/employee-mappings', [GoogleDocsEmployeeMappingController::class, 'index'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::put('/{id}/google-docs/employee-mappings', [GoogleDocsEmployeeMappingController::class, 'update'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::get('/{id}', [WeeklyPlanController::class, 'show'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.view,organization');
        Route::post('/{id}/reparse', [WeeklyPlanController::class, 'reparse'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/source/extract', [WeeklyPlanController::class, 'extractSource'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/source/replace', [WeeklyPlanController::class, 'replaceSource'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::patch('/{id}/source/group', [WeeklyPlanController::class, 'assignSourceGroup'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/source/apply', [WeeklyPlanController::class, 'applySourceEntries'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/source/ai-extract', [WeeklyPlanController::class, 'extractSourceWithAi'])
            ->whereNumber('id')
            ->middleware([
                'permission:weekly_plans.manage_draft,organization',
                'throttle:2,1',
            ]);
        Route::post('/{id}/entries', [WeeklyPlanController::class, 'storeEntry'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::patch('/{id}/entries/{entryId}', [WeeklyPlanController::class, 'updateEntry'])
            ->whereNumber('id')->whereNumber('entryId')
            ->middleware('permission:weekly_plans.view,organization');
        Route::delete('/{id}/entries/{entryId}', [WeeklyPlanController::class, 'destroyEntry'])
            ->whereNumber('id')->whereNumber('entryId')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/submit', [WeeklyPlanController::class, 'submit'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.submit,organization');
        Route::post('/{id}/analyze', [WeeklyPlanController::class, 'analyze'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.manage_draft,organization');
        Route::post('/{id}/employee-reviews', [WeeklyPlanController::class, 'reviewEmployee'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.review,organization');
        Route::post('/{id}/approve', [WeeklyPlanController::class, 'approve'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.approve,organization');
        Route::post('/{id}/publish', [WeeklyPlanController::class, 'publish'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.publish,organization');
        Route::post('/{id}/approve-and-publish', [WeeklyPlanController::class, 'approveAndPublish'])
            ->whereNumber('id')
            ->middleware([
                'permission:weekly_plans.approve,organization',
                'permission:weekly_plans.publish,organization',
            ]);
        Route::post('/{id}/comments', [WeeklyPlanController::class, 'comment'])
            ->whereNumber('id')
            ->middleware('permission:weekly_plans.view,organization');
        Route::patch('/{id}/suggestions/{suggestionId}', [WeeklyPlanController::class, 'decideSuggestion'])
            ->whereNumber('id')->whereNumber('suggestionId')
            ->middleware('permission:weekly_plans.manage_draft,organization');
    });

    Route::get('/ai/executive-dashboard', AiExecutiveDashboardController::class)
        ->middleware([
            'permission:ai.dashboard.view,organization',
            'permission:reports.view,organization',
        ]);

    // AI Daily Report V1: same Sanctum membership + backend permission boundaries.
    Route::prefix('ai/daily-reports')
        ->middleware(['permission:ai.insights.view', 'permission:reports.view'])
        ->group(function (): void {
            Route::get('/', [AiDailyReportController::class, 'index']);
            Route::get('/available-dates', [AiDailyReportController::class, 'availableDates']);
            Route::get('/summary', [AiDailyReportController::class, 'summary']);
            Route::get('/history', [AiDailyReportController::class, 'history']);
            Route::post('/', [AiDailyReportController::class, 'store'])->middleware('throttle:4,1');
            Route::get('/{id}', [AiDailyReportController::class, 'show'])->whereNumber('id');
        });

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
