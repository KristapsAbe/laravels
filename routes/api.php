<?php
//api.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CapsuleController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\ProfileController;

Route::post('/register', [RegisterController::class, 'register']);
Route::post('/verify-email', [RegisterController::class, 'verifyEmail']);

Route::get('/register', function() {
    return response()->json(['message' => 'GET request received, but POST is required'], 405);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::put('/user/privacy', [AuthController::class, 'updatePrivacy'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/capsule/upload-images', [CapsuleController::class, 'uploadImages']);
});
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/capsule/create', [CapsuleController::class, 'create']);
});
Route::middleware('auth:sanctum')->post('/update-profile', [ProfileController::class, 'updateProfile']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
});
Route::get('/capsules/monthly-stats', [CapsuleController::class, 'getMonthlyStats']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/friends', [FriendController::class, 'index']);
    Route::post('/friends/request', [FriendController::class, 'sendRequest']);
    Route::get('/friends/requests', [FriendController::class, 'getPendingRequests']);
    Route::post('/friends/request/{id}/accept', [FriendController::class, 'acceptRequest']);
    Route::post('/friends/request/{id}/decline', [FriendController::class, 'declineRequest']);
    Route::get('/friends/potential', [FriendController::class, 'getPotentialFriends']);
    Route::get('/friends/accepted', [FriendController::class, 'getAcceptedFriends'])->middleware('auth:sanctum');
    Route::middleware('auth:sanctum')->get('/capsules/shared', [CapsuleController::class, 'getSharedCapsules']);
    Route::post('/capsules/{id}/accept', [CapsuleController::class, 'acceptCapsule']);
    Route::put('/capsules/share/{id}/status', [CapsuleController::class, 'updateShareStatus']);
    Route::get('/capsules', [CapsuleController::class, 'index']);
    Route::get('/friends/requests/count', [FriendController::class, 'getPendingRequestsCount']);
    Route::get('/friends/count', [FriendController::class, 'getFriendCount']);
    Route::get('/capsules/count', [CapsuleController::class, 'getCapsuleCount']);
    Route::delete('/friends/{id}', [FriendController::class, 'removeFriend']);
    Route::get('/capsules/{id}', [CapsuleController::class, 'getCapsuleDetails']);
    Route::get('/friends/{friendId}/capsules', [CapsuleController::class, 'getFriendCapsules']);
});