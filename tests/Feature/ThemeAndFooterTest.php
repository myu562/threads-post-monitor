<?php

namespace Tests\Feature;

use App\Models\TrackedPost;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeAndFooterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
        $u->forceFill(['is_admin' => true, 'is_active' => true])->save();

        return $u;
    }

    private function makePost(User $owner): TrackedPost
    {
        $post = TrackedPost::create([
            'url' => 'https://www.threads.com/@example_user/post/DFakeP0st01',
            'shortcode' => 'DFakeP0st01',
            'username' => 'example_user',
            'published_at' => CarbonImmutable::parse('2026-10-05 17:30:00', 'UTC'),
            'published_at_source' => 'page',
            'track_until' => CarbonImmutable::parse('2026-10-08 17:30:00', 'UTC'),
            'next_poll_at' => CarbonImmutable::parse('2026-10-05 18:30:00', 'UTC'),
            'status' => 'tracking',
        ]);
        $owner->trackedPosts()->attach($post->id);

        return $post;
    }

    /** @return array<string, string> page name => rendered HTML */
    private function allPages(): array
    {
        $pages = ['login' => $this->get('/login')->assertOk()->getContent()];

        $admin = $this->admin();
        $post = $this->makePost($admin);
        $this->actingAs($admin);
        $pages['index'] = $this->get('/')->assertOk()->getContent();
        $pages['show'] = $this->get('/posts/'.$post->id)->assertOk()->getContent();
        $pages['users'] = $this->get('/users')->assertOk()->getContent();
        $pages['account/password'] = $this->get('/account/password')->assertOk()->getContent();

        return $pages;
    }

    public function test_every_page_has_theme_toggle_footer_and_stylesheet(): void
    {
        foreach ($this->allPages() as $name => $html) {
            $this->assertMatchesRegularExpression('/<button type="button" class="theme-toggle" data-theme-toggle aria-label="[^"]+" aria-pressed="false"/', $html, "toggle on $name");
            $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html, "color-scheme on $name");
            $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/css/app\.css\?v=\d+">#', $html, "versioned css on $name");
            $this->assertMatchesRegularExpression('#<script src="[^"]*/js/theme\.js\?v=\d+" defer></script>#', $html, "theme.js on $name");
            $this->assertStringContainsString('<footer class="site-footer">', $html, "footer on $name");
            $this->assertStringContainsString(
                '<a href="'.e(config('threads.footer.url')).'" target="_blank" rel="noopener noreferrer">'.e(config('threads.footer.text')).'</a>',
                $html,
                "footer link on $name"
            );
            // The footer comes after the page content.
            $this->assertGreaterThan(strpos($html, 'class="wrap"'), strpos($html, '<footer'), "footer position on $name");
        }
    }

    public function test_inline_theme_script_runs_before_first_stylesheet(): void
    {
        foreach ($this->allPages() as $name => $html) {
            $script = strpos($html, "localStorage.getItem('theme')");
            $setAttr = strpos($html, "setAttribute('data-theme'");
            $this->assertNotFalse($setAttr, "data-theme set on $name");
            $css = strpos($html, '<link rel="stylesheet"');
            $this->assertNotFalse($script, "inline script on $name");
            $this->assertNotFalse($css, "stylesheet on $name");
            $this->assertLessThan($css, $script, "inline script before stylesheet on $name");
            $this->assertLessThan($css, $setAttr, "data-theme set before stylesheet on $name");
            // localStorage access is guarded (it can throw in private mode).
            $this->assertMatchesRegularExpression("/try\\{t=localStorage\\.getItem\\('theme'\\)\\}catch\\(e\\)\\{\\}/", $html);
            // It is the first script in <head>.
            $this->assertSame($script, strpos($html, 'localStorage'), "first script on $name");
            $this->assertLessThan(strpos($html, '<title>'), $script, "inline script near the top of head on $name");
        }
    }

    public function test_footer_can_be_turned_off(): void
    {
        config(['threads.footer.enabled' => false]);
        foreach ($this->allPages() as $name => $html) {
            $this->assertStringNotContainsString('site-footer', $html, "no footer on $name");
            $this->assertStringNotContainsString('Powered by', $html, "no credit on $name");
        }
    }

    public function test_footer_enabled_accepts_string_false_from_env(): void
    {
        $this->assertNull(AppServiceProvider::footer(['enabled' => 'false', 'text' => 'X', 'url' => 'https://example.com/']));
        $this->assertNull(AppServiceProvider::footer(['enabled' => '0', 'text' => 'X', 'url' => 'https://example.com/']));
        $this->assertNotNull(AppServiceProvider::footer(['enabled' => 'true', 'text' => 'X', 'url' => 'https://example.com/']));
        $this->assertNull(AppServiceProvider::footer(['enabled' => true, 'text' => '   ', 'url' => 'https://example.com/']));
    }

    public function test_custom_text_and_link(): void
    {
        config(['threads.footer.text' => 'Run by <Example> & Co', 'threads.footer.url' => 'https://example.com/me?a=1&b=2']);

        $this->get('/login')->assertOk()
            ->assertSee('<a href="https://example.com/me?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">Run by &lt;Example&gt; &amp; Co</a>', false)
            ->assertDontSee('Powered by');
    }

    public function test_empty_url_shows_text_only(): void
    {
        config(['threads.footer.url' => '']);

        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<span>'.e(config('threads.footer.text')).'</span>', $html);
        $this->assertDoesNotMatchRegularExpression('#<footer class="site-footer">\s*<a#', $html);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(document.cookie)'],
            'javascript with leading space' => ['  javascript:alert(1)'],
            'javascript with tab inside' => ["java\tscript:alert(1)"],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'ftp' => ['ftp://example.com/'],
            'relative' => ['/some/path'],
            'protocol relative' => ['//example.com/x'],
            'https without host' => ['https:example.com'],
            'not a string' => [['https://example.com/']],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_non_http_url_is_never_a_link(mixed $url): void
    {
        config(['threads.footer.url' => $url]);

        $html = $this->get('/login')->assertOk()->getContent();
        $footer = substr($html, (int) strpos($html, '<footer'));
        $this->assertStringContainsString('<span>'.e(config('threads.footer.text')).'</span>', $footer);
        $this->assertStringNotContainsString('<a ', $footer);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsStringIgnoringCase('vbscript:', $html);

        $this->assertNull(AppServiceProvider::footer(['enabled' => true, 'text' => 'X', 'url' => $url])['url']);
    }

    public function test_http_and_https_urls_are_kept(): void
    {
        foreach (['http://example.com/', 'https://example.com/a?b=c', 'HTTPS://EXAMPLE.COM/'] as $url) {
            $this->assertSame($url, AppServiceProvider::footer(['enabled' => true, 'text' => 'X', 'url' => $url])['url']);
        }
    }

    public function test_head_and_body_extra_hooks_are_optional(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertMatchesRegularExpression("/@includeIf\\('partials\\.head-extra'\\)\\s*<\\/head>/", $layout);
        $this->assertMatchesRegularExpression("/@includeIf\\('partials\\.body-extra'\\)\\s*<\\/body>/", $layout);

        // The public views do not ship the extra partials; only an (export-excluded) personal layer may add them.
        $this->assertFileDoesNotExist(resource_path('views/partials/head-extra.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/partials/body-extra.blade.php'));

        $this->get('/login')->assertOk();
    }

    /** Loads config/view.php as if the app lived in $base. */
    private function viewPathsFor(string $base): array
    {
        $original = $this->app->basePath();
        $configFile = $this->app->configPath('view.php');
        try {
            $this->app->setBasePath($base);
            $config = require $configFile;
        } finally {
            $this->app->setBasePath($original);
        }

        return $config['paths'];
    }

    public function test_view_paths_put_personal_layer_first_only_when_it_exists(): void
    {
        $fs = new Filesystem;
        $base = sys_get_temp_dir().'/theme-view-paths-'.bin2hex(random_bytes(6));
        $fs->makeDirectory($base.'/resources/views/partials', 0755, true);
        file_put_contents($base.'/resources/views/partials/footer.blade.php', 'public footer');

        try {
            $this->assertSame([$base.'/resources/views'], $this->viewPathsFor($base));
            $finder = new FileViewFinder($fs, $this->viewPathsFor($base));
            $this->assertSame($base.'/resources/views/partials/footer.blade.php', $finder->find('partials.footer'));

            $fs->makeDirectory($base.'/resources/views/personal/partials', 0755, true);
            file_put_contents($base.'/resources/views/personal/partials/footer.blade.php', 'personal footer');

            $paths = $this->viewPathsFor($base);
            $this->assertSame([$base.'/resources/views/personal', $base.'/resources/views'], $paths);
            $finder = new FileViewFinder($fs, $paths);
            // A view in the personal layer wins; everything else still comes from resources/views.
            $this->assertSame($base.'/resources/views/personal/partials/footer.blade.php', $finder->find('partials.footer'));
            file_put_contents($base.'/resources/views/partials/header.blade.php', 'public header');
            $this->assertSame($base.'/resources/views/partials/header.blade.php', $finder->find('partials.header'));
        } finally {
            $fs->deleteDirectory($base);
        }

        // Back to the real app: the personal layer comes first only when this checkout has one.
        $expected = is_dir(resource_path('views/personal'))
            ? [resource_path('views/personal'), resource_path('views')]
            : [resource_path('views')];
        $this->assertSame($expected, config('view.paths'));
    }

    public function test_views_contain_no_hard_coded_colors(): void
    {
        $files = (new Filesystem)->allFiles(resource_path('views'));
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/', $file->getContents(), $file->getRelativePathname());
        }
    }

    public function test_stylesheet_defines_light_and_dark_tokens_and_uses_them_only(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertMatchesRegularExpression('/:root\s*\{[^}]*--color-bg:/', $css);
        $this->assertMatchesRegularExpression('/\[data-theme="dark"\]\s*\{[^}]*--color-bg:/', $css);

        // Outside the two token blocks there are no literal colors.
        $rest = preg_replace('/(:root|\[data-theme="dark"\])\s*\{[^}]*\}/', '', $css);
        $rest = preg_replace('#/\*.*?\*/#s', '', $rest);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $rest);

        foreach (['--color-bg', '--color-surface', '--color-text', '--color-text-muted', '--color-border', '--color-accent', '--color-danger', '--color-success', '--color-warning'] as $token) {
            $this->assertMatchesRegularExpression('/:root\s*\{[^}]*'.preg_quote($token, '/').':/', $css, "$token light");
            $this->assertMatchesRegularExpression('/\[data-theme="dark"\]\s*\{[^}]*'.preg_quote($token, '/').':/', $css, "$token dark");
        }
    }
}
