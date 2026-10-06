<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * is_admin / is_active are deliberately NOT here: privilege fields are only set with
     * forceFill() or explicit property assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_admin' => false,
        'is_active' => true,
    ];

    /** Posts in this user's own tracking list (fetch data is shared per tracked_post). */
    public function trackedPosts(): BelongsToMany
    {
        return $this->belongsToMany(TrackedPost::class)
            ->using(TrackedPostUser::class)
            ->withPivot('id', 'note', 'group_id')
            ->withTimestamps();
    }

    /** This user's own tags (never shared with other accounts). */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** This user's own groups, in display order (never shared with other accounts). */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class)->orderBy('sort_order')->orderBy('id');
    }
}
