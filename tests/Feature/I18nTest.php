<?php

namespace Tests\Feature;

use App\Http\Controllers\LocaleController;
use App\Http\Middleware\SetLocale;
use App\Models\TrackedPost;
use App\Models\User;
use App\Support\StoredMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Interface language (contracts L1–L8): detection order, the header switch, translation coverage,
 * stored error codes and the track-hours parameter.
 */
class I18nTest extends TestCase
{
    use RefreshDatabase;

    /** CJK ideographs and symbols, kana, hangul, full-width forms. */
    private const CJK = '/[\x{1100}-\x{11FF}\x{2E80}-\x{2FDF}\x{3000}-\x{30FF}\x{3130}-\x{318F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FFEF}]/u';

    private const GROUPS = ['ui', 'posts', 'users', 'errors', 'auth', 'validation', 'cli'];

    // ---- (a) no hard-coded CJK text outside lang/ ----

    public function test_no_cjk_characters_in_views_or_app_code(): void
    {
        $dirs = [resource_path('views'), app_path()]; // views include personal/; app covers Http, Models, Threads
        $hits = [];
        $scanned = 0;
        foreach ($dirs as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $info) {
                if (! str_ends_with($info->getFilename(), '.php')) {
                    continue;
                }
                $scanned++;
                foreach (file($info->getPathname()) as $n => $line) {
                    if (preg_match(self::CJK, $line)) {
                        $hits[] = $info->getPathname().':'.($n + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $scanned);
        $this->assertSame([], $hits, "User-facing text belongs in lang/{zh_TW,en}/*.php:\n".implode("\n", $hits));
    }

    // ---- (b) both languages have exactly the same keys ----

    public function test_both_languages_have_the_same_files_keys_and_placeholders(): void
    {
        $files = fn (string $locale) => array_map('basename', glob(lang_path($locale.'/*.php')));
        $this->assertSame($files('en'), $files('zh_TW'));
        $this->assertNotEmpty($files('en'));
        $this->assertSame([], glob(lang_path('*.json')), 'Use grouped PHP files, not JSON string keys.');

        foreach ($files('en') as $file) {
            $en = self::flatten(require lang_path('en/'.$file));
            $zh = self::flatten(require lang_path('zh_TW/'.$file));
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($zh))), "missing in zh_TW/{$file}");
            $this->assertSame([], array_values(array_diff(array_keys($zh), array_keys($en))), "missing in en/{$file}");

            foreach ($en as $key => $text) {
                $this->assertIsString($text, "{$file} {$key}");
                $this->assertNotSame('', trim($text), "{$file} {$key}");
                $this->assertSame(self::placeholders($text), self::placeholders($zh[$key]), "placeholders differ: {$file} {$key}");
                $this->assertDoesNotMatchRegularExpression(self::CJK, $key);
            }
        }
    }

    public function test_the_key_comparison_notices_a_missing_nested_key(): void
    {
        $en = self::flatten(['a' => ['b' => 'x', 'c' => ['d' => 'y']]]);
        $zh = self::flatten(['a' => ['b' => 'x', 'c' => []]]);
        $this->assertSame(['a.c.d'], array_values(array_diff(array_keys($en), array_keys($zh))));
    }

    /** @return array<string, mixed> */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $k => $v) {
            if (is_array($v) && $v !== []) {
                $out += self::flatten($v, $prefix.$k.'.');
            } else {
                $out[$prefix.$k] = $v;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function placeholders(mixed $text): array
    {
        preg_match_all('/:([a-z_]+)/', (string) $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    // ---- (c) every page renders in both languages, without raw keys ----

    public function test_every_page_renders_in_both_languages_without_raw_keys(): void
    {
        [$admin, $post] = $this->seedData();

        foreach (['zh-TW' => 'zh-Hant-TW', 'en-US' => 'en'] as $header => $htmlLang) {
            $this->withHeader('Accept-Language', $header);
            $pages = [];

            $this->flushSession();
            $this->app['auth']->forgetGuards(); // signed out again for the second language
            $pages['login'] = $this->get('/login');
            $pages['login-error'] = $this->followingRedirects()->from('/login')->post('/login', ['email' => 'nobody@example.test', 'password' => 'x']);
            $pages['not-found'] = $this->get('/no-such-page');

            $this->actingAs($admin);
            $pages['index'] = $this->get('/');
            $pages['index-filtered'] = $this->get('/?q=example&status=tracking&tag[]=client');
            $pages['index-filtered-empty'] = $this->get('/?q=nothing-matches-this');
            $pages['index-group'] = $this->get('/?group=none');
            $pages['index-meta-errors'] = $this->followingRedirects()->from('/')
                ->put('/posts/'.$post->id.'/meta', ['group' => 'new', 'new_group' => '', 'tags' => 'a,b#c', 'note' => 'x', 'redirect' => '/']);
            $pages['index-group-errors'] = $this->followingRedirects()->from('/')->post('/groups', ['name' => 'Client work', 'redirect' => '/']);
            $pages['show'] = $this->get('/posts/'.$post->id);
            $pages['show-errors'] = $this->followingRedirects()->from('/posts/'.$post->id)
                ->put('/posts/'.$post->id.'/tags', ['tags' => 'a,b#c']);
            $pages['store-error'] = $this->followingRedirects()->from('/')->post('/posts', ['url' => 'https://example.com/nope']);
            $pages['store-required'] = $this->followingRedirects()->from('/')->post('/posts', ['url' => '']);
            $pages['password'] = $this->get('/account/password');
            $pages['password-errors'] = $this->followingRedirects()->from('/account/password')
                ->put('/account/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'x']);
            $pages['users'] = $this->get('/users');
            $pages['users-create'] = $this->get('/users/create');
            $pages['users-create-errors'] = $this->followingRedirects()->from('/users/create')->post('/users', ['name' => '', 'email' => 'bad', 'password' => 'short']);
            $pages['users-edit'] = $this->get('/users/'.$admin->id.'/edit');

            foreach ($pages as $name => $response) {
                $expected = $name === 'not-found' ? 404 : 200;
                $this->assertSame($expected, $response->getStatusCode(), "{$header} {$name}");
                $html = (string) $response->getContent();
                $this->assertStringContainsString('<html lang="'.$htmlLang.'">', $html, "{$header} {$name}");
                $this->assertNoRawKeys($html, "{$header} {$name}");
                if ($header === 'en-US') {
                    $this->assertNoCjkOutsideSwitch($html, $name);
                } else {
                    $this->assertMatchesRegularExpression('/\p{Han}/u', self::visibleText($html), "zh {$name} has Chinese");
                }
            }
        }
    }

    public function test_throttled_login_page_is_translated(): void
    {
        foreach (['zh-TW' => '登入嘗試次數過多', 'en' => 'Too many login attempts'] as $header => $text) {
            $this->withHeader('Accept-Language', $header);
            for ($i = 0; $i < 6; $i++) {
                $response = $this->post('/login', ['email' => 'nobody'.$header.'@example.test', 'password' => 'x', '_i' => $i]);
            }
            $response->assertStatus(429)->assertSee($text);
            $this->assertNoRawKeys((string) $response->getContent(), "429 {$header}");
            app('cache')->store()->flush();
        }
    }

    private function assertNoRawKeys(string $html, string $context): void
    {
        $text = self::visibleText($html).' '.implode(' ', self::attributes($html));
        $groups = implode('|', self::GROUPS);
        $this->assertDoesNotMatchRegularExpression('/\b(?:'.$groups.')\.[a-z0-9_]+(?:\.[a-z0-9_]+)*\b/', $text, "raw translation key on {$context}");
    }

    /** English pages: the only CJK allowed is the language switch, which names the target language (中文). */
    private function assertNoCjkOutsideSwitch(string $html, string $context): void
    {
        $withoutSwitch = preg_replace('#<button[^>]*data-locale-toggle.*?</button>#s', '', $html);
        $withoutSwitch = preg_replace('#\s(?:aria-label|title)="[^"]*\(switch to Traditional Chinese\)"#', '', (string) $withoutSwitch);
        $this->assertDoesNotMatchRegularExpression(self::CJK, (string) $withoutSwitch, "CJK on English page {$context}");
        if ($context !== 'not-found') {
            $this->assertStringContainsString('中文', $html, "switch shown on {$context}");
        }
    }

    private static function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private static function attributes(string $html): array
    {
        preg_match_all('/\s(?:title|aria-label|placeholder|data-confirm|data-title-to-light|data-title-to-dark|value)="([^"]*)"/', $html, $m);

        return array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    }

    /** @return array{0: User, 1: TrackedPost} */
    private function seedData(): array
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
        $admin->forceFill(['is_admin' => true])->save();

        $pub = CarbonImmutable::now('UTC')->subHours(30);
        $post = TrackedPost::create([
            'url' => 'https://www.threads.com/@example_user/post/DFakeP0st01', 'shortcode' => 'DFakeP0st01',
            'username' => 'example_user', 'published_at' => $pub, 'published_at_source' => 'shortcode_decode',
            'track_until' => $pub->addHours(72), 'next_poll_at' => $pub->addHours(31), 'status' => 'tracking',
            'caption' => str_repeat('Example caption text. ', 40), 'counts_hidden' => true, 'added_after_window' => true,
            'last_error' => StoredMessage::encode('fetch.http_error', ['status' => 503]), 'consecutive_failures' => 2,
        ]);
        foreach ([[1, 100], [2, 160], [2.25, null], [5, 400]] as [$h, $views]) {
            $post->snapshots()->create([
                'captured_at' => $pub->addMinutes((int) ($h * 60)), 'hours_since_publish' => $h,
                'views' => $views, 'likes' => null, 'replies' => 3, 'reposts' => 1, 'quotes' => 0, 'shares' => 2, 'source' => 'public_page',
            ]);
        }
        $done = TrackedPost::create([
            'url' => 'https://www.threads.com/@other_user/post/DFakeP0st02', 'shortcode' => 'DFakeP0st02',
            'username' => 'other_user', 'published_at' => $pub->subHours(80), 'published_at_source' => 'page',
            'track_until' => $pub->subHours(8), 'status' => 'gone', 'final_views' => 9,
            'final_captured_at' => $pub->subHours(9), 'last_error' => StoredMessage::encode('fetch.gone', ['status' => 404]),
        ]);
        $admin->trackedPosts()->attach([$post->id, $done->id]);
        $entry = \App\Models\TrackedPostUser::where('user_id', $admin->id)->where('tracked_post_id', $post->id)->sole();
        $entry->tags()->sync([$admin->tags()->create(['name' => 'client'])->id]);
        $entry->update(['note' => 'A private note', 'group_id' => $admin->groups()->create(['name' => 'Client work', 'sort_order' => 1])->id]);
        $admin->groups()->create(['name' => 'Empty group', 'sort_order' => 2]);

        return [$admin, $post];
    }

    // ---- (d) detection order: cookie, Accept-Language, default ----

    public function test_cookie_beats_accept_language_beats_default(): void
    {
        $this->assertLoginLang('en', []);
        $this->assertLoginLang('zh-Hant-TW', ['Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8']);
        $this->assertLoginLang('en', ['Accept-Language' => 'zh-TW'], 'en');
        $this->assertLoginLang('zh-Hant-TW', ['Accept-Language' => 'en-US'], 'zh_TW');
        // An unknown cookie value is ignored, not trusted.
        $this->assertLoginLang('zh-Hant-TW', ['Accept-Language' => 'zh-TW'], 'fr');
        $this->assertLoginLang('en', [], 'zh-TW');

        // The configured default (APP_LOCALE), as captured at boot: App::setLocale() rewrites
        // config('app.locale') on every request, so the middleware keeps the original separately.
        config(['app.default_locale' => 'zh_TW']);
        $this->assertLoginLang('zh-Hant-TW', []);
        $this->assertLoginLang('en', ['Accept-Language' => 'fr-FR']);
        config(['app.default_locale' => 'de']);
        $this->assertLoginLang('en', []);
    }

    private function assertLoginLang(string $htmlLang, array $headers, ?string $cookie = null): void
    {
        $this->flushHeaders();
        // Symfony's test requests send "Accept-Language: en-us,en;q=0.5" unless told otherwise; an
        // empty header stands for a browser that sends none.
        $request = $this->withHeaders($headers + ['Accept-Language' => '']);
        if ($cookie !== null) {
            $request = $request->withUnencryptedCookie(SetLocale::COOKIE, $cookie);
        }
        $request->get('/login')->assertOk()->assertSee('<html lang="'.$htmlLang.'">', false);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    public function test_accept_language_is_ordered_by_q_and_first_language_decides(): void
    {
        $cases = [
            'zh-TW' => 'zh_TW',
            'zh-CN,zh;q=0.9' => 'zh_TW',
            'ZH-hant-tw' => 'zh_TW',
            'en-US,en;q=0.9,zh-TW;q=0.8' => 'en',
            'fr-FR,zh-TW;q=0.9' => 'en',
            'ja' => 'en',
            'en;q=0.5, zh-TW;q=0.9' => 'zh_TW',
            'zh-TW;q=0, en' => 'en',
            'zh;q=0.8, en;q=0.8' => 'zh_TW', // equal q: header order
            '*, zh-TW;q=0.5' => 'zh_TW',     // "*" names no language
            'en ; q = 0.3 , zh-TW ; q = 0.4' => 'zh_TW',
        ];
        foreach ($cases as $header => $expected) {
            $this->assertSame($expected, SetLocale::fromAcceptLanguage($header), $header);
        }
        foreach (['', '*', ' , ', 'zh;q=0', '!!!'] as $header) {
            $this->assertNull(SetLocale::fromAcceptLanguage($header), $header);
        }
    }

    // ---- (e) POST /locale ----

    public function test_locale_switch_sets_cookie_and_returns_to_the_page(): void
    {
        $response = $this->post('/locale', ['locale' => 'zh_TW', 'redirect' => '/login?x=1']);
        $response->assertRedirect('/login?x=1');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === SetLocale::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertSame('zh_TW', $cookie->getValue()); // not encrypted: it only ever holds zh_TW or en
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addYear()->getTimestamp(), $cookie->getExpiresTime(), 86400 * 2);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'zh_TW')->get('/login')->assertSee('<html lang="zh-Hant-TW">', false)->assertSee('記住我');

        $secure = $this->post('https://localhost/locale', ['locale' => 'en', 'redirect' => '/login']);
        $this->assertTrue(collect($secure->headers->getCookies())->first(fn ($c) => $c->getName() === SetLocale::COOKIE)->isSecure());
    }

    public function test_locale_switch_rejects_other_values_with_422(): void
    {
        foreach ([['locale' => 'fr'], ['locale' => 'zh-TW'], ['locale' => ''], [], ['locale' => ['en']]] as $data) {
            $response = $this->post('/locale', $data + ['redirect' => '/login']);
            $response->assertStatus(422);
            $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === SetLocale::COOKIE));
        }
    }

    public function test_locale_switch_only_follows_same_site_relative_paths(): void
    {
        $bad = ['https://evil.example/', '//evil.example/x', '/\\evil.example', '\\\\evil.example', 'javascript:alert(1)',
            'evil.example', "/\tevil", "/x\nLocation: https://evil.example", "\t//evil.example", ''];
        foreach ($bad as $target) {
            $this->assertSame('/', LocaleController::safeRedirect($target), json_encode($target));
            $this->post('/locale', ['locale' => 'en', 'redirect' => $target])->assertRedirect('/');
        }
        $this->assertSame('/', LocaleController::safeRedirect(['/login']));
        $this->assertSame('/', LocaleController::safeRedirect(null));
        $this->post('/locale', ['locale' => 'en'])->assertRedirect('/');

        // Percent-encoded characters stay encoded in the path: still the same site.
        foreach (['/', '/login', '/posts/3?q=a%20b&tag[]=x', '/users/1/edit#top', '/%0D%0A'] as $good) {
            $this->assertSame($good, LocaleController::safeRedirect($good));
        }
        $location = $this->post('/locale', ['locale' => 'en', 'redirect' => '/posts/3?q=x'])->headers->get('Location');
        $this->assertSame(url('/posts/3?q=x'), $location);
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($location, PHP_URL_HOST));
    }

    public function test_locale_switch_is_csrf_protected(): void
    {
        // CSRF checks are skipped while running unit tests; pretend we are not.
        $this->app['env'] = 'local';
        try {
            $this->post('/locale', ['locale' => 'en', 'redirect' => '/login'])->assertStatus(419);
            $this->withSession(['_token' => 'known-token'])
                ->post('/locale', ['locale' => 'en', 'redirect' => '/login', '_token' => 'wrong-token'])->assertStatus(419);
            $this->withSession(['_token' => 'known-token'])
                ->post('/locale', ['locale' => 'en', 'redirect' => '/login', '_token' => 'known-token'])->assertRedirect('/login');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertTrue(Route::has('locale.update'));
        $this->get('/locale')->assertStatus(405);
    }

    public function test_switch_is_in_both_headers_for_guests_and_users(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => 'correct-horse-battery']);

        // The rendered pages use the personal header when resources/views/personal exists; the public
        // header must carry the switch too (it is what the public export ships).
        foreach (['partials/header.blade.php', 'personal/partials/header.blade.php'] as $file) {
            $path = resource_path('views/'.$file);
            if (! is_file($path)) {
                continue;
            }
            $this->assertSame(2, substr_count((string) file_get_contents($path), "@include('partials.top-toggles')"), $file);
        }
        $this->actingAs($user);
        $this->get('/'); // a request, so the session and route exist for the direct render below
        $public = view()->file(resource_path('views/partials/header.blade.php'))->render();
        $this->assertStringContainsString('data-locale-toggle', $public);
        $this->assertStringContainsString(route('locale.update'), $public);

        foreach (['en' => ['中文', '中文 (switch to Traditional Chinese)', 'zh_TW'], 'zh-TW' => ['EN', 'EN（切換為英文介面）', 'en']] as $header => [$text, $label, $target]) {
            foreach ([true, false] as $signedIn) {
                $this->flushSession();
                if ($signedIn) {
                    $this->actingAs($user);
                } else {
                    $this->app['auth']->forgetGuards();
                }
                $path = $signedIn ? route('account.password', [], false) : '/login';
                $html = (string) $this->withHeader('Accept-Language', $header)->get($path)->assertOk()->getContent();
                $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('locale.update'), '#').'" class="locale-form">\s*<input type="hidden" name="_token"#', $html);
                $this->assertStringContainsString('<input type="hidden" name="locale" value="'.$target.'">', $html);
                $this->assertStringContainsString('<input type="hidden" name="redirect" value="'.$path.'">', $html);
                $this->assertMatchesRegularExpression('#<button type="submit" class="locale-toggle" data-locale-toggle aria-label="'.preg_quote(e($label), '#').'"#u', $html);
                $this->assertMatchesRegularExpression('#data-locale-toggle[^>]*>\s*<span lang="[^"]+">'.preg_quote($text, '#').'</span>#u', $html);
            }
        }
    }

    // ---- L4: stored error codes ----

    public function test_stored_errors_are_codes_rendered_in_the_viewer_language(): void
    {
        $this->assertSame('fetch.http_error|{"status":503}', StoredMessage::encode('fetch.http_error', ['status' => 503]));
        $this->assertSame('poll.no_publish_time|{}', StoredMessage::encode('poll.no_publish_time'));

        $cases = [
            'fetch.http_error|{"status":503}' => ['非預期的 HTTP 503。', 'Unexpected HTTP 503.'],
            'exception|{"class":"RuntimeException"}' => ['RuntimeException：內部錯誤，詳見伺服器 log', 'RuntimeException: internal error, see the server log'],
            'fetch.gone|{"status":410}' => ['HTTP 410：找不到貼文。', 'HTTP 410: post not found.'],
            // Older rows are plain text, and anything unknown or malformed is shown as stored.
            '[blocked] HTTP 429: rate limited.' => ['[blocked] HTTP 429: rate limited.', '[blocked] HTTP 429: rate limited.'],
            'fetch.unknown_code|{}' => ['fetch.unknown_code|{}', 'fetch.unknown_code|{}'],
            'fetch|{}' => ['fetch|{}', 'fetch|{}'],
            'fetch.http_error|{"status":{"x":1}}' => ['fetch.http_error|{"status":{"x":1}}', 'fetch.http_error|{"status":{"x":1}}'],
            'fetch.http_error|not json' => ['fetch.http_error|not json', 'fetch.http_error|not json'],
        ];
        foreach ($cases as $raw => [$zh, $en]) {
            app()->setLocale('zh_TW');
            $this->assertSame($zh, StoredMessage::render($raw), $raw);
            app()->setLocale('en');
            $this->assertSame($en, StoredMessage::render($raw), $raw);
        }
        $this->assertNull(StoredMessage::render(null));
    }

    public function test_every_code_the_poller_can_store_has_a_translation(): void
    {
        $sources = file_get_contents(app_path('Threads/PublicPageFetcher.php')).file_get_contents(app_path('Threads/PostPoller.php'));
        preg_match_all("/'(fetch\\.[a-z_]+|poll\\.'\\.self::STATUS_[A-Z_]+)'/", $sources, $m);
        $codes = array_merge($m[1], ['exception', 'poll.no_publish_time']);
        foreach (['gone', 'blocked', 'parse_failed', 'network_error', 'http_error'] as $status) {
            $codes[] = 'fetch.'.$status; // FetchResult::failure() falls back to fetch.<status>
        }
        $this->assertGreaterThanOrEqual(10, count(array_unique($codes)));
        foreach (array_unique($codes) as $code) {
            if (str_contains($code, 'self::')) {
                continue;
            }
            foreach (['zh_TW', 'en'] as $locale) {
                $this->assertIsString(trans('errors.stored.'.$code, [], $locale), "{$locale} {$code}");
                $this->assertNotSame('errors.stored.'.$code, trans('errors.stored.'.$code, [], $locale), "{$locale} {$code}");
            }
        }
    }

    // ---- L8: track hours come from config ----

    public function test_track_hours_appear_from_config_not_hard_coded(): void
    {
        config(['threads.track_hours' => 48]);
        [$admin] = $this->seedData();
        $done = TrackedPost::create([
            'url' => 'https://www.threads.com/@done_user/post/DFakeP0st03', 'shortcode' => 'DFakeP0st03', 'username' => 'done_user',
            'published_at' => CarbonImmutable::now('UTC')->subHours(60), 'published_at_source' => 'page',
            'track_until' => CarbonImmutable::now('UTC')->subHours(12), 'status' => 'done', 'final_views' => 5,
            'final_captured_at' => CarbonImmutable::now('UTC')->subHours(12), 'added_after_window' => true,
        ]);
        $admin->trackedPosts()->attach($done->id);
        $this->actingAs($admin);

        foreach (['zh-TW' => ['48 小時最終', '加入時已超過 48 小時，非 48 小時那一刻的數字'], 'en' => ['48-hour final', 'Added after 48 hours: not the 48-hour figures']] as $header => $expected) {
            foreach (['/', '/posts/'.$done->id] as $page) {
                $text = self::visibleText((string) $this->withHeader('Accept-Language', $header)->get($page)->assertOk()->getContent());
                foreach ($expected as $line) {
                    $this->assertStringContainsString($line, $text, "{$header} {$page}");
                }
                $this->assertStringNotContainsString('72', preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?|\d{4}-\d{2}-\d{2} \d{2}(時|:00)/u', '', $text), "{$header} {$page}");
            }
        }

        config(['threads.track_hours' => 72]);
        app()->setLocale('zh_TW');
        $this->assertSame('72 小時最終', $done->fresh()->displayMetrics()['label']);
    }

    // ---- L3: date formats per language ----

    public function test_hour_format_follows_the_language(): void
    {
        config(['threads.display_timezone' => 'Asia/Taipei']);
        $t = CarbonImmutable::parse('2026-10-05 17:30:45', 'UTC');
        app()->setLocale('zh_TW');
        $this->assertSame('2026-10-06 01時', TrackedPost::localTime($t));
        $this->assertSame('2026-10-06 01:30:45', TrackedPost::localTime($t, 'Y-m-d H:i:s'));
        app()->setLocale('en');
        $this->assertSame('2026-10-06 01:00', TrackedPost::localTime($t));
        $this->assertSame('2026-10-06 01:30:45', TrackedPost::localTime($t, 'Y-m-d H:i:s'));
        $this->assertSame('—', TrackedPost::localTime(null));
    }
}
