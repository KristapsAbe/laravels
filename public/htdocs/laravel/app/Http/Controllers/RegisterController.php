<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Resend\Laravel\Facades\Resend;


class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|unique:users',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        $verificationCode = Str::random(6);

        VerificationCode::create([
            'email' => $request->email,
            'code' => $verificationCode,
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::raw("Your verification code is: $verificationCode", function ($message) use ($request) {
            $message->to($request->email)->subject('Email Verification');
        });

        return response()->json([
            'message' => 'Verification code sent to your email.',
        ], 200);
    }

    public function verifyEmail(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|string|email|max:255',
        'name' => 'required|string|max:255',
        'password' => 'required|string|min:8',
        'verification_code' => 'required|string|size:6',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 400);
    }

    $verificationCode = VerificationCode::where('email', $request->email)
        ->where('code', $request->verification_code)
        ->where('expires_at', '>', now())
        ->first();

    if (!$verificationCode) {
        return response()->json([
            'message' => 'Invalid or expired verification code.',
        ], 400);
    }

    if (User::where('email', $request->email)->exists()) {
        return response()->json([
            'message' => 'User with this email already exists.',
        ], 409);
    }

    $user = User::create([
        'email' => $request->email,
        'name' => $request->name,
        'password' => Hash::make($request->password),
        'email_verified_at' => now(),
    ]);

    $token = $user->createToken('auth_token')->plainTextToken;

    $verificationCode->delete();

    return response()->json([
        'message' => 'User successfully registered and verified.',
        'name' => $user->name,
        'access_token' => $token,
        'token_type' => 'Bearer',
    ], 201);
}

}