<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ExistingAccountSignUpAttempt;
use App\Notifications\VerifyEmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The sign-up form must not reveal whether an address already has an account — not by message,
 * status, response body, sign-in state, or by how the code screen behaves afterwards.
 */
class RegistrationEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(string $email, string $password = 'secret-password-123', bool $json = true): TestResponse
    {
        $data = [
            'name' => 'Someone',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ];

        return $json ? $this->postJson(route('register.store'), $data) : $this->post(route('register.store'), $data);
    }

    /** A fresh browser: no session, no cached user. */
    private function freshBrowser(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /** Everything a browser can observe about one request. */
    private function observe(TestResponse $r): array
    {
        return [
            'status' => $r->getStatusCode(),
            'body' => $r->getContent(),
            'location' => $r->headers->get('Location'),
            'cookies' => collect($r->headers->getCookies())->map->getName()->sort()->values()->all(),
            'signed_in' => $this->isAuthenticated(),
            'dashboard' => $this->get(route('dashboard'))->headers->get('Location'),
        ];
    }

    public function test_a_taken_address_gets_the_same_answer_as_a_new_one(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);

        foreach ([true, false] as $json) {
            $this->freshBrowser();
            $new = $this->observe($this->signUp($json ? 'new-json@example.com' : 'new-form@example.com', json: $json));
            $this->freshBrowser();
            $taken = $this->observe($this->signUp('taken@example.com', json: $json));

            $this->assertSame($new, $taken, $json ? 'JSON sign-up' : 'form sign-up');
            $this->assertFalse($taken['signed_in']);
        }
    }

    public function test_a_taken_address_creates_nothing_and_tells_its_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Owner']);
        $hash = $owner->password;

        $this->signUp('owner@example.com', 'attacker-password-1')->assertCreated();

        $this->assertSame(1, User::where('email', 'owner@example.com')->count());
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->assertSame($hash, $owner->fresh()->password);
        Notification::assertSentTo($owner, ExistingAccountSignUpAttempt::class);
        Notification::assertNotSentTo($owner, VerifyEmailCode::class);
    }

    public function test_the_owner_is_not_flooded_with_notices(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->freshBrowser();
            $this->signUp('owner@example.com')->assertCreated();
        }

        Notification::assertSentToTimes($owner, ExistingAccountSignUpAttempt::class, 3);
    }

    public function test_a_new_sign_up_finishes_with_the_emailed_code_without_being_signed_in(): void
    {
        Notification::fake();

        $this->signUp('fresh@example.com')->assertCreated();
        $this->assertGuest();

        $user = User::where('email', 'fresh@example.com')->firstOrFail();
        $code = null;
        Notification::assertSentTo($user, VerifyEmailCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson(route('verification.code'), ['code' => $code])
            ->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /** The code screen after a refused sign-up behaves like a real one where every code is wrong. */
    public function test_the_code_screen_behaves_the_same_for_a_taken_address(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);

        $script = function (): array {
            $seen = [];
            for ($i = 0; $i < User::VERIFICATION_MAX_ATTEMPTS; $i++) {
                $r = $this->postJson(route('verification.code'), ['code' => '000000']);
                $seen[] = [$r->getStatusCode(), $r->json('error')];
            }
            $r = $this->postJson(route('verification.resend'));      // within the cooldown
            $seen[] = [$r->getStatusCode(), $r->json('error'), $r->json('retry_after')];

            $this->travel(User::VERIFICATION_RESEND_MINUTES)->minutes();
            $r = $this->postJson(route('verification.resend'));
            $seen[] = [$r->getStatusCode(), $r->json('message'), $r->json('resend_available_in')];
            $r = $this->postJson(route('verification.code'), ['code' => '000000']);   // count reset
            $seen[] = [$r->getStatusCode(), $r->json('error')];
            $this->travelBack();

            return $seen;
        };

        // Two browsers on two addresses, so the per-IP route throttle can't tell them apart.
        $this->freshBrowser();
        $this->freezeSecond();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->signUp('new@example.com')->assertCreated();
        $new = $script();

        $this->freshBrowser();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2']);
        $this->signUp('taken@example.com')->assertCreated();
        $taken = $script();

        $this->assertSame($new, $taken, json_encode([$new, $taken]));
        $this->assertSame('Too many incorrect codes. Request a new code and try again.', $taken[User::VERIFICATION_MAX_ATTEMPTS - 1][1]);
    }

    public function test_validation_errors_are_unchanged_for_a_taken_address(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $bad = ['name' => 'Someone', 'password' => 'secret-password-123', 'password_confirmation' => 'something-else'];
        $new = $this->postJson(route('register.store'), $bad + ['email' => 'new@example.com']);
        $taken = $this->postJson(route('register.store'), $bad + ['email' => 'taken@example.com']);

        // A JSON 422, not a redirect: fetch() follows a redirect to a 200 page and the sign-up
        // screen took that for success.
        $new->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame($new->json('errors'), $taken->json('errors'));
        $this->assertArrayNotHasKey('email', $taken->json('errors'));
    }

    public function test_the_code_endpoints_still_refuse_a_browser_with_no_sign_up(): void
    {
        $this->postJson(route('verification.code'), ['code' => '123456'])->assertUnauthorized();
        $this->postJson(route('verification.resend'))->assertUnauthorized();
    }
}
