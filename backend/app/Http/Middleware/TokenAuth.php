<?php
namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hand-rolled bearer-token auth. Sanctum is not present in this project's
 * vendor/ tree, so tokens are issued at login (see AuthController) and
 * verified here by SHA-256 hash lookup against auth_tokens. The resolved
 * user is bound to the request via auth()->setUser() so `$request->user()`
 * / `auth()->user()` work exactly like they would under any other guard.
 */
class TokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $hash = hash('sha256', $token);
        $record = AuthToken::with('user')->where('TokenHash', $hash)->first();

        if (!$record || !$record->user) {
            return response()->json(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
        }

        if ($record->ExpiresAt < now()) {
            $record->user->Status = 'Inactive';
            $record->user->LastActivity = now();
            $record->user->save();
            $record->delete();
            return response()->json(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
        }

        $record->user->Status = 'Active';
        $record->user->LastActivity = now();
        $record->user->save();

        $record->LastUsedAt = now();
        $record->save();

        auth()->setUser($record->user);
        $request->attributes->set('auth_token_id', $record->id);

        return $next($request);
    }
}
