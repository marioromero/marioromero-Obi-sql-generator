<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchemaTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'schema_id',
        'table_name',
        'column_metadata',
    ];

    protected $casts = [
        'column_metadata' => 'array', // <-- AÑADIR (Laravel lo convierte auto. en Array)
    ];

    public function schema(): BelongsTo
    {
        return $this->belongsTo(Schema::class);
    }
}
