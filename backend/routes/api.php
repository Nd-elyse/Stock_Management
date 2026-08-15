<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MechanicController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetTicketController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RepairJobController;
use App\Http\Controllers\Api\SparePartController;
use App\Http\Controllers\Api\SparePartRequestController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\StockTransactionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TrackRepairController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;

/* =========================================================================
   PUBLIC (no auth) - login flow, marketing site contact form, public stats
   ========================================================================= */
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/cancel-otp', [AuthController::class, 'cancelOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotStart']);
    Route::post('/forgot-resend-otp', [AuthController::class, 'forgotResendOtp']);
    Route::post('/forgot-verify-otp', [AuthController::class, 'forgotVerifyOtp']);
    Route::post('/forgot-reset-password', [AuthController::class, 'forgotResetPassword']);
});
Route::post('/contact-messages', [ContactMessageController::class, 'store']);
Route::post('/password-resets', [PasswordResetTicketController::class, 'store']);
Route::get('/stats/public', [StatsController::class, 'public']);
// Rate-limited: requires both name + plate to match, but throttled anyway
// since it's an unauthenticated lookup against customer/vehicle records.
Route::post('/track-repair', [TrackRepairController::class, 'lookup'])->middleware('throttle:20,1');

/* =========================================================================
   AUTHENTICATED (any role)
   ========================================================================= */
Route::middleware('auth.token')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/session-renew', [AuthController::class, 'sessionRenew']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/stats/dashboard', [StatsController::class, 'dashboard']);

    // Notifications - read/ack is open to every role, scoped server-side.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{id}/mark-read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Repair jobs - index is scoped to "my jobs" for Mechanic inside the controller.
    Route::get('/jobs', [RepairJobController::class, 'index']);
    Route::get('/mechanics', [MechanicController::class, 'index']);

    // Spare part requests - index is scoped to "my requests" for Mechanic inside the controller.
    Route::get('/spare-part-requests', [SparePartRequestController::class, 'index']);

    // Read-only reference data every role's dashboard needs somewhere.
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/spare-parts', [SparePartController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::get('/stock-transactions', [StockTransactionController::class, 'index']);

    /* ---------------- Admin only ---------------- */
    Route::middleware('role:Admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        Route::post('/mechanics', [MechanicController::class, 'store']);
        Route::put('/mechanics/{id}', [MechanicController::class, 'update']);
        Route::delete('/mechanics/{id}', [MechanicController::class, 'destroy']);

        Route::get('/contact-messages', [ContactMessageController::class, 'index']);
        Route::put('/contact-messages/mark-all-read', [ContactMessageController::class, 'markAllRead']);
        Route::put('/contact-messages/{id}/mark-read', [ContactMessageController::class, 'markRead']);
        Route::delete('/contact-messages/{id}', [ContactMessageController::class, 'destroy']);

        Route::get('/password-resets', [PasswordResetTicketController::class, 'index']);
        Route::put('/password-resets/{id}/resolve', [PasswordResetTicketController::class, 'resolve']);
        Route::delete('/password-resets/{id}', [PasswordResetTicketController::class, 'destroy']);
    });

    /* ---------------- Admin + Receptionist + Stock Manager: notifications & broadcasts ---------------- */
    Route::middleware('role:Admin,Receptionist,Stock Manager')->group(function () {
        Route::post('/notifications', [NotificationController::class, 'store']);
        Route::put('/notifications/{id}', [NotificationController::class, 'update']);
    });

    /* ---------------- Admin + Receptionist: customers, vehicles, repair jobs, billing ---------------- */
    Route::middleware('role:Admin,Receptionist')->group(function () {
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::put('/customers/{id}', [CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);

        Route::post('/vehicles', [VehicleController::class, 'store']);
        Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
        Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy']);

        Route::post('/jobs', [RepairJobController::class, 'store']);
        Route::delete('/jobs/{id}', [RepairJobController::class, 'destroy']);

        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
        Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);

        Route::post('/payments', [PaymentController::class, 'store']);
        Route::put('/payments/{id}', [PaymentController::class, 'update']);
        Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
    });

    /* ---------------- Admin + Receptionist + Mechanic: job status/diagnostics ---------------- */
    Route::middleware('role:Admin,Receptionist,Mechanic')->group(function () {
        Route::put('/jobs/{id}', [RepairJobController::class, 'update']);
    });
    Route::middleware('role:Admin,Mechanic')->group(function () {
        Route::get('/jobs/{jobId}/diagnostics', [RepairJobController::class, 'diagnostics']);
        Route::post('/jobs/{jobId}/diagnostics', [RepairJobController::class, 'diagnostics']);
    });

    /* ---------------- Mechanic: spare part requests ---------------- */
    Route::middleware('role:Mechanic')->group(function () {
        Route::post('/spare-part-requests', [SparePartRequestController::class, 'store']);
        Route::delete('/spare-part-requests/{id}', [SparePartRequestController::class, 'destroy']);
    });

    /* ---------------- Admin + Stock Manager: inventory ---------------- */
    Route::middleware('role:Admin,Stock Manager')->group(function () {
        Route::put('/spare-part-requests/{id}/approve', [SparePartRequestController::class, 'approve']);
        Route::put('/spare-part-requests/{id}/reject', [SparePartRequestController::class, 'reject']);
        Route::delete('/stock-transactions/{id}', [StockTransactionController::class, 'destroy']);
    });

    /* ---------------- Stock Manager (+ Admin): inventory writes ---------------- */
    Route::middleware('role:Stock Manager,Admin')->group(function () {
        Route::post('/spare-parts', [SparePartController::class, 'store']);
        Route::put('/spare-parts/{id}', [SparePartController::class, 'update']);
        Route::delete('/spare-parts/{id}', [SparePartController::class, 'destroy']);
        Route::post('/spare-parts/{id}/adjust', [SparePartController::class, 'adjust']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::delete('/purchases/{id}', [PurchaseController::class, 'destroy']);
    });
});
