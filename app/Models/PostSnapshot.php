<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostSnapshot extends Model
{
    protected $fillable = [
        'tracked_post_id', 'captured_at', 'hours_since_publish',
        'views', 'likes', 'replies', 'reposts', 'quotes', 'shares',
        'source', 'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'hours_since_publish' => 'decimal:2',
            'views' => 'integer',
            'likes' => 'integer',
            'replies' => 'integer',
            'reposts' => 'integer',
            'quotes' => 'integer',
            'shares' => 'integer',
        ];
    }

    public function trackedPost(): BelongsTo
    {
        return $this->belongsTo(TrackedPost::class);
    }
}
