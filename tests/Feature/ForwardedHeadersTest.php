<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The client must not be able to choose the host the app builds links with, or the address
 * it is treated as coming from.
 *
 * Both used to be possible: proxies were trusted at '*', and because the bundled nginx has
 * already rewritten REMOTE_ADDR to the visitor (real_ip), "the proxy" Laravel trusted was the
 * visitor. A reset request carrying `X-Forwarded-Host: evil.example` produced a genuine email
 * whose link sent the victim's token to evil.example; `X-Forwarded-For` picked any
 * $request->ip(), defeating the IP rate limits and the playlist IP lock.
 */
class ForwardedHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const EVIL = 'evil.example';

    /**
     * The reset link in the email actually sent during the request.
     *
     * A spoofed Host header is simulated by requesting an absolute URL on that host: the test
     * client takes the host from the URL, so a 'Host' entry in withHeaders() never takes effect
     * and a test written that way passes against unfixed code.
     */
    private function resetLinkSentFor(User $user, array $headers, ?string $host = null): string
    {
        $url = route('password.email', absolute: false);
        $this->withHeaders($headers)->post($host ? "http://{$host}{$url}" : $url, ['email' => $user->email]);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages, 'exactly one reset email should have been sent');

        $body = $messages[0]->getOriginalMessage()->getHtmlBody() ?? '';
        $this->assertMatchesRegularExpression('#https?://[^"\s<]+/reset-password/[^"\s<]+#', $body);
        preg_match('#https?://[^"\s<]+/reset-password/[^"\s<]+#', $body, $m);

        return html_entity_decode($m[0]);
    }

    private function assertLinkIsOnAppUrl(string $link): void
    {
        $this->assertStringNotContainsString(self::EVIL, $link, 'the reset link must not carry a client-chosen host');
        $this->assertSame(
            parse_url(config('app.url'), PHP_URL_HOST),
            parse_url($link, PHP_URL_HOST),
            'the reset link must be built from APP_URL',
        );
    }

    public function test_reset_email_ignores_a_forwarded_host(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());

        $user = User::factory()->create();

        $this->assertLinkIsOnAppUrl($this->resetLinkSentFor($user, [
            'X-Forwarded-Host' => self::EVIL,
            'X-Forwarded-Proto' => 'https',
        ]));
    }

    public function test_reset_email_ignores_a_spoofed_host_header(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());

        $user = User::factory()->create();

        $this->assertLinkIsOnAppUrl($this->resetLinkSentFor($user, [], self::EVIL));
    }

    public function test_pages_do_not_link_to_a_spoofed_host(): void
    {
        $this->get('http://'.self::EVIL.route('login', absolute: false))
            ->assertOk()
            ->assertDontSee(self::EVIL, false);
    }

    public function test_the_client_cannot_choose_its_own_ip(): void
    {
        Route::get('/_test/client-ip', fn () => request()->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
            ->get('/_test/client-ip')
            ->assertSeeText('203.0.113.7', false)
            ->assertDontSeeText('198.51.100.99', false);
    }

    public function test_a_forwarded_proto_is_still_honoured(): void
    {
        // TLS terminates at the proxy, so this is the one forwarded header that must keep working.
        Route::get('/_test/is-secure', fn () => request()->isSecure() ? 'yes' : 'no');

        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/_test/is-secure')
            ->assertSeeText('yes');
    }
}
