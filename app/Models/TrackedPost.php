<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\StoredMessage;
use App\Support\UserMessage;

class TrackedPost extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRACKING = 'tracking';
    public const STATUS_DONE = 'done';
    public const STATUS_GONE = 'gone';
    public const STATUS_FAILED = 'failed';

    public const PUBLISHED_FROM_PAGE = 'page';
    public const PUBLISHED_FROM_SHORTCODE = 'shortcode_decode';

    protected $fillable = [
        'url', 'shortcode', 'username', 'source', 'pk', 'caption',
        'published_at', 'published_at_source', 'track_until', 'next_poll_at',
        'status', 'added_after_window', 'counts_hidden',
        'final_views', 'final_likes', 'final_replies', 'final_reposts', 'final_quotes', 'final_shares',
        'final_captured_at', 'last_captured_at', 'last_error', 'consecutive_failures',
    ];

    protected $attributes = [
        'source' => 'public_page',
        'status' => self::STATUS_PENDING,
        'added_after_window' => false,
        'counts_hidden' => false,
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'track_until' => 'immutable_datetime',
            'next_poll_at' => 'immutable_datetime',
            'final_captured_at' => 'immutable_datetime',
            'last_captured_at' => 'immutable_datetime',
            'added_after_window' => 'boolean',
            'counts_hidden' => 'boolean',
            'final_views' => 'integer',
            'final_likes' => 'integer',
            'final_replies' => 'integer',
            'final_reposts' => 'integer',
            'final_quotes' => 'integer',
            'final_shares' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    /** Users who have this post in their tracking list. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PostSnapshot::class);
    }

    public const METRICS = ['views', 'likes', 'replies', 'reposts', 'quotes', 'shares'];

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_TRACKING, self::STATUS_DONE, self::STATUS_GONE, self::STATUS_FAILED];

    /** Display name of a metric in the current language (lang/{zh_TW,en}/posts.php, "metrics"). */
    public static function metricLabel(string $metric): string
    {
        return UserMessage::key('posts.metrics.'.$metric);
    }

    /** Display name of a status in the current language; an unknown status is shown as is. */
    public static function statusLabelFor(string $status): string
    {
        $key = 'posts.status.'.$status;
        $text = UserMessage::key($key);

        return $text === $key ? $status : $text;
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PostSnapshot::class)->ofMany(['captured_at' => 'max', 'id' => 'max']);
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor((string) $this->status);
    }

    /** last_error as text in the current language (stored as a code, contract L4; old rows as is). */
    public function lastErrorText(): ?string
    {
        return StoredMessage::render($this->last_error);
    }

    /**
     * The username only comes from the submitted URL until a page read confirms the author
     * (published_at_source becomes "page"); anyone can type any @name before /post/CODE.
     */
    public function isUsernameVerified(): bool
    {
        return $this->published_at_source === self::PUBLISHED_FROM_PAGE;
    }

    public function isPublishedAtEstimated(): bool
    {
        return $this->published_at_source === self::PUBLISHED_FROM_SHORTCODE;
    }

    /** Hours elapsed since publish (floored), or null when publish time is unknown. */
    public function hoursSincePublish(?CarbonInterface $now = null): ?int
    {
        if ($this->published_at === null) {
            return null;
        }
        $now ??= CarbonImmutable::now('UTC');

        return (int) floor($this->published_at->diffInMinutes($now, false) / 60);
    }

    /** Metrics the author can hide on Threads: only these show "hidden" when counts_hidden is set. */
    public const HIDEABLE_METRICS = ['likes', 'views'];

    /** Translation keys (lang/…/posts.php) for the label of final figures, per status. */
    private const FINAL_LABELS = [
        self::STATUS_DONE => 'posts.figures.final_done',
        self::STATUS_GONE => 'posts.figures.final_gone',
        self::STATUS_FAILED => 'posts.figures.final_failed',
    ];

    /**
     * Metrics to show in summaries: final_* once done (or gone / failed with a last reading),
     * otherwise the latest snapshot.
     *
     * @return array{label: string, values: array<string, ?int>, captured_at: ?CarbonImmutable}
     */
    public function displayMetrics(): array
    {
        if ($this->status === self::STATUS_DONE || ($this->hasFinal() && isset(self::FINAL_LABELS[$this->status]))) {
            $values = [];
            foreach (self::METRICS as $m) {
                $values[$m] = $this->{'final_'.$m};
            }

            $label = __(self::FINAL_LABELS[$this->status], ['hours' => (int) config('threads.track_hours', 72)]);

            return ['label' => $label, 'values' => $values, 'captured_at' => $this->final_captured_at];
        }

        $snap = $this->latestSnapshot;
        $values = [];
        foreach (self::METRICS as $m) {
            $values[$m] = $snap?->{$m};
        }

        return ['label' => __('posts.figures.current'), 'values' => $values, 'captured_at' => $snap?->captured_at];
    }

    private function hasFinal(): bool
    {
        if ($this->final_captured_at !== null) {
            return true;
        }
        foreach (self::METRICS as $m) {
            if ($this->{'final_'.$m} !== null) {
                return true;
            }
        }

        return false;
    }

    /** True when the final numbers were captured more than 10 minutes away from track_until. */
    public function finalCapturedOffWindow(): bool
    {
        if ($this->final_captured_at === null || $this->track_until === null) {
            return false;
        }

        return abs($this->final_captured_at->diffInSeconds($this->track_until, false)) > 600;
    }

    /**
     * Format a metric for display (contract C4): null is never shown as 0 or blank.
     * "Hidden by the author" only applies to likes and views; any other null is "not available".
     */
    public function formatMetric(?int $value, string $metric): string
    {
        if ($value !== null) {
            return number_format($value);
        }

        return $this->counts_hidden && in_array($metric, self::HIDEABLE_METRICS, true) ? __('posts.metric_hidden') : __('posts.metric_na');
    }

    /**
     * Format a stored (UTC) time in the display timezone (contract C3). Without a format: the hour
     * format of the current language (lang/…/ui.php "formats.hour"; Chinese adds the hour sign, English uses Y-m-d H:00).
     */
    public static function localTime(?CarbonInterface $time, ?string $format = null): string
    {
        if ($time === null) {
            return '—';
        }
        $format ??= (string) __('ui.formats.hour');

        return CarbonImmutable::instance($time)->setTimezone(config('threads.display_timezone'))->format($format);
    }
}
