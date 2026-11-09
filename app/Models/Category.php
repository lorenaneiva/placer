<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'max_advance_days'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'max_advance_days' => 'integer'];
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function reservations(): HasManyThrough
    {
        return $this->hasManyThrough(Reservation::class, Space::class);
    }
}
