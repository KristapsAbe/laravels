<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($notifications);
    }

    public function markAsRead($id)
    {
        $notification = Notification::findOrFail($id);

        if ($notification->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->read = true;
        $notification->save();

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllAsRead()
    {
        Notification::where('user_id', Auth::id())
            ->update(['read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }
}