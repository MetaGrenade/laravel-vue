<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(SupportResponseTemplate::class, 'support_response_template_support_team')
            ->withTimestamps();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'support_team_user')
            ->withTimestamps();
    }
}
