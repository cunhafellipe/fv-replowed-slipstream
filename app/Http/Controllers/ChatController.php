<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\ChatReadStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function messages(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $limit = (int) ($validated['limit'] ?? 50);
            $before = $validated['before'] ?? null;

            $query = ChatMessage::with('user:uid,name')
                ->orderBy('id', 'desc')
                ->limit($limit);

            if ($before !== null) {
                $query->where('id', '<', $before);
            }

            $messages = $query->get();
            $profilePictures = DB::table('usermeta')
                ->whereIn('uid', $messages->pluck('uid')->unique()->all())
                ->pluck('profile_picture', 'uid');

            // A bounded page uses one profile lookup instead of one per message.
            $transformedMessages = $messages->map(function ($message) use ($profilePictures) {
                $pic = $profilePictures->get($message->uid)
                    ?: 'https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png';

                return [
                    'id' => $message->id,
                    'message' => $message->message,
                    'username' => $message->user->name ?? 'Unknown',
                    'profilePicture' => $pic,
                    'createdAt' => $message->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'messages' => array_reverse($transformedMessages->toArray()),
            ]);
        } catch (\Exception $e) {
            \Log::error('Chat messages error: ' . $e->getMessage());
            return response()->json([
                'messages' => [],
            ]);
        }
    }

    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $user = Auth::user();

        $chatMessage = ChatMessage::create([
            'uid' => $user->uid,
            'message' => $request->message,
        ]);

        $userMeta = DB::table('usermeta')
            ->where('uid', $user->uid)
            ->first();

        $pic = optional($userMeta)->profile_picture ?: 'https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png';

        try {
            $broadcasterDriver = config('broadcasting.default');
            $broadcasterInstance = app('Illuminate\Contracts\Broadcasting\Factory')->connection($broadcasterDriver);
            
            \Log::info('Broadcasting chat message', [
                'id' => $chatMessage->id,
                'user' => $user->name,
                'message' => substr($chatMessage->message, 0, 50),
                'driver' => $broadcasterDriver,
                'broadcaster_class' => get_class($broadcasterInstance),
                'config' => [
                    'host' => config('broadcasting.connections.reverb.options.host'),
                    'port' => config('broadcasting.connections.reverb.options.port'),
                    'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                ]
            ]);

            broadcast(new ChatMessageSent(
                $chatMessage->id,
                $chatMessage->message,
                $user->name,
                $pic,
                $chatMessage->created_at->toIso8601String()
            ))->toOthers();
            
            \Log::info('Broadcast successful', ['id' => $chatMessage->id]);
        } catch (\Exception $e) {
            \Log::error('Broadcast failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $chatMessage->id,
                'message' => $chatMessage->message,
                'username' => $user->name,
                'profilePicture' => $pic,
                'createdAt' => $chatMessage->created_at->toIso8601String(),
            ],
        ]);
    }

    public function unreadCount()
    {
        try {
            $user = Auth::user();

            $readStatus = ChatReadStatus::where('uid', $user->uid)->first();
            $lastReadId = $readStatus ? $readStatus->last_read_message_id : 0;

            $unreadCount = ChatMessage::where('id', '>', $lastReadId)->count();

            return response()->json([
                'unreadCount' => $unreadCount,
            ]);
        } catch (\Exception $e) {
            \Log::error('Chat unread count error: ' . $e->getMessage());
            return response()->json([
                'unreadCount' => 0,
            ]);
        }
    }

    public function markRead(Request $request)
    {
        $request->validate([
            'messageId' => 'required|integer',
        ]);

        $user = Auth::user();

        ChatReadStatus::updateOrCreate(
            ['uid' => $user->uid],
            ['last_read_message_id' => $request->messageId]
        );

        return response()->json([
            'success' => true,
        ]);
    }
}
