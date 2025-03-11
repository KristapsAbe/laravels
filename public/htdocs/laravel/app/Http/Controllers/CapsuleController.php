<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\CapsuleUser;
use App\Models\Capsule;
use App\Models\User;
use App\Models\FriendRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class CapsuleController extends Controller
{
    public function uploadImages(Request $request)
    {
        try {
            Log::info('Raw request data:', $request->all());

            $validatedData = $request->validate([
                'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'image_comments.*' => 'nullable|string',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'time' => 'required|date',
            ]);

            Log::info('Validated data:', $validatedData);

            $uploadedImages = [];
            $imageComments = [];

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $filePath = $image->store('capsules', 'public');
                    $uploadedImages[] = $filePath;
                    $comment = $request->input("image_comments.$index");
                    $imageComments[$filePath] = $comment !== null ? $comment : '';
                    Log::info('Image uploaded', ['path' => $filePath, 'comment' => $imageComments[$filePath]]);
                }
            }

            $user = Auth::user();

            if (!$user) {
                throw new \Exception('User not authenticated');
            }

            $time = Carbon::parse($validatedData['time']);
            Log::info('Parsed time:', ['time' => $time->toDateTimeString()]);

            $capsule = $user->capsules()->create([
                'title' => $validatedData['title'],
                'description' => $validatedData['description'],
                'images' => json_encode($uploadedImages),
                'image_comments' => json_encode($imageComments),
                'time' => $time,
            ]);

            Log::info('Capsule created', [
                'id' => $capsule->id, 
                'user_id' => $user->id, 
                'time' => $capsule->time,
                'image_comments' => $imageComments
            ]);

            return response()->json([
                'message' => 'Capsule created successfully',
                'capsule_id' => $capsule->id,
                'images' => $uploadedImages,
                'image_comments' => $imageComments,
                'time' => $time->toDateTimeString()
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in uploadImages', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error in uploadImages', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while creating the capsule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $capsules = Capsule::query()
                ->with(['user', 'capsuleUsers' => function($query) {
                    $query->select('users.id', 'name', 'email');  // Add any other user fields you need
                }])
                ->where(function($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhereHas('capsuleUsers', function($query) use ($user) {
                            $query->where('users.id', $user->id);
                        })
                        ->orWhere(function($query) use ($user) {
                            $query->where('privacy', 'public')
                                ->whereIn('user_id', function($subQuery) use ($user) {
                                    $subQuery->select('friend_id')
                                        ->from('friendships')
                                        ->where('user_id', $user->id)
                                        ->where('status', 'accepted')
                                        ->union(
                                            $subQuery->newQuery()
                                                ->select('user_id')
                                                ->from('friendships')
                                                ->where('friend_id', $user->id)
                                                ->where('status', 'accepted')
                                        );
                                });
                        });
                })
                ->orderBy('created_at', 'desc')
                ->get();

            $transformedCapsules = $capsules->map(function ($capsule) use ($user) {
                $capsuleArray = $capsule->toArray();
                $capsuleArray['is_owner'] = $capsule->user_id === $user->id;

                // Ensure capsule_users is always an array, even if empty
                $capsuleArray['capsule_users'] = collect($capsule->capsuleUsers)->map(function ($capsuleUser) {
                    return [
                        'user_id' => $capsuleUser->id,
                        'name' => $capsuleUser->name,
                        'status' => $capsuleUser->pivot->status ?? 'pending'
                    ];
                })->values()->all();

                return $capsuleArray;
            });

            return response()->json([
                'status' => 'success',
                'data' => $transformedCapsules
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching capsules: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch capsules',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getFriendCapsules(Request $request, int $friendId)
    {
        try {
            $currentUser = $request->user();

            if (!$currentUser) {
                \Log::error('Unauthorized access attempt');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized'
                ], 401);
            }

            \Log::info('Fetching capsules for friend:', ['friendId' => $friendId, 'currentUser' => $currentUser->id]);

            // Validate that the requested friend ID is valid
            $friend = User::findOrFail($friendId);

            // Check if the current user is friends with the capsule owner
            $isFriend = FriendRequest::where(function($query) use ($currentUser, $friendId) {
                $query->where('user_id', $currentUser->id)
                    ->where('friend_id', $friendId)
                    ->where('status', 'accepted');
            })->orWhere(function($query) use ($currentUser, $friendId) {
                $query->where('user_id', $friendId)
                    ->where('friend_id', $currentUser->id)
                    ->where('status', 'accepted');
            })->exists();

            \Log::info('Is friend:', ['isFriend' => $isFriend]);

            // First, let's check how many total capsules this user has
            $totalCapsules = Capsule::where('user_id', $friendId)->count();
            \Log::info('Total capsules for this user:', ['total' => $totalCapsules]);

            // Log capsules with each privacy setting
            $privateCapsules = Capsule::where('user_id', $friendId)->where('privacy', 'private')->count();
            $friendsCapsules = Capsule::where('user_id', $friendId)->where('privacy', 'friends')->count();
            $publicCapsules = Capsule::where('user_id', $friendId)->where('privacy', 'public')->count();

            \Log::info('Capsules by privacy:', [
                'private' => $privateCapsules,
                'friends' => $friendsCapsules,
                'public' => $publicCapsules
            ]);

            // Simplified query - start by getting all capsules for this user
            $allUserCapsules = Capsule::with(['user', 'capsuleUsers'])
                ->where('user_id', $friendId)
                ->get();

            \Log::info('All capsules for this user:', ['count' => $allUserCapsules->count()]);

            // Then filter them based on privacy rules
            $visibleCapsules = $allUserCapsules->filter(function ($capsule) use ($currentUser, $isFriend, $friendId) {
                // Public capsules are visible to everyone
                if ($capsule->privacy === 'public') {
                    return true;
                }

                // Friends capsules are visible if users are friends
                if ($capsule->privacy === 'friends' && $isFriend) {
                    return true;
                }

                // Private capsules are only visible to the owner
                if ($capsule->privacy === 'private' && $currentUser->id === $friendId) {
                    return true;
                }

                return false;
            });

            \Log::info('Visible capsules after filtering:', ['count' => $visibleCapsules->count()]);

            // Transform the capsules for the response
            $transformedCapsules = $visibleCapsules->map(function ($capsule) use ($currentUser, $friendId) {
                $capsuleArray = $capsule->toArray();
                $capsuleArray['is_owner'] = $capsule->user_id === $currentUser->id;
                $capsuleArray['is_friend_owner'] = $capsule->user_id === $friendId;

                // Add debug info
                $capsuleArray['debug_info'] = [
                    'id' => $capsule->id,
                    'privacy' => $capsule->privacy,
                    'status' => $capsule->status
                ];

                // Transform capsule users array
                $capsuleArray['capsule_users'] = collect($capsule->capsuleUsers)->map(function ($capsuleUser) {
                    return [
                        'user_id' => $capsuleUser->id,
                        'name' => $capsuleUser->name,
                        'status' => $capsuleUser->pivot->status ?? 'pending'
                    ];
                })->values()->all();

                // Format time for human readability
                $dateTime = new \DateTime($capsule->time);
                $capsuleArray['time'] = [
                    'original' => $capsule->time,
                    'human_readable' => $dateTime->format('F j, Y, g:i a')
                ];

                return $capsuleArray;
            });

            return response()->json([
                'status' => 'success',
                'data' => $transformedCapsules,
                'debug_counts' => [
                    'total_user_capsules' => $totalCapsules,
                    'private_capsules' => $privateCapsules,
                    'friends_capsules' => $friendsCapsules,
                    'public_capsules' => $publicCapsules,
                    'visible_after_filtering' => $visibleCapsules->count()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching friend capsules: ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch capsules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function acceptCapsule(Request $request, $id)
    {
        $request->validate([
            'images.*' => 'required|image|max:2048', // 2MB max
            'image_comments.*' => 'nullable|string|max:1000',
        ]);

        try {
            $capsule = Capsule::findOrFail($id);

            // Get current images and comments from JSON fields
            $currentImages = json_decode($capsule->images ?? '[]', true);
            $currentComments = json_decode($capsule->image_comments ?? '{}', true);

            // Process and store new images
            foreach($request->file('images') as $index => $image) {
                $path = $image->store('capsules', 'public');
                $currentImages[] = $path;
                $currentComments[$path] = $request->input("image_comments.$index", '');
            }

            // Update capsule with new images and comments
            $capsule->update([
                'images' => json_encode($currentImages),
                'image_comments' => json_encode($currentComments)
            ]);

            // Update the capsule_user status
            $capsuleUser = CapsuleUser::where('capsule_id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if ($capsuleUser) {
                $capsuleUser->update(['status' => 'accepted']);
            }

            return response()->json(['message' => 'Capsule accepted successfully']);
        } catch (\Exception $e) {
            \Log::error('Capsule acceptance failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to process capsule: ' . $e->getMessage()], 500);
        }
    }

    public function getSharedCapsules()
    {
        try {
            $userId = Auth::id();

            $sharedCapsules = DB::table('capsule_user')
                ->join('capsules', 'capsule_user.capsule_id', '=', 'capsules.id')
                ->join('users', 'capsules.user_id', '=', 'users.id')
                ->where('capsule_user.user_id', $userId)
                ->orderBy('capsule_user.created_at', 'desc')
                ->select([
                    'capsule_user.id as share_id',
                    'capsules.id as capsule_id',
                    'capsules.title',
                    'capsules.vision',
                    'users.name as shared_by',
                    'capsule_user.created_at',
                    'capsules.user_id as owner_id',
                    'capsule_user.status'
                ])
                ->get();

            return response()->json($sharedCapsules);
        } catch (\Exception $e) {
            Log::error('Error in getSharedCapsules', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving shared capsules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function create(Request $request)
    {
        try {
            Log::info('Raw request data in create method:', $request->all());

            $validatedData = $request->validate([
                'images' => 'required|array',
                'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'image_comments.*' => 'nullable|string',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'time' => 'required|date',
                'vision' => 'required|string',
                'privacy' => 'required|in:private,friends,public',
                'design' => 'required|string',
                'shared_with' => 'nullable|array',
                'shared_with.*' => 'exists:users,id',
            ]);

            Log::info('Validated data in create method:', $validatedData);

            // Add validation debugging
            Log::info('Form data structure', [
                'has_images' => $request->hasFile('images'),
                'image_count' => $request->hasFile('images') ? count($request->file('images')) : 0,
                'has_comments' => $request->has('image_comments'),
                'shared_with_count' => isset($validatedData['shared_with']) ? count($validatedData['shared_with']) : 0,
                'all_keys' => array_keys($request->all())
            ]);

            $uploadedImages = [];
            $imageComments = [];

            if ($request->hasFile('images')) {
                Log::info('Processing images array - count: ' . count($request->file('images')));
                foreach ($request->file('images') as $index => $image) {
                    try {
                        Log::info('Processing image at index ' . $index, [
                            'original_name' => $image->getClientOriginalName(),
                            'mime_type' => $image->getMimeType(),
                            'size' => $image->getSize()
                        ]);
                        $filePath = $image->store('capsules', 'public');
                        $uploadedImages[] = $filePath;
                        $comment = $request->input("image_comments.$index");
                        $imageComments[$filePath] = $comment !== null ? $comment : '';
                        Log::info('Image successfully uploaded', [
                            'index' => $index,
                            'path' => $filePath,
                            'comment' => $imageComments[$filePath]
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to process image at index ' . $index, [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw $e;
                    }
                }
            } else {
                Log::warning('No images found in the request');
                // Validate if this is expected or not
            }

            $user = Auth::user();
            if (!$user) {
                Log::error('User authentication failed');
                throw new \Exception('User not authenticated');
            }

            $time = Carbon::parse($validatedData['time']);
            Log::info('Parsed time in create method:', ['time' => $time->toDateTimeString()]);

            // Log before database operation
            Log::info('Attempting to create capsule record', [
                'user_id' => $user->id,
                'title' => $validatedData['title'],
                'privacy' => $validatedData['privacy'],
                'time' => $time->toDateTimeString(),
                'image_count' => count($uploadedImages)
            ]);

            try {
                $capsule = $user->capsules()->create([
                    'title' => $validatedData['title'],
                    'description' => $validatedData['description'],
                    'images' => json_encode($uploadedImages),
                    'image_comments' => json_encode($imageComments),
                    'time' => $time,
                    'vision' => $validatedData['vision'],
                    'privacy' => $validatedData['privacy'],
                    'design' => $validatedData['design'],
                    'status' => isset($validatedData['shared_with']) ? 'pending' : 'completed',
                ]);

                Log::info('Capsule record created successfully', [
                    'id' => $capsule->id,
                    'user_id' => $user->id
                ]);
            } catch (\Exception $e) {
                Log::error('Database error while creating capsule', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                    'sql' => $e instanceof \Illuminate\Database\QueryException ? $e->getSql() : null
                ]);
                throw $e;
            }

            if (!empty($validatedData['shared_with'])) {
                try {
                    Log::info('Attaching shared users to capsule', [
                        'capsule_id' => $capsule->id,
                        'shared_with' => $validatedData['shared_with']
                    ]);
                    $capsule->sharedUsers()->attach($validatedData['shared_with']);
                    Log::info('Successfully attached shared users');
                } catch (\Exception $e) {
                    Log::error('Failed to attach shared users', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }

            return response()->json([
                'message' => 'Capsule created successfully',
                'capsule_id' => $capsule->id,
                'images' => $uploadedImages,
                'image_comments' => $imageComments,
                'time' => $time->toDateTimeString(),
                'vision' => $capsule->vision,
                'privacy' => $capsule->privacy,
                'design' => $capsule->design,
                'shared_with' => $capsule->sharedUsers()->pluck('users.id'),
                'status' => $capsule->status,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in create method', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error in create method', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while creating the capsule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCapsuleDetails($id)
    {
        try {
            $user = Auth::user();

            $capsule = Capsule::query()
                ->with(['user', 'capsuleUsers' => function($query) {
                    $query->select('users.id', 'name', 'email');
                }])
                ->where('id', $id)
                ->where(function($query) use ($user) {
                    // Access control: User can view if they are the owner
                    $query->where('user_id', $user->id)
                        // Or if they are one of the shared users
                        ->orWhereHas('capsuleUsers', function($query) use ($user) {
                            $query->where('users.id', $user->id);
                        })
                        // Or if it's a public capsule from a friend
                        ->orWhere(function($query) use ($user) {
                            $query->where('privacy', 'public')
                                ->whereIn('user_id', function($subQuery) use ($user) {
                                    $subQuery->select('friend_id')
                                        ->from('friendships')
                                        ->where('user_id', $user->id)
                                        ->where('status', 'accepted')
                                        ->union(
                                            $subQuery->newQuery()
                                                ->select('user_id')
                                                ->from('friendships')
                                                ->where('friend_id', $user->id)
                                                ->where('status', 'accepted')
                                        );
                                });
                        });
                })
                ->firstOrFail();

            // Determine if current user is the owner
            $isOwner = $capsule->user_id === $user->id;

            // Process images and comments
            $images = json_decode($capsule->images ?? '[]', true);
            $imageComments = json_decode($capsule->image_comments ?? '{}', true);

            // Format images with their comments for front-end
            $processedImages = [];
            foreach ($images as $imagePath) {
                $processedImages[] = [
                    'path' => $imagePath,
                    'url' => Storage::url($imagePath),
                    'comment' => $imageComments[$imagePath] ?? ''
                ];
            }

            // Format capsule users data
            $capsuleUsers = collect($capsule->capsuleUsers)->map(function ($capsuleUser) {
                return [
                    'user_id' => $capsuleUser->id,
                    'name' => $capsuleUser->name,
                    'email' => $capsuleUser->email,
                    'status' => $capsuleUser->pivot->status ?? 'pending'
                ];
            })->values()->all();

            // Get related timestamp information
            $now = Carbon::now();
            $capsuleTime = Carbon::parse($capsule->time);
            $isAvailable = $now->greaterThanOrEqualTo($capsuleTime);
            $daysUntilAvailable = $now->diffInDays($capsuleTime, false);
            $createdTimeAgo = Carbon::parse($capsule->created_at)->diffForHumans();

            // Build response data
            $result = [
                'id' => $capsule->id,
                'title' => $capsule->title,
                'description' => $capsule->description,
                'vision' => $capsule->vision,
                'design' => $capsule->design,
                'privacy' => $capsule->privacy,
                'status' => $capsule->status,
                'is_owner' => $isOwner,
                'owner' => [
                    'id' => $capsule->user->id,
                    'name' => $capsule->user->name,
                    'email' => $capsule->user->email
                ],
                'images' => $processedImages,
                'shared_users' => $capsuleUsers,
                'time' => [
                    'formatted' => $capsuleTime->format('Y-m-d H:i:s'),
                    'human_readable' => $capsuleTime->diffForHumans(),
                    'is_available' => $isAvailable,
                    'days_until_available' => $daysUntilAvailable,
                ],
                'created_at' => [
                    'formatted' => $capsule->created_at->format('Y-m-d H:i:s'),
                    'human_readable' => $createdTimeAgo
                ]
            ];

            Log::info('Capsule details retrieved', [
                'capsule_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $result
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Capsule not found or access denied', [
                'capsule_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Capsule not found or you do not have permission to view it'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving capsule details', [
                'message' => $e->getMessage(),
                'capsule_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve capsule details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function getCapsuleCount()
    {
        try {
            $userId = Auth::id();
            Log::info('User ID:', ['user_id' => $userId]);
            
            $count = Capsule::where('user_id', $userId)->count();
            Log::info('Capsule count retrieved', ['count' => $count]);
    
            return response()->json(['count' => $count]);
        } catch (\Exception $e) {
            Log::error('Error in getCapsuleCount', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            return response()->json([
                'message' => 'An error occurred while retrieving capsule count',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateShareStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,accepted,declined',
            'capsule_id' => 'required|exists:capsules,id'
        ]);

        try {
            $capsuleUser = CapsuleUser::where('id', $id)
                ->where('user_id', auth()->id())
                ->firstOrFail();

            $capsuleUser->update(['status' => $request->status]);

            return response()->json([
                'message' => 'Status updated successfully',
                'capsule_id' => $request->capsule_id
            ]);
        } catch (\Exception $e) {
            \Log::error('Status update failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update status'], 500);
        }
    }
    public function updateImageComment(Request $request, $capsuleId)
    {
        try {
            Log::info('Update image comment request data:', $request->all());

            $capsule = Capsule::findOrFail($capsuleId);
            $validatedData = $request->validate([
                'image_path' => 'required|string',
                'comment' => 'required|string',
            ]);

            $imageComments = json_decode($capsule->image_comments, true) ?: [];
            $imageComments[$validatedData['image_path']] = $validatedData['comment'];

            $capsule->update(['image_comments' => json_encode($imageComments)]);

            Log::info('Image comment updated', [
                'capsule_id' => $capsuleId,
                'image_path' => $validatedData['image_path'],
                'comment' => $validatedData['comment']
            ]);

            return response()->json(['message' => 'Image comment updated successfully']);
        } catch (\Exception $e) {
            Log::error('Error in updateImageComment', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while updating image comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getMonthlyStats()
{
    try {
        $currentYear = Carbon::now()->year;
        
        // Get counts of capsules grouped by creation month
        $monthlyStats = Capsule::selectRaw('MONTH(created_at) as month, COUNT(*) as total_created')
            ->whereYear('created_at', $currentYear)
            ->groupBy('month')
            ->orderBy('month')
            ->get();
            
        // Initialize all months with zero counts
        $formattedStats = collect(range(1, 12))->mapWithKeys(function ($month) {
            return [$month => [
                'month' => Carbon::create()->month($month)->format('M'),
                'created' => 0,
                'scheduled' => 0,
                'private' => 0,
                'public' => 0,
                'friends' => 0
            ]];
        });
        
        // Get detailed stats for each month
        $detailedStats = Capsule::selectRaw('
            MONTH(created_at) as month,
            COUNT(*) as total_created,
            SUM(CASE WHEN privacy = "private" THEN 1 ELSE 0 END) as private_count,
            SUM(CASE WHEN privacy = "public" THEN 1 ELSE 0 END) as public_count,
            SUM(CASE WHEN privacy = "friends" THEN 1 ELSE 0 END) as friends_count
        ')
        ->whereYear('created_at', $currentYear)
        ->groupBy('month')
        ->get();
        
        // Fill in actual counts
        $detailedStats->each(function ($stat) use (&$formattedStats) {
            $formattedStats[$stat->month] = [
                'month' => Carbon::create()->month($stat->month)->format('M'),
                'created' => $stat->total_created,
                'private' => $stat->private_count,
                'public' => $stat->public_count,
                'friends' => $stat->friends_count
            ];
        });

        $response = $formattedStats->values();

        Log::info('Monthly creation stats retrieved', ['stats' => $response]);
        
        return response()->json($response);
        
    } catch (\Exception $e) {
        Log::error('Error in getMonthlyStats', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'An error occurred while retrieving monthly stats',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
