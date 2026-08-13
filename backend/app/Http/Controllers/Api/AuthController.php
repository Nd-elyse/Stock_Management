<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 5;
    private const TOKEN_TTL_DAYS = 7;

    /** STEP 1: username + password -> OTP is issued and "sent" (logged/mailed). */
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('Username', $data['username'])->first();

        if (!$user || !Hash::check($data['password'], $user->Password)) {
            return response()->json(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }

        $otp = $this->issueOtp($user, 'login');

        return response()->json([
            'success' => true,
            'otp_required' => true,
            'username' => $user->Username,
            'message' => 'A verification code has been sent to ' . $user->Email . '.',
            // Only present when APP_DEBUG=true - lets you test the OTP flow
            // without a working mail transport. Never exposed in production.
            'debug_otp' => config('app.debug') ? $otp : null,
        ]);
    }

    /** STEP 2: username + otp -> bearer token + user payload. */
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'otp' => 'required|string',
        ]);

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $check = $this->checkOtp($user, 'login', $data['otp']);
        if (!$check['success']) {
            return response()->json($check, 422);
        }

        $user->Status = 'Active';
        $user->LastActivity = now();
        $user->save();

        $token = $this->issueToken($user);

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string']);
        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }
        $otp = $this->issueOtp($user, 'login');
        return response()->json([
            'success' => true,
            'message' => 'A new code has been sent.',
            'debug_otp' => config('app.debug') ? $otp : null,
        ]);
    }

    public function cancelOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string']);
        $user = User::where('Username', $data['username'])->first();
        if ($user) {
            Otp::where('UserID', $user->UserID)->where('Purpose', 'login')->where('Consumed', false)->update(['Consumed' => true]);
        }
        return response()->json(['success' => true]);
    }

    /** Rotates the caller's token so a still-active session doesn't get logged out. */
    public function sessionRenew(Request $request)
    {
        $user = $request->user();
        $oldTokenId = $request->attributes->get('auth_token_id');
        if ($oldTokenId) {
            AuthToken::where('id', $oldTokenId)->delete();
        }
        $token = $this->issueToken($user);
        return response()->json(['success' => true, 'token' => $token]);
    }

    public function logout(Request $request)
    {
        $tokenId = $request->attributes->get('auth_token_id');
        $user = $request->user();
        if ($tokenId) {
            AuthToken::where('id', $tokenId)->delete();
        }
        if ($user) {
            $user->Status = 'Inactive';
            $user->LastActivity = now();
            $user->save();
        }
        return response()->json(['success' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'user' => $this->userPayload($request->user())]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'current_password' => 'nullable|string',
            'new_password' => 'nullable|string|min:6',
            'confirm_password' => 'nullable|string',
        ]);

        $usernameChanged = $data['username'] !== $user->Username;
        $changingPassword = !empty($data['new_password']);

        if ($usernameChanged || $changingPassword) {
            if (empty($data['current_password']) || !Hash::check($data['current_password'], $user->Password)) {
                return response()->json(['success' => false, 'message' => 'Current password is incorrect.'], 422);
            }
        }
        if ($changingPassword && $data['new_password'] !== ($data['confirm_password'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'New passwords do not match.'], 422);
        }
        if ($usernameChanged && User::where('Username', $data['username'])->where('UserID', '!=', $user->UserID)->exists()) {
            return response()->json(['success' => false, 'message' => 'That username is already taken.'], 422);
        }

        $user->FullName = $data['full_name'];
        $user->Username = $data['username'];
        $user->Email = $data['email'];
        if ($changingPassword) {
            $user->Password = Hash::make($data['new_password']);
        }
        $user->save();

        return response()->json(['success' => true, 'message' => 'Profile updated successfully.', 'user' => $this->userPayload($user)]);
    }

    /* ---------------- Forgot password (self-service, OTP by email) ---------------- */

    public function forgotStart(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'email' => 'required|email']);
        $user = User::where('Username', $data['username'])->where('Email', $data['email'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No account matches that username and email.'], 404);
        }
        $otp = $this->issueOtp($user, 'password_reset');
        return response()->json([
            'success' => true,
            'message' => 'A verification code has been sent to your email.',
            'debug_otp' => config('app.debug') ? $otp : null,
        ]);
    }

    public function forgotResendOtp(Request $request)
    {
        return $this->resendPasswordResetOtp($request);
    }

    private function resendPasswordResetOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string']);
        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }
        $otp = $this->issueOtp($user, 'password_reset');
        return response()->json(['success' => true, 'message' => 'A new code has been sent.', 'debug_otp' => config('app.debug') ? $otp : null]);
    }

    public function forgotVerifyOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'otp' => 'required|string']);
        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $record = Otp::where('UserID', $user->UserID)
            ->where('Purpose', 'password_reset')
            ->where('Consumed', false)
            ->orderByDesc('id')
            ->first();

        if (!$record || $record->ExpiresAt < now()) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }
        if ($record->Attempts >= 5) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Request a new code.'], 422);
        }
        if (!Hash::check($data['otp'], $record->CodeHash)) {
            $record->increment('Attempts');
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $record->VerifiedAt = now();
        $record->save();

        return response()->json(['success' => true]);
    }

    public function forgotResetPassword(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
            'confirm' => 'required|string',
        ]);
        if ($data['password'] !== $data['confirm']) {
            return response()->json(['success' => false, 'message' => 'Passwords do not match.'], 422);
        }

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }

        $record = Otp::where('UserID', $user->UserID)
            ->where('Purpose', 'password_reset')
            ->where('Consumed', false)
            ->whereNotNull('VerifiedAt')
            ->where('VerifiedAt', '>=', now()->subMinutes(self::OTP_TTL_MINUTES * 2))
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            return response()->json(['success' => false, 'message' => 'Please verify the code again before resetting your password.'], 422);
        }

        $record->Consumed = true;
        $record->save();

        $user->Password = Hash::make($data['password']);
        $user->save();

        // Any active sessions are invalidated on a password reset.
        AuthToken::where('UserID', $user->UserID)->delete();

        return response()->json(['success' => true, 'message' => 'Password reset. You can now sign in.']);
    }

    /* ---------------- helpers ---------------- */

    private function issueOtp(User $user, string $purpose): string
    {
        Otp::where('UserID', $user->UserID)->where('Purpose', $purpose)->where('Consumed', false)->update(['Consumed' => true]);

        $code = (string) random_int(100000, 999999);
        Otp::create([
            'UserID' => $user->UserID,
            'Purpose' => $purpose,
            'CodeHash' => Hash::make($code),
            'Attempts' => 0,
            'Consumed' => false,
            'ExpiresAt' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);

        $this->deliverOtp($user, $code, $purpose);

        return $code;
    }

    /** Best-effort email delivery; never blocks the flow if mail isn't configured. */
    private function deliverOtp(User $user, string $code, string $purpose): void
    {
        Log::info("OTP for {$user->Username} ({$purpose}): {$code}");
        try {
            Mail::raw("Your GarageManager verification code is {$code}. It expires in " . self::OTP_TTL_MINUTES . " minutes.", function ($message) use ($user) {
                $message->to($user->Email)->subject('Your GarageManager verification code');
            });
        } catch (\Throwable $e) {
            Log::warning('OTP email delivery failed: ' . $e->getMessage());
        }
    }

    private function checkOtp(User $user, string $purpose, string $code): array
    {
        $record = Otp::where('UserID', $user->UserID)
            ->where('Purpose', $purpose)
            ->where('Consumed', false)
            ->orderByDesc('id')
            ->first();

        if (!$record || $record->ExpiresAt < now()) {
            return ['success' => false, 'message' => 'Invalid or expired code.'];
        }
        if ($record->Attempts >= 5) {
            return ['success' => false, 'message' => 'Too many attempts. Request a new code.'];
        }
        if (!Hash::check($code, $record->CodeHash)) {
            $record->increment('Attempts');
            return ['success' => false, 'message' => 'Invalid or expired code.'];
        }

        $record->Consumed = true;
        $record->save();

        return ['success' => true];
    }

    private function issueToken(User $user): string
    {
        $plain = Str::random(64);
        AuthToken::create([
            'UserID' => $user->UserID,
            'TokenHash' => hash('sha256', $plain),
            'ExpiresAt' => now()->addDays(self::TOKEN_TTL_DAYS),
        ]);
        return $plain;
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->UserID,
            'full_name' => $user->FullName,
            'username' => $user->Username,
            'email' => $user->Email,
            'role' => $user->Role,
            'mechanic_id' => $user->MechanicID,
        ];
    }
}
