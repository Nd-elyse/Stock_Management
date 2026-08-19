<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\AuthToken;
use App\Models\Otp;
use App\Models\RateLimit;
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
    private const TOKEN_TTL_DAYS_REMEMBERED = 30;

    // Rate-limit tuning. Backed by the `rate_limits` table (see RateLimit
    // model) which existed in the database already but nothing wrote to
    // it, so login/OTP endpoints previously had no brute-force or spam
    // protection at all.
    private const LOGIN_MAX_ATTEMPTS = 5;      // failed password attempts
    private const LOGIN_DECAY_MINUTES = 15;
    private const OTP_VERIFY_MAX_ATTEMPTS = 8; // failed OTP attempts, across resends
    private const OTP_VERIFY_DECAY_MINUTES = 15;
    private const RESEND_COOLDOWN_SECONDS = 30;   // minimum gap between resend requests
    private const RESEND_MAX_ATTEMPTS = 5;        // resends allowed per decay window
    private const RESEND_DECAY_MINUTES = 15;

    /** STEP 1: username + password -> OTP is issued and "sent" (logged/mailed). */
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $rateKey = $this->rateIdentifier($data['username'], $request);

        if ($this->isRateLimited($rateKey, 'login')) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed sign-in attempts. Please try again in a few minutes.',
            ], 429);
        }

        $user = User::where('Username', $data['username'])->first();

        if (!$user || !Hash::check($data['password'], $user->Password)) {
            $this->registerFailure($rateKey, 'login', self::LOGIN_MAX_ATTEMPTS, self::LOGIN_DECAY_MINUTES);
            return response()->json(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }

        // Credentials are correct, but without a registered email there is
        // nowhere to deliver the OTP - fail clearly instead of silently
        // issuing a code the user can never receive.
        if (empty($user->Email)) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has no registered email address. Contact an administrator to complete sign-in.',
            ], 422);
        }

        // Correct password: clear the failure counter for this identifier.
        $this->clearRateLimit($rateKey, 'login');

        $this->issueOtp($user, 'login');

        return response()->json([
            'success' => true,
            'otp_required' => true,
            'username' => $user->Username,
            'message' => 'A verification code has been sent to ' . $this->maskEmail($user->Email) . '.',
        ]);
    }

    /** STEP 2: username + otp -> bearer token + user payload. */
    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'otp' => ['required', 'digits:6'],
            'remember' => 'nullable|boolean',
        ]);

        $rateKey = $this->rateIdentifier($data['username'], $request);

        if ($this->isRateLimited($rateKey, 'verify_otp')) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please request a new code and try again shortly.',
            ], 429);
        }

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            $this->registerFailure($rateKey, 'verify_otp', self::OTP_VERIFY_MAX_ATTEMPTS, self::OTP_VERIFY_DECAY_MINUTES);
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $check = $this->checkOtp($user, 'login', $data['otp']);
        if (!$check['success']) {
            $this->registerFailure($rateKey, 'verify_otp', self::OTP_VERIFY_MAX_ATTEMPTS, self::OTP_VERIFY_DECAY_MINUTES);
            return response()->json($check, 422);
        }

        $this->clearRateLimit($rateKey, 'verify_otp');
        $this->clearRateLimit($rateKey, 'login');

        $this->syncUserSessionState($user, true);

        $token = $this->issueToken($user, (bool) ($data['remember'] ?? false));

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function resendOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string']);
        $rateKey = $this->rateIdentifier($data['username'], $request);

        $cooldownMessage = $this->enforceResendLimit($rateKey, 'resend_otp');
        if ($cooldownMessage) {
            return response()->json(['success' => false, 'message' => $cooldownMessage], 429);
        }

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }
        if (empty($user->Email)) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has no registered email address. Contact an administrator.',
            ], 422);
        }
        $this->issueOtp($user, 'login');
        return response()->json([
            'success' => true,
            'message' => 'A new code has been sent.',
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
        $this->syncUserSessionState($user, true);
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
            $this->syncUserSessionState($user, false);
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
            'phone' => ['nullable', 'string', 'regex:/^(072|073|078|079)\d{7}$/'],
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
        $user->Phone = $data['phone'] ?? $user->Phone;
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

        $rateKey = $this->rateIdentifier($data['username'], $request);
        $cooldownMessage = $this->enforceResendLimit($rateKey, 'forgot_start');
        if ($cooldownMessage) {
            return response()->json(['success' => false, 'message' => $cooldownMessage], 429);
        }

        $user = User::where('Username', $data['username'])->where('Email', $data['email'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No account matches that username and email.'], 404);
        }
        $this->issueOtp($user, 'password_reset');
        return response()->json(['success' => true, 'message' => 'A verification code has been sent to your email.']);
    }

    public function forgotResendOtp(Request $request)
    {
        return $this->resendPasswordResetOtp($request);
    }

    private function resendPasswordResetOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string']);

        $rateKey = $this->rateIdentifier($data['username'], $request);
        $cooldownMessage = $this->enforceResendLimit($rateKey, 'forgot_resend_otp');
        if ($cooldownMessage) {
            return response()->json(['success' => false, 'message' => $cooldownMessage], 429);
        }

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Account not found.'], 404);
        }
        $this->issueOtp($user, 'password_reset');
        return response()->json(['success' => true, 'message' => 'A new code has been sent.']);
    }

    public function forgotVerifyOtp(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'otp' => ['required', 'digits:6']]);

        $rateKey = $this->rateIdentifier($data['username'], $request);
        if ($this->isRateLimited($rateKey, 'forgot_verify_otp')) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please request a new code and try again shortly.',
            ], 429);
        }

        $user = User::where('Username', $data['username'])->first();
        if (!$user) {
            $this->registerFailure($rateKey, 'forgot_verify_otp', self::OTP_VERIFY_MAX_ATTEMPTS, self::OTP_VERIFY_DECAY_MINUTES);
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $record = Otp::where('UserID', $user->UserID)
            ->where('Purpose', 'password_reset')
            ->where('Consumed', false)
            ->orderByDesc('id')
            ->first();

        if (!$record || $record->ExpiresAt < now()) {
            $this->registerFailure($rateKey, 'forgot_verify_otp', self::OTP_VERIFY_MAX_ATTEMPTS, self::OTP_VERIFY_DECAY_MINUTES);
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }
        if ($record->Attempts >= 5) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Request a new code.'], 422);
        }
        if (!Hash::check($data['otp'], $record->CodeHash)) {
            $record->increment('Attempts');
            $this->registerFailure($rateKey, 'forgot_verify_otp', self::OTP_VERIFY_MAX_ATTEMPTS, self::OTP_VERIFY_DECAY_MINUTES);
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 422);
        }

        $this->clearRateLimit($rateKey, 'forgot_verify_otp');

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

    /** Delivers the code to the email registered on the authenticated account. */
    private function deliverOtp(User $user, string $code, string $purpose): void
    {
        $email = $user->getAttribute('Email');

        Mail::to($email)->send(new OtpMail($code, self::OTP_TTL_MINUTES));

        Log::info("OTP for {$user->Username} ({$purpose}) sent to {$email}");
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

    private function syncUserSessionState(User $user, bool $online): void
    {
        $user->Status = $online ? 'Active' : 'Inactive';
        $user->LastActivity = now();
        $user->save();
    }

    private function issueToken(User $user, bool $remember = false): string
    {
        $plain = Str::random(64);
        $this->syncUserSessionState($user, true);
        $ttlDays = $remember ? self::TOKEN_TTL_DAYS_REMEMBERED : self::TOKEN_TTL_DAYS;
        AuthToken::create([
            'UserID' => $user->UserID,
            'TokenHash' => hash('sha256', $plain),
            'ExpiresAt' => now()->addDays($ttlDays),
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
            'phone' => $user->Phone,
            'role' => $user->Role,
            'mechanic_id' => $user->MechanicID,
        ];
    }

    /** e.g. "jo***@example.com" - enough for the user to recognize their own inbox without fully exposing it in a response payload. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return $email;
        }
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }

    /* ---------------- rate limiting (backed by the `rate_limits` table) ---------------- */

    private function rateIdentifier(string $username, Request $request): string
    {
        return strtolower($username) . '|' . $request->ip();
    }

    private function isRateLimited(string $identifier, string $endpoint): bool
    {
        $record = RateLimit::where('identifier', $identifier)->where('endpoint', $endpoint)->first();
        return (bool) ($record && $record->blocked_until && $record->blocked_until->isFuture());
    }

    /** Records a failed attempt; blocks the identifier once maxAttempts is reached within the decay window. */
    private function registerFailure(string $identifier, string $endpoint, int $maxAttempts, int $decayMinutes): void
    {
        $record = RateLimit::where('identifier', $identifier)->where('endpoint', $endpoint)->first();

        if (!$record || $record->first_attempt->lt(now()->subMinutes($decayMinutes))) {
            RateLimit::updateOrCreate(
                ['identifier' => $identifier, 'endpoint' => $endpoint],
                ['attempt_count' => 1, 'first_attempt' => now(), 'last_attempt' => now(), 'blocked_until' => null]
            );
            return;
        }

        $record->attempt_count += 1;
        $record->last_attempt = now();
        if ($record->attempt_count >= $maxAttempts) {
            $record->blocked_until = now()->addMinutes($decayMinutes);
        }
        $record->save();
    }

    private function clearRateLimit(string $identifier, string $endpoint): void
    {
        RateLimit::where('identifier', $identifier)->where('endpoint', $endpoint)->delete();
    }

    /**
     * Enforces both a short cooldown between individual resend requests and
     * a cap on total resends per decay window. Returns a user-facing
     * message if the request should be blocked, or null if it's allowed
     * (in which case the attempt is recorded).
     */
    private function enforceResendLimit(string $identifier, string $endpoint): ?string
    {
        $record = RateLimit::where('identifier', $identifier)->where('endpoint', $endpoint)->first();

        if ($record) {
            if ($record->blocked_until && $record->blocked_until->isFuture()) {
                return 'Too many requests. Please try again later.';
            }
            if ($record->last_attempt && $record->last_attempt->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                return 'Please wait a few seconds before requesting another code.';
            }
            if ($record->first_attempt->lt(now()->subMinutes(self::RESEND_DECAY_MINUTES))) {
                $record->attempt_count = 0;
                $record->first_attempt = now();
                $record->blocked_until = null;
            }
            $record->attempt_count += 1;
            $record->last_attempt = now();
            if ($record->attempt_count >= self::RESEND_MAX_ATTEMPTS) {
                $record->blocked_until = now()->addMinutes(self::RESEND_DECAY_MINUTES);
            }
            $record->save();
            return null;
        }

        RateLimit::create([
            'identifier' => $identifier,
            'endpoint' => $endpoint,
            'attempt_count' => 1,
            'first_attempt' => now(),
            'last_attempt' => now(),
        ]);
        return null;
    }
}
