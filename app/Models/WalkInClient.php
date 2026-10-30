<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalkInClient extends Model
{
    use HasFactory;

    protected $fillable = [
        'photographer_id',
        'name',
        'phone',
        'email',
        'location',
        'source',
        'status',
    ];

public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'walk_in_client_id');
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }
}