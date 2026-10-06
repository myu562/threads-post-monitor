<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RA-F16: until a page read confirms the author (published_at_source = page), the username from
 * the submitted URL is marked "unverified", and "Open post" never uses it.
 */
class UnverifiedUsernameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(string $email = 'a@example.test'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'u', 'password' => 'correct-horse-battery']);
    }

    private function mkpost(string $source, string $username = 'fake.brand'): TrackedPost
    {
        $post = TrackedPost::create([
            'url' => 'https://www.threads.com/@'.$username.'/post/DFakeUnv001', 'shortcode' => 'DFakeUnv001', 'username' => $username,
            'published_at' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'), 'published_at_source' => $source,
            'track_until' => CarbonImmutable::parse('2026-10-08 17:00:00', 'UTC'), 'status' => 'tracking',
        ]);
        $this->user()->trackedPosts()->attach($post->id);

        return $post;
    }

    public function test_unverified_username_is_labelled_on_list_and_post_page(): void
    {
        $post = $this->mkpost(TrackedPost::PUBLISHED_FROM_SHORTCODE);
        $this->assertFalse($post->isUsernameVerified());

        foreach (['/', '/posts/'.$post->id] as $page) {
            $html = $this->actingAs($this->user())->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('@fake.brand', $html, $page);
            $this->assertStringContainsString('>'.__('posts.unverified_account').'</span>', $html, $page);
            $this->assertStringContainsString('href="https://www.threads.com/t/DFakeUnv001"', $html, $page);
            $this->assertStringNotContainsString('threads.com/@fake.brand', $html, $page);
        }
    }

    public function test_label_in_both_languages(): void
    {
        $this->mkpost(TrackedPost::PUBLISHED_FROM_SHORTCODE);
        $this->actingAs($this->user())->withHeader('Accept-Language', 'zh-TW')->get('/')->assertOk()->assertSee('未驗證');
        $this->actingAs($this->user())->withUnencryptedCookie('locale', 'en')->get('/')->assertOk()->assertSee('unverified');
    }

    public function test_verified_username_has_no_label(): void
    {
        $post = $this->mkpost(TrackedPost::PUBLISHED_FROM_PAGE, 'real_owner');
        $this->assertTrue($post->isUsernameVerified());

        foreach (['/', '/posts/'.$post->id] as $page) {
            $html = $this->actingAs($this->user())->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('@real_owner', $html, $page);
            $this->assertStringNotContainsString('>'.__('posts.unverified_account').'</span>', $html, $page);
            $this->assertStringContainsString('href="https://www.threads.com/t/DFakeUnv001"', $html, $page);
        }
    }

    public function test_freshly_added_url_username_is_unverified(): void
    {
        $this->actingAs($this->user())->post('/posts', ['url' => 'https://www.threads.com/@fake.brand/post/AAAAAAA'])->assertRedirect();
        $post = TrackedPost::sole();
        $this->assertFalse($post->isUsernameVerified());

        $html = $this->actingAs($this->user())->get('/')->getContent();
        $this->assertStringContainsString('>'.__('posts.unverified_account').'</span>', $html);
        $this->assertStringContainsString('href="https://www.threads.com/t/AAAAAAA"', $html);
    }
}
