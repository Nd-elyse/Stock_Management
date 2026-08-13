<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => User::orderBy('UserID')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'role' => 'required|string|in:Admin,Receptionist,Mechanic,Stock Manager',
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'required|email|unique:users,Email',
            'username' => 'required|string|max:255|unique:users,Username',
            'password' => 'required|string|min:6',
            'confirm_password' => 'nullable|string',
            'mechanic_specialization' => 'nullable|string',
            'mechanic_salary' => 'nullable|numeric',
        ]);
        if (!empty($data['confirm_password']) && $data['password'] !== $data['confirm_password']) {
            return response()->json(['success' => false, 'message' => 'Passwords do not match.'], 422);
        }

        return DB::transaction(function () use ($data) {
            $mechanicId = null;
            if ($data['role'] === 'Mechanic') {
                $mechanic = Mechanic::create([
                    'FullName' => $data['full_name'],
                    'Phone' => $data['phone'] ?? null,
                    'Specialization' => $data['mechanic_specialization'] ?? null,
                    'Salary' => $data['mechanic_salary'] ?? 0,
                ]);
                $mechanicId = $mechanic->MechanicID;
            }

            $user = User::create([
                'Username' => $data['username'],
                'Password' => Hash::make($data['password']),
                'Role' => $data['role'],
                'FullName' => $data['full_name'],
                'Email' => $data['email'],
                'Phone' => $data['phone'] ?? null,
                'Status' => 'Inactive',
                'MechanicID' => $mechanicId,
            ]);

            return response()->json(['success' => true, 'message' => 'User created.', 'data' => $user]);
        });
    }

    public function update(Request $request, int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $data = $request->validate([
            'role' => 'sometimes|string|in:Admin,Receptionist,Mechanic,Stock Manager',
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'sometimes|email|unique:users,Email,' . $id . ',UserID',
            'username' => 'sometimes|string|max:255|unique:users,Username,' . $id . ',UserID',
            'password' => 'nullable|string|min:6',
            'mechanic_specialization' => 'nullable|string',
            'mechanic_salary' => 'nullable|numeric',
        ]);

        $user->fill(array_filter([
            'Username' => $data['username'] ?? null,
            'Role' => $data['role'] ?? null,
            'FullName' => $data['full_name'] ?? null,
            'Email' => $data['email'] ?? null,
            'Phone' => $data['phone'] ?? null,
        ], fn ($v) => $v !== null));

        if (!empty($data['password'])) {
            $user->Password = Hash::make($data['password']);
        }
        $user->save();

        if ($user->Role === 'Mechanic') {
            if ($user->MechanicID) {
                Mechanic::where('MechanicID', $user->MechanicID)->update(array_filter([
                    'FullName' => $data['full_name'] ?? null,
                    'Phone' => $data['phone'] ?? null,
                    'Specialization' => $data['mechanic_specialization'] ?? null,
                    'Salary' => $data['mechanic_salary'] ?? null,
                ], fn ($v) => $v !== null));
            } else {
                $mechanic = Mechanic::create([
                    'FullName' => $user->FullName,
                    'Phone' => $user->Phone,
                    'Specialization' => $data['mechanic_specialization'] ?? null,
                    'Salary' => $data['mechanic_salary'] ?? 0,
                ]);
                $user->MechanicID = $mechanic->MechanicID;
                $user->save();
            }
        }

        return response()->json(['success' => true, 'message' => 'User updated.', 'data' => $user]);
    }

    public function destroy(int $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }
        if ($user->UserID === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own account while logged in.'], 422);
        }
        return $this->safeDelete($user, 'user');
    }
}
