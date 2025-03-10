<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class ProfileController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName'  => 'required|string|max:255',
            'email'     => 'required|email|max:255|unique:users,email,' . $user->id,
            'bio'       => 'nullable|string|max:1000',
            'image'     => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'confirmPassword' => 'required|string',
            'newPassword'     => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->confirmPassword, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 403);
        }

        if ($request->hasFile('image')) {
            if ($user->profile_image) {
                \Storage::delete('public/' . $user->profile_image);
            }

            $imagePath = $request->file('image')->store('profile_images', 'public');
            $user->profile_image = $imagePath;
        }

        $user->first_name = $request->firstName;
        $user->last_name = $request->lastName;
        $user->email = $request->email;
        $user->bio = $request->bio;

        if ($request->filled('newPassword')) {
            $user->password = Hash::make($request->newPassword);
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }
    public function getProfile()
    {
        $user = auth()->user();
        return response()->json([
            'user' => [
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'email' => $user->email,
                'bio' => $user->bio,
                'profileImage' => $user->profile_image
            ]
        ]);
    }
}