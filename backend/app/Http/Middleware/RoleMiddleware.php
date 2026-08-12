<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /** Usage: ->middleware('role:Admin') or ->middleware('role:Admin,Receptionist') */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }
        if (!empty($roles) && !in_array($user->Role, $roles, true)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
        return $next($request);
    }
}
