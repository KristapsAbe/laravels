<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email|max:255',
                'password' => 'required|string|min:8',
            ], [
                'email.required' => 'Email field cannot be empty.',
                'password.required' => 'Password field cannot be empty.',
            ]);

            if (Auth::attempt($credentials)) {
                $user = Auth::user();
                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $user,
                ], 200);
            } else {
                return response()->json([
                    'message' => 'The provided credentials are incorrect.'
                ], 401);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }
    public function getUser(Request $request)
{
    return response()->json($request->user());
}
public function updatePrivacy(Request $request)
    {
        $request->validate([
            'privacy' => 'required|in:public,private,friends_only',
        ]);

        $user = Auth::user();
        $user->privacy = $request->privacy;
        $user->save();

        return response()->json([
            'message' => 'Privacy settings updated successfully',
            'privacy' => $user->privacy
        ]);
    }

}