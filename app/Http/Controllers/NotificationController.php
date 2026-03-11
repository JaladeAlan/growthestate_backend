<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return response()->json([
            'success'       => true,
            'notifications' => $notifications,
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        $unread = $request->user()
            ->unreadNotifications()
            ->latest()
            ->get();

        return response()->json([
            'success'              => true,
            'unread_notifications' => $unread,
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }

    public function markRead(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }
}