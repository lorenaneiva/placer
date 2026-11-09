<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'identifier', 'description', 'hourly_price', 'capacity', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'hourly_price' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function scheduleBlocks(): HasMany
    {
        return $this->hasMany(ScheduleBlock::class);
    }

    public function getTitleAttribute(): string
    {
        return $this->category->name.' '.$this->identifier;
    }
}
