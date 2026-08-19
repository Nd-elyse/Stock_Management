<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\AuthToken;
use App\Models\Otp;
use App\Models\RateLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now());
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'Username' => 'otpuser',
            'Password' => Hash::make('correct-password'),
            'Role' => 'Admin',
            'FullName' => 'OTP Test User',
            'Email' => 'otpuser@example.com',
            'Status' => 'Inactive',
        ], $overrides));
    }

    protected function latestOtpCode(): string
    {
        $mail = Mail::sent(OtpMail::class)->last();
        $this->assertNotNull($mail);
        return $mail->code;
    }

    /* ---------------- generation + delivery ---------------- */

    public function test_login_with_valid_credentials_issues_an_otp_and_attempts_email_delivery(): void
    {
        $user = $this->makeUser();

        $res = $this->postJson('/api/auth/login', [
            'username' => 'otpuser',
            'password' => 'correct-password',
        ]);

        $res->assertOk()->assertJson(['success' => true, 'otp_required' => true]);
        $this->assertArrayNotHasKey('debug_otp', $res->json());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->latestOtpCode());

        $this->assertDatabaseHas('otps', [
            'UserID' => $user->UserID,
            'Purpose' => 'login',
            'Consumed' => false,
        ]);
        Mail::assertSentCount(1);
        Mail::assertSent(OtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->Email);
        });
    }

    public function test_login_with_wrong_password_does_not_create_an_otp(): void
    {
        $this->makeUser();

        $res = $this->postJson('/api/auth/login', [
            'username' => 'otpuser',
            'password' => 'wrong-password',
        ]);

        $res->assertStatus(401)->assertJson(['success' => false]);
        $this->assertDatabaseCount('otps', 0);
    }

    public function test_login_blocks_users_with_no_registered_email(): void
    {
        $this->makeUser(['Email' => null]);

        $res = $this->postJson('/api/auth/login', [
            'username' => 'otpuser',
            'password' => 'correct-password',
        ]);

        $res->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->assertDatabaseCount('otps', 0);
    }

    /* ---------------- verification: success, wrong code, expiry ---------------- */

    public function test_correct_otp_completes_login_and_issues_a_bearer_token(): void
    {
        $user = $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        $res = $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('otpuser', $res->json('user.username'));

        $this->assertDatabaseHas('otps', ['UserID' => $user->UserID, 'Consumed' => true]);
        $this->assertDatabaseHas('auth_tokens', ['UserID' => $user->UserID]);

        // Using the token should authenticate subsequent requests.
        $me = $this->withHeader('Authorization', 'Bearer ' . $res->json('token'))->getJson('/api/me');
        $me->assertOk()->assertJson(['success' => true, 'user' => ['username' => 'otpuser']]);
    }

    public function test_wrong_otp_is_rejected_without_consuming_the_code(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);

        $res = $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => '000000']);

        $res->assertStatus(422)->assertJsonFragment(['message' => 'Invalid or expired code.']);
        $this->assertDatabaseHas('otps', ['UserID' => $user->UserID, 'Consumed' => false]);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        Otp::query()->update(['ExpiresAt' => now()->subMinute()]);

        $res = $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code]);

        $res->assertStatus(422)->assertJsonFragment(['message' => 'Invalid or expired code.']);
    }

    public function test_otp_locks_after_five_wrong_attempts_within_the_same_code(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);

        // 5 wrong guesses exhaust the per-code attempt budget.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => '111111'])
                ->assertStatus(422)
                ->assertJsonFragment(['message' => 'Invalid or expired code.']);
        }

        // The 6th attempt is blocked at the OTP-record level with a distinct message.
        $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => '111111'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Too many attempts. Request a new code.']);
    }

    public function test_repeated_wrong_otp_attempts_eventually_hit_the_ip_rate_limit(): void
    {
        $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        // 8 wrong attempts trips the identifier+IP rate limit (independent of
        // the per-OTP-record attempt cap above).
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => '222222'])
                ->assertStatus(422);
        }

        // Even the correct code is now refused until the lockout expires.
        $res = $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code]);
        $res->assertStatus(429);
    }

    public function test_cancelling_otp_invalidates_the_pending_code(): void
    {
        $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        $this->postJson('/api/auth/cancel-otp', ['username' => 'otpuser'])->assertOk();

        $res = $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code]);
        $res->assertStatus(422)->assertJsonFragment(['message' => 'Invalid or expired code.']);
    }

    /* ---------------- resend ---------------- */

    public function test_resend_issues_a_fresh_code_and_invalidates_the_previous_one(): void
    {
        $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $firstCode = $this->latestOtpCode();

        Carbon::setTestNow(now()->addSeconds(31)); // clear the resend cooldown
        $resend = $this->postJson('/api/auth/resend-otp', ['username' => 'otpuser']);
        $resend->assertOk();
        $secondCode = $this->latestOtpCode();

        $this->assertNotSame($firstCode, $secondCode);

        // The old code no longer works...
        $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $firstCode])
            ->assertStatus(422);

        // ...but the new one does.
        $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $secondCode])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_resend_enforces_a_cooldown_between_requests(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);

        $first = $this->postJson('/api/auth/resend-otp', ['username' => 'otpuser']);
        $first->assertOk();

        // Immediately resending again should be refused.
        $second = $this->postJson('/api/auth/resend-otp', ['username' => 'otpuser']);
        $second->assertStatus(429);

        // After the cooldown window it succeeds again.
        Carbon::setTestNow(now()->addSeconds(31));
        $this->postJson('/api/auth/resend-otp', ['username' => 'otpuser'])->assertOk();
    }

    /* ---------------- login brute-force protection ---------------- */

    public function test_login_locks_out_after_repeated_wrong_passwords(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'wrong'])
                ->assertStatus(401);
        }

        // 6th failed-password request is now blocked outright, even the
        // password is never checked again until the lockout window passes.
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'wrong'])
            ->assertStatus(429);

        // The correct password is refused too, while locked out.
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password'])
            ->assertStatus(429);
    }

    public function test_successful_login_clears_the_failed_attempt_counter(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'wrong'])->assertStatus(401);

        $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password'])
            ->assertOk();

        $this->assertDatabaseCount('rate_limits', 0);
    }

    /* ---------------- remember me / token TTL ---------------- */

    public function test_remember_me_issues_a_longer_lived_token(): void
    {
        $user = $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code, 'remember' => true])
            ->assertOk();

        $token = AuthToken::where('UserID', $user->UserID)->firstOrFail();
        $this->assertTrue(Carbon::parse($token->ExpiresAt)->greaterThan(now()->addDays(20)));
    }

    public function test_without_remember_me_the_token_uses_the_short_default_ttl(): void
    {
        $user = $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['username' => 'otpuser', 'password' => 'correct-password']);
        $code = $this->latestOtpCode();

        $this->postJson('/api/auth/verify-otp', ['username' => 'otpuser', 'otp' => $code])
            ->assertOk();

        $token = AuthToken::where('UserID', $user->UserID)->firstOrFail();
        $this->assertTrue(Carbon::parse($token->ExpiresAt)->lessThan(now()->addDays(20)));
    }
}
