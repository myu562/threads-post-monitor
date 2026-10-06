<?php

namespace Tests\Feature;

use App\Models\PostSnapshot;
use App\Models\TrackedPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostOwnershipTest extends TestCase
{
    /** These tests check the Traditional Chinese interface text. */
    protected ?string $acceptLanguage = 'zh-TW';

    use RefreshDatabase;

    private const URL_A = 'https://www.threads.com/@example_user/post/DFakeP0st01';
    private const URL_B = 'https://www.threads.net/@example_user/post/DFakeP0st01?xmt=FAKEXMTZZZ';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function makeUser(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => 'correct-horse-battery']);
    }

    public function test_post_added_by_a_is_invisible_to_b(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');

        $this->actingAs($a)->post('/posts', ['url' => self::URL_A])->assertRedirect('/');
        $post = TrackedPost::sole();

        $this->actingAs($a)->get('/')->assertSee('@example_user');
        $this->actingAs($b)->get('/')->assertOk()->assertDontSee('@example_user')->assertSee('還沒有追蹤任何貼文');
        $this->actingAs($b)->get('/posts/'.$post->id)->assertNotFound();
    }

    public function test_non_owner_delete_is_404_and_pivot_unchanged(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $post = TrackedPost::sole();
        $pivotBefore = DB::table('tracked_post_user')->count();

        $this->actingAs($b)->delete('/posts/'.$post->id)->assertNotFound();

        $this->assertSame($pivotBefore, DB::table('tracked_post_user')->count());
        $this->assertSame(1, $pivotBefore);
        $this->assertNotNull(TrackedPost::find($post->id));
    }

    public function test_admin_gets_no_special_access_to_others_posts(): void
    {
        $a = $this->makeUser('a@example.test');
        $admin = $this->makeUser('root@example.test');
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $post = TrackedPost::sole();

        $this->actingAs($admin)->get('/')->assertDontSee('@example_user');
        $this->actingAs($admin)->get('/posts/'.$post->id)->assertNotFound();
        $this->actingAs($admin)->delete('/posts/'.$post->id)->assertNotFound();
        $this->assertSame(1, $a->trackedPosts()->count());
    }

    public function test_second_user_shares_existing_post_without_refetch_or_reset(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $post = TrackedPost::sole();
        $post->update(['status' => 'tracking', 'consecutive_failures' => 1, 'last_error' => 'x',
            'next_poll_at' => CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC')]);
        $post->snapshots()->create(['captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page',
            'hours_since_publish' => 1.0, 'views' => 4321]);
        $before = $post->fresh()->getAttributes();

        $this->actingAs($b)->post('/posts', ['url' => self::URL_B])->assertRedirect('/');

        $this->assertSame(1, TrackedPost::count());
        $this->assertSame($before, $post->fresh()->getAttributes());
        Http::assertNothingSent();
        $this->actingAs($b)->get('/')->assertSee('已加入追蹤。')->assertDontSee('共用既有')->assertDontSee('已有其他帳號在追蹤')->assertSee('4,321');
        $this->actingAs($a)->get('/')->assertSee('4,321');
        $this->actingAs($b)->get('/posts/'.$post->id)->assertOk()->assertSee('抓取紀錄（1 筆）');
        $this->actingAs($a)->get('/posts/'.$post->id)->assertOk()->assertSee('抓取紀錄（1 筆）');
    }

    public function test_resubmitting_own_post_says_already_in_list(): void
    {
        $a = $this->makeUser('a@example.test');
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $this->actingAs($a)->post('/posts', ['url' => self::URL_B]);
        $this->actingAs($a)->get('/')->assertSee('這篇貼文已經在你的清單裡。');
        $this->assertSame(1, DB::table('tracked_post_user')->count());
    }

    public function test_untrack_keeps_post_for_other_owner_then_deletes_when_last(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $this->actingAs($b)->post('/posts', ['url' => self::URL_B]);
        $post = TrackedPost::sole();
        $post->snapshots()->create(['captured_at' => CarbonImmutable::now('UTC'), 'source' => 'public_page', 'views' => 7]);

        // A removes: B still tracks it; the flash does not reveal that.
        $this->actingAs($a)->delete('/posts/'.$post->id)->assertRedirect('/');
        $this->actingAs($a)->get('/')->assertSee('已從你的清單移除。')->assertDontSee('其他帳號仍在追蹤')->assertDontSee('@example_user');
        $this->assertNotNull(TrackedPost::find($post->id));
        $this->assertSame(1, PostSnapshot::count());
        $this->actingAs($b)->get('/')->assertSee('@example_user');
        $this->actingAs($b)->get('/posts/'.$post->id)->assertOk();

        // B is the last owner: post and snapshots go away.
        $this->actingAs($b)->delete('/posts/'.$post->id)->assertRedirect('/');
        $this->actingAs($b)->get('/')->assertSee('已從你的清單移除。')->assertDontSee('停止追蹤');
        $this->assertNull(TrackedPost::find($post->id));
        $this->assertSame(0, PostSnapshot::count());
        $this->assertSame(0, DB::table('tracked_post_user')->count());
    }

    public function test_untrack_service_returns_whether_post_was_deleted(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');
        $service = app(\App\Threads\TrackPostService::class);
        $post = $service->register(self::URL_A, $a);
        $service->register(self::URL_B, $b);

        $this->assertFalse($service->untrack($post, $a));
        $this->assertTrue($service->untrack($post->fresh(), $b));
    }

    public function test_remove_buttons_wording(): void
    {
        $a = $this->makeUser('a@example.test');
        $this->actingAs($a)->post('/posts', ['url' => self::URL_A]);
        $post = TrackedPost::sole();

        foreach (['/', '/posts/'.$post->id] as $page) {
            $this->actingAs($a)->get($page)->assertOk()
                ->assertSee('從我的清單移除')
                ->assertDontSee('刪除貼文')
                ->assertSee('只會從你的清單移除')
                ->assertSee('其他帳號在追蹤，他們的資料和抓取不受影響')
                ->assertSee('只有在沒有任何帳號追蹤時');
        }
    }
}
