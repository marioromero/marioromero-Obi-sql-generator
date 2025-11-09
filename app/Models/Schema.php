<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schema extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'dialect',
        'database_name_prefix',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schemaTables(): HasMany
    {
        return $this->hasMany(SchemaTable::class);
    }
}
