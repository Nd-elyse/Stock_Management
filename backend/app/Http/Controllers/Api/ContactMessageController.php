<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    /** Public - the contact form on the marketing site posts here without auth. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
            'phone' => 'nullable|string',
        ]);
        $msg = ContactMessage::create([
            'FullName' => $data['full_name'],
            'Email' => $data['email'],
            'Phone' => $data['phone'] ?? null,
            'Subject' => $data['subject'] ?? 'Account Access Request',
            'Message' => $data['message'],
        ]);
        $this->notifyRole('Admin', 'contact', "New contact message from {$data['full_name']}", '#messages');
        return response()->json(['success' => true, 'message' => 'Message sent. We will get back to you soon.', 'data' => $msg]);
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => ContactMessage::orderByDesc('CreatedAt')->get()]);
    }

    public function markRead(int $id)
    {
        $m = ContactMessage::find($id);
        if (!$m) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);
        $m->IsRead = true;
        $m->save();
        return response()->json(['success' => true]);
    }

    public function markAllRead()
    {
        ContactMessage::where('IsRead', false)->update(['IsRead' => true]);
        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        $m = ContactMessage::find($id);
        if (!$m) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);
        return $this->safeDelete($m, 'message');
    }
}
