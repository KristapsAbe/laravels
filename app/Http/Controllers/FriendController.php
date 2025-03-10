<?php

namespace App\Http\Controllers;

use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use App\Notifications\FriendRequestNotification;


class FriendController extends Controller
{
    
    public function index()
    {
        try {
            $users = User::select('id', 'name', 'bio', 'profile_image')
                ->where('id', '!=', Auth::id())
                ->withCount(['receivedFriendRequests as friend_request_sent' => function($query) {
                    $query->where('user_id', Auth::id())
                        ->where('status', 'pending');
                }])
                ->with(['receivedFriendRequests' => function($query) {
                    $query->where('user_id', Auth::id())
                        ->where('status', 'accepted');
                }])
                ->get();

            $users = $users->map(function ($user) {
                $user->profile_image_url = $this->getImageUrl($user->profile_image);
                $user->is_friend = $user->receivedFriendRequests->isNotEmpty();
                unset($user->receivedFriendRequests);
                return $user;
            });
            
            return response()->json($users);
        } catch (\Exception $e) {
            Log::error('Error in FriendController@index: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'An error occurred while fetching users',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    private function getImageUrl($profileImage)
    {
        if (!$profileImage || !Storage::disk('public')->exists($profileImage)) {

            return URL::asset('images/DefaultAvatar.jpg');
        }

        return URL::asset('storage/' . $profileImage);
    }
    public function sendRequest(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'target_user_id' => 'required|exists:users,id',
            ]);

            // Check if trying to send request to self
            if ($validatedData['target_user_id'] == Auth::id()) {
                return response()->json([
                    'message' => 'Cannot send friend request to yourself'
                ], 400);
            }

            // Check for existing friend request in either direction
            $existingFriendRequest = FriendRequest::where(function($query) use ($validatedData) {
                $query->where('user_id', Auth::id())
                      ->where('friend_id', $validatedData['target_user_id']);
            })->orWhere(function($query) use ($validatedData) {
                $query->where('user_id', $validatedData['target_user_id'])
                      ->where('friend_id', Auth::id());
            })->first();

            if ($existingFriendRequest) {
                $status = $existingFriendRequest->status;
                $message = match($status) {
                    'pending' => 'A friend request is already pending',
                    'accepted' => 'You are already friends',
                    'declined' => 'Previous request was declined. Please try again later',
                    default => 'Friend request already exists'
                };
                
                return response()->json([
                    'message' => $message,
                    'status' => $status
                ], 400);
            }

            $friendRequest = FriendRequest::create([
                'user_id' => Auth::id(),
                'friend_id' => $validatedData['target_user_id'],
                'status' => 'pending',
            ]);

            $sender = Auth::user();
            
            Log::info('Friend request sent successfully', [
                'sender_id' => Auth::id(),
                'recipient_id' => $validatedData['target_user_id']
            ]);

            return response()->json([
                'message' => 'Friend request sent successfully',
                'notification' => "{$sender->name} sent you a friend request",
                'friendrequest' => $friendRequest
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in friend request:', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error sending friend request:', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'An error occurred while sending the friend request'
            ], 500);
        }
    }

    public function getFriendCount()
{
    $count = FriendRequest::where('user_id', Auth::id())
                          ->where('status', 'accepted')
                          ->count();

    return response()->json(['count' => $count]);
}
    

    public function getPendingRequests()
    {
        $pendingRequests = FriendRequest::where('friend_id', Auth::id())
            ->where('status', 'pending')
            ->with('user:id,name,profile_image')
            ->get();

        return response()->json($pendingRequests);
    }

    public function acceptRequest($id)
    {
        $friendRequest = FriendRequest::findOrFail($id);
    
        if ($friendRequest->friend_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $friendRequest->status = 'accepted';
        $friendRequest->save();
    
        // Create a reverse friendship
        FriendRequest::create([
            'user_id' => Auth::id(),
            'friend_id' => $friendRequest->user_id,
            'status' => 'accepted'
        ]);
    
        return response()->json(['message' => 'Friend request accepted']);
    }
    public function getPendingRequestsCount()
    {
        try {
            $count = FriendRequest::where('friend_id', Auth::id())
                             ->where('status', 'pending')
                             ->count();

            return response()->json([
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching friend request count',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function removeFriend($id)
    {
        try {
            // Find and delete friend relationship in both directions
            $deletedCount = FriendRequest::where(function($query) use ($id) {
                $query->where('user_id', Auth::id())->where('friend_id', $id);
            })->orWhere(function($query) use ($id) {
                $query->where('user_id', $id)->where('friend_id', Auth::id());
            })->delete();

            if ($deletedCount === 0) {
                return response()->json(['message' => 'Friend relationship not found'], 404);
            }

            Log::info('Friend removed successfully', [
                'user_id' => Auth::id(),
                'friend_id' => $id
            ]);

            return response()->json(['message' => 'Friend removed successfully']);

        } catch (\Exception $e) {
            Log::error('Error removing friend:', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'friend_id' => $id
            ]);

            return response()->json([
                'message' => 'An error occurred while removing the friend',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function declineRequest($id)
    {
        try {
            $friendRequest = FriendRequest::findOrFail($id);

            if ($friendRequest->friend_id !== Auth::id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Delete the friend request instead of changing its status
            $friendRequest->delete();

            // Log the action
            Log::info('Friend request declined and deleted', [
                'decliner_id' => Auth::id(),
                'requester_id' => $friendRequest->user_id,
                'request_id' => $id
            ]);

            return response()->json(['message' => 'Friend request declined and deleted']);
        } catch (\Exception $e) {
            Log::error('Error declining friend request:', [
                'error' => $e->getMessage(),
                'request_id' => $id
            ]);
            return response()->json([
                'message' => 'An error occurred while declining the friend request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getAcceptedFriends()
    {
        try {
            // Get all accepted friend requests where the current user is either the sender or receiver
            $friendIds = FriendRequest::where(function($query) {
                $query->where('user_id', Auth::id())
                    ->orWhere('friend_id', Auth::id());
            })
            ->where('status', 'accepted')
            ->get()
            ->map(function($friendship) {
                // Return the ID of the other user (not the current user)
                return $friendship->user_id == Auth::id()
                    ? $friendship->friend_id
                    : $friendship->user_id;
            });

            // Get the user details for all friends
            $friends = User::whereIn('id', $friendIds)
                ->select('id', 'name', 'profile_image')
                ->get()
                ->map(function($user) {
                    $user->profile_image_url = $this->getImageUrl($user->profile_image);
                    return $user;
                });

            return response()->json($friends);
        } catch (\Exception $e) {
            Log::error('Error in FriendController@getAcceptedFriends: ' . $e->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching friends',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}