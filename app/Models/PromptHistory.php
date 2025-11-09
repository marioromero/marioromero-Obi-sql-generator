<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptHistory extends Model
{
    use HasFactory;

    // Usar $guarded es una alternativa a $fillable.
    // Un array vacío significa que "nada está protegido" de la asignación masiva.
    // Lo usamos aquí porque es un modelo de log interno.
    protected $guarded = [];

    /**
     * Obtener el usuario al que pertenece este registro de historial.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
