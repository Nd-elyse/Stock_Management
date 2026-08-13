<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $query = Notification::with('user');
        // Admin sees every notification; everyone else sees their own plus broadcasts (UserID null).
        if ($user && $user->Role !== 'Admin') {
            $query->where(function ($q) use ($user) {
                $q->where('UserID', $user->UserID)->orWhereNull('UserID');
            });
        }
        $rows = $query->orderByDesc('CreatedAt')->limit(50)->get()->map(function ($n) {
            $n->UserFullName = $n->user->FullName ?? null;
            return $n;
        });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,UserID',
            'type' => 'required|string',
            'message' => 'required|string',
            'link' => 'nullable|string',
        ]);
        $n = Notification::create([
            'UserID' => $data['user_id'] ?? null,
            'Type' => $data['type'],
            'Message' => $data['message'],
            'Link' => $data['link'] ?? null,
        ]);
        return response()->json(['success' => true, 'message' => 'Notification saved.', 'data' => $n]);
    }

    public function update(Request $request, int $id)
    {
        $n = Notification::find($id);
        if (!$n) return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,UserID',
            'type' => 'sometimes|string',
            'message' => 'sometimes|string',
            'link' => 'nullable|string',
        ]);
        $n->fill(array_filter([
            'UserID' => array_key_exists('user_id', $data) ? $data['user_id'] : null,
            'Type' => $data['type'] ?? null,
            'Message' => $data['message'] ?? null,
            'Link' => $data['link'] ?? null,
        ], fn ($v) => $v !== null));
        $n->save();
        return response()->json(['success' => true, 'message' => 'Notification updated.', 'data' => $n]);
    }

    public function markRead(int $id)
    {
        $n = Notification::find($id);
        if (!$n) return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        $n->IsRead = true;
        $n->save();
        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $query = Notification::where('IsRead', false);
        if ($user->Role !== 'Admin') {
            $query->where(function ($q) use ($user) {
                $q->where('UserID', $user->UserID)->orWhereNull('UserID');
            });
        }
        $query->update(['IsRead' => true]);
        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        $n = Notification::find($id);
        if (!$n) return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        return $this->safeDelete($n, 'notification');
    }
}
