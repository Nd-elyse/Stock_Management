<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetTicket;
use Illuminate\Http\Request;

class PasswordResetTicketController extends Controller
{
    /** Public - "I can't get my OTP" fallback link on the login page. */
    public function store(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'note' => 'nullable|string']);
        $ticket = PasswordResetTicket::create(['Username' => $data['username'], 'Note' => $data['note'] ?? null]);
        return response()->json(['success' => true, 'message' => 'Request submitted. An administrator will contact you.', 'data' => $ticket]);
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => PasswordResetTicket::orderByDesc('CreatedAt')->get()]);
    }

    public function resolve(int $id)
    {
        $ticket = PasswordResetTicket::find($id);
        if (!$ticket) return response()->json(['success' => false, 'message' => 'Request not found.'], 404);
        $ticket->Status = 'Resolved';
        $ticket->ResolvedAt = now();
        $ticket->save();
        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        $ticket = PasswordResetTicket::find($id);
        if (!$ticket) return response()->json(['success' => false, 'message' => 'Request not found.'], 404);
        $ticket->delete();
        return response()->json(['success' => true, 'message' => 'Request removed.']);
    }
}
