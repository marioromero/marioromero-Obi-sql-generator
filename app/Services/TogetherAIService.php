<?php

namespace App\Services;

use App\Models\PromptHistory;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TogetherAIService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected \Illuminate\Http\Client\PendingRequest $client;

    public function __construct()
    {
        $this->apiKey = config('services.together.api_key');
        $this->baseUrl = config('services.together.api_base_url');
        $this->model = config('services.together.model');

        if (empty($this->apiKey)) {
            throw new Exception('La API Key de Together.ai no está configurada.');
        }

        $this->client = Http::withToken($this->apiKey)
            ->timeout(60)
            ->baseUrl($this->baseUrl)
            ->withHeaders(['Content-Type' => 'application/json']);
    }

    public function generateSql(string $userQuestion, string $dialect, array $schemaTablesObjects, ?string $dbPrefix, User $user, ?string $conversationId): array
    {
        $systemPrompt = $this->buildSystemPrompt($dialect, $schemaTablesObjects, $dbPrefix);

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        if ($conversationId) {
            $history = PromptHistory::where('user_id', $user->id)
                ->where('conversation_id', $conversationId)
                ->latest()
                ->take(4)
                ->get()
                ->reverse();

            foreach ($history as $record) {
                if (!empty($record->question) && !empty($record->raw_response)) {
                    $messages[] = ['role' => 'user', 'content' => $record->question];
                    $messages[] = ['role' => 'assistant', 'content' => $record->raw_response];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userQuestion];

        try {
            $response = $this->client->post('chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.0,
                'max_tokens' => 1500,
                'response_format' => ['type' => 'json_object'],
            ]);

            $response->throw();
            $data = $response->json();
            $aiResponse = $data['choices'][0]['message']['content'] ?? null;

            if (empty($aiResponse)) {
                throw new Exception('La respuesta de la IA estaba vacía.');
            }

            return [
                'sql_or_error' => $aiResponse,
                'usage' => $data['usage'] ?? [],
            ];

        } catch (RequestException $e) {
            Log::error('TogetherAI Error: ' . $e->getMessage());
            throw new Exception('Error de comunicación con el servicio de IA: ' . $e->getMessage());
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getSchemaContext(array $schemaTablesObjects): string
    {
        $schemaStringParts = [];

        foreach ($schemaTablesObjects as $table) {
            $tableName = $table->table_name;
            $tableString = "TABLA/VISTA: `{$tableName}`\n";
            $tableString .= "DESCRIPCIÓN: Vista lógica de datos.\n";
            $tableString .= "COLUMNAS VISIBLES (Solo estas están permitidas):\n";

            $columnsDefinitions = [];

            if (!empty($table->column_metadata) && is_array($table->column_metadata)) {
                foreach ($table->column_metadata as $meta) {
                    if (empty($meta['col']) || empty($meta['sql_def'])) continue;

                    $colName = $meta['col'];
                    $sqlType = $meta['sql_def'];

                    // Extraer Concepto Principal para usarlo como Alias
                    $synonyms = [];
                    $primaryConcept = $colName;

                    if (!empty($meta['desc'])) {
                        $descParts = array_map('trim', explode(',', $meta['desc']));
                        $primaryConcept = $descParts[0];
                        if (count($descParts) > 1) {
                            $synonyms = array_slice($descParts, 1);
                        }
                    }

                    $origin = $meta['origin'] ?? 'Sistema';
                    $instruction = $meta['instructions'] ?? '';

                    $line = "  - `{$colName}` ({$sqlType})";
                    $line .= " | Concepto: \"{$primaryConcept}\""; // <-- ESTE ES CLAVE PARA EL ALIAS

                    if (!empty($synonyms)) {
                        $line .= " | Sinónimos: \"" . implode('", "', $synonyms) . "\"";
                    }

                    $line .= " | Entidad: \"{$origin}\"";

                    if (!empty($instruction)) {
                        $line .= " | [LOGIC_REQUIRED]: \"{$instruction}\"";
                    }

                    $columnsDefinitions[] = $line;
                }
            }

            if (empty($columnsDefinitions)) {
                $tableString .= "  (ADVERTENCIA CRÍTICA: No hay columnas visibles. El usuario debe seleccionarlas explícitamente.)\n";
            } else {
                $tableString .= implode("\n", $columnsDefinitions) . "\n";
                $tableString .= "  -- NOTA: El esquema real tiene más columnas (OCULTAS). Solo puedes usar las listadas arriba. PROHIBIDO SELECT *.\n";
            }

            $schemaStringParts[] = $tableString;
        }

        return implode("\n\n--------------------------------\n\n", $schemaStringParts);
    }

    private function buildSystemPrompt(string $dialect, array $schemaTablesObjects, ?string $dbPrefix): string
    {
        $schemaString = $this->getSchemaContext($schemaTablesObjects);

        $prefixRule = "";
        if (!empty($dbPrefix)) {
            $prefixRule = "   - Prefijo de BD OBLIGATORIO: `{$dbPrefix}`. (Ej: `FROM {$dbPrefix}.tabla`).";
        }

        return <<<PROMPT
Eres un Arquitecto de Datos y Experto en SQL ({$dialect}).
Tu objetivo es traducir lenguaje natural a una consulta SQL precisa, respetando reglas de negocio estrictas.

### TUS INSTRUCCIONES PRIORITARIAS

1.  **INTERPRETACIÓN DE METADATOS:**
    Analiza la sección "COLUMNAS VISIBLES".
    a) **Concepto Principal (Aliases):** DEBES usar el valor de "Concepto" para generar un alias amigable en el SELECT.
       * *Ejemplo:* Si la columna es `code` y el concepto es "Código Caso", el SQL debe ser: `SELECT t1.code AS "Código Caso"`.
    b) **[LOGIC_REQUIRED] y FILTRADO:** Las instrucciones lógicas son OBLIGATORIAS para SELECT y WHERE.
       * *Ejemplo:* Si `state` requiere extraer la última palabra y piden "Ingreso", GENERA: `WHERE SUBSTRING_INDEX(state, '/', -1) = 'Ingreso'`.
    c) **Inferencia:** Si piden filtrar por un valor (ej: "Estado Ingreso") y no es ENUM, asume que es texto y usa LIKE sobre la columna conceptualmente coincidente.

2.  **SINTAXIS SQL:**
    * Dialecto: **{$dialect}**.
    * Usa alias de tabla explícitos (ej: `t1.columna`).
    * **Fechas:** Usa funciones dinámicas (`CURRENT_DATE`, `NOW()`) para conceptos como "este mes".
{$prefixRule}

3.  **FORMATO DE SALIDA (ESTRICTO):**
    * **ORDEN:** Lista las columnas en el `SELECT` EXACTAMENTE en el orden visual de "COLUMNAS VISIBLES".
    * **ALIASES (Obligatorio):** TODAS las columnas del SELECT deben tener `AS "Nombre"`. No devuelvas nombres de columna crudos.
    * **PROHIBIDO `SELECT *`**.

4.  **SEGURIDAD:**
    * Solo usa columnas visibles. Si falta información, responde `missing_context`.

### FORMATO DE RESPUESTA (JSON)

**Caso Éxito:**
{
  "sql": "SELECT t1.columna1 AS 'Alias1', t1.columna2 AS 'Alias2' ...",
  "thoughts": "Explica la lógica usada."
}

**Caso Error/Ambigüedad:**
{
  "error": "missing_context",
  "missing_context": ["Explica qué falta."],
  "thoughts": "..."
}

### ESQUEMA DE DATOS DEFINIDO (VISTA PARCIAL)
{$schemaString}
PROMPT;
    }
}
