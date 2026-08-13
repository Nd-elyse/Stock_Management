<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Fire an in-app notification to every active user with the given role
     * (mirrors the old PHP app's admin/stock-manager broadcast notifications).
     */
    protected function notifyRole(string $role, string $type, string $message, ?string $link = null): void
    {
        $userIds = \App\Models\User::where('Role', $role)->where('Status', 'Active')->pluck('UserID');
        foreach ($userIds as $userId) {
            \App\Models\Notification::create(['UserID' => $userId, 'Type' => $type, 'Message' => $message, 'Link' => $link]);
        }
    }

    /** Fire an in-app notification to one specific user. */
    protected function notifyUser(?int $userId, string $type, string $message, ?string $link = null): void
    {
        if (!$userId) return;
        \App\Models\Notification::create(['UserID' => $userId, 'Type' => $type, 'Message' => $message, 'Link' => $link]);
    }

    /**
     * Delete a model, turning a foreign-key constraint violation into a
     * friendly 409 instead of a raw SQL exception (mirrors the old PHP
     * app's PDOException handling for errorInfo[1] === 1451 on MySQL -
     * SQLite/Postgres raise the same class of error under a different
     * code, so this checks the message instead of one driver's code).
     */
    protected function safeDelete(Model $model, string $label): JsonResponse
    {
        try {
            $model->delete();
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'foreign key') || str_contains($msg, 'constraint')) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete: this {$label} has linked records elsewhere. Remove those first.",
                ], 409);
            }
            throw $e;
        }
        return response()->json(['success' => true, 'message' => ucfirst($label) . ' deleted.']);
    }
}
