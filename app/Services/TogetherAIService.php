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

        $this->client = Http::withoutVerifying() // <- Recordatorio: Quitar esto en producción
            ->withToken($this->apiKey)
            ->timeout(60)
            ->baseUrl($this->baseUrl);
    }

    /**
     * @param array $schemaTablesObjects Array de objetos App\Models\SchemaTable
     */
    public function generateSql(
        string $userQuestion,
        string $dialect,
        array $schemaTablesObjects, // <-- Ahora son Objetos con metadata filtrada
        ?string $dbPrefix,
        User $user,
        ?string $conversationId
    ): array {

        // 1. Construir el prompt del sistema dinámicamente
        $systemPrompt = $this->buildSystemPrompt($dialect, $schemaTablesObjects, $dbPrefix);

        // 2. Construir el historial de mensajes
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        if ($conversationId) {
            $history = PromptHistory::where('user_id', $user->id)
                ->where('conversation_id', $conversationId)
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get()
                ->reverse();

            foreach ($history as $record) {
                $messages[] = ['role' => 'user', 'content' => $record->question];
                $messages[] = ['role' => 'assistant', 'content' => $record->raw_response];
            }
        }

        // 3. Añadir la pregunta actual del usuario
        $messages[] = ['role' => 'user', 'content' => $userQuestion];

        // Registrar el prompt completo que se enviará a la IA
        Log::debug('Prompt enviado a la IA:', ['messages' => $messages]);

        try {
            $response = $this->client->post('chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.0,
                'max_tokens' => 1024,
                'response_format' => ['type' => 'json_object'],
            ]);

            $response->throw();

            $data = $response->json();
            $aiResponse = $data['choices'][0]['message']['content'] ?? null;
            if (empty($aiResponse)) {
                throw new Exception('La respuesta de la IA estaba vacía.');
            }

            $usage = $data['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];

            return [
                'sql_or_error' => $aiResponse,
                'usage' => $usage,
            ];

        } catch (RequestException $e) {
            Log::error('Error en la API de Together.ai: ' . $e->getMessage(), ['status' => $e->response->status(), 'response' => $e->response->body()]);
            throw new Exception('El servicio de traducción no está disponible: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Error en TogetherAIService: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Construye el contexto del esquema como string.
     * @param array $schemaTablesObjects Array de App\Models\SchemaTable
     */
    public function getSchemaContext(array $schemaTablesObjects): string
    {
        $schemaStringParts = [];

        foreach ($schemaTablesObjects as $table) {
            // 1. Cabecera de la tabla
            $tableString = "TABLE: " . $table->table_name . "\n";
            $tableString .= "CREATE TABLE `" . $table->table_name . "` (\n";

            // 2. Construir cuerpo de la tabla basado en metadata filtrada
            $columnsDefinitions = [];

            if (!empty($table->column_metadata)) {
                foreach ($table->column_metadata as $meta) {
                    if (empty($meta['sql_def'])) continue; // Saltar si la metadata está incompleta

                    $line = "  " . $meta['sql_def'];

                    // Añadir comentarios inteligentes
                    $comments = [];
                    if (!empty($meta['desc'])) {
                        $comments[] = "Significa: '" . $meta['desc'] . "'";
                    }
                    if (!empty($meta['instructions'])) {
                        $comments[] = "REGLA: " . $meta['instructions'];
                    }

                    if (!empty($comments)) {
                        $line .= " -- " . implode(". ", $comments);
                    }

                    $columnsDefinitions[] = $line;
                }
            }

            // 3. Inyectar advertencia de "Contexto Parcial"
            if (empty($columnsDefinitions)) {
                $tableString .= "  -- (ADVERTENCIA: Todas las columnas están ocultas. Pide contexto si es necesario.)\n";
            } else {
                $tableString .= implode(",\n", $columnsDefinitions) . "\n";
                // Añadimos una línea comentada al final para avisar a la IA
                $tableString .= "  -- ... (Otras columnas pueden estar ocultas por optimización. Si necesitas una columna que no ves aquí, responde con el error 'missing_context')\n";
            }

            $tableString .= ");";
            $schemaStringParts[] = $tableString;
        }

        return implode("\n\n", $schemaStringParts);
    }

    /**
     * Construye el "mega-prompt" dinámicamente usando la metadata.
     * @param array $schemaTablesObjects Array de App\Models\SchemaTable
     */
    private function buildSystemPrompt(string $dialect, array $schemaTablesObjects, ?string $dbPrefix): string
    {
        $schemaString = $this->getSchemaContext($schemaTablesObjects);

        // 4. Lógica del prefijo (no cambia)
        $prefixRule = "";
        if (!empty($dbPrefix)) {
            $prefixRule = <<<RULE
4.  **Prefijo de BD (Obligatorio):** TODAS las tablas en la consulta SQL generada DEBEN usar el prefijo '{$dbPrefix}'.
    Ejemplo de formato: `SELECT * FROM {$dbPrefix}.nombre_tabla;`
RULE;
        }

        // 5. El Prompt Definitivo (no cambia)
        return <<<PROMPT
Eres un asistente experto en SQL que convierte lenguaje natural en consultas SQL.
Tu única tarea es generar una consulta SQL precisa y optimizada.

### REGLAS
1.  **Contexto:** Basa tu consulta ÚNICAMENTE en el siguiente esquema de base de datos y dialecto.
2.  **Dialecto:** Genera la consulta usando sintaxis de: {$dialect}.
3.  **Precisión:** No inventes nombres de columnas o tablas que no estén en el esquema proporcionado.
{$prefixRule}
5.  **REGLA DE AMBIGÜEDAD ESTRICTA:** No debes adivinar ni asumir nada.
    * Si la pregunta requiere una columna que NO está presente en la definición de la tabla (porque está oculta), DEBES responder con 'missing_context'.
    * NO uses `SELECT *` a menos que el usuario pida explícitamente "todas las columnas".
    * Si se viola esta regla, DEBES responder con el FORMATO DE AMBIGÜEDAD.
6.  **PRESENTACIÓN:**
    * Usa las descripciones del diccionario como ALIAS en el SQL (ej. `SELECT id AS "ID del Caso"`).

### FORMATO DE RESPUESTA OBLIGATORIO
Tu respuesta debe ser SIEMPRE un único objeto JSON válido.

**FORMATO DE ÉXITO:**
{
  "sql": "SELECT ...",
  "thoughts": "..."
}

**FORMATO DE AMBIGÜEDAD / ERROR:**
{
  "error": "missing_context",
  "thoughts": "...",
  "missing_context": ["La columna 'fecha' no está visible en el esquema proporcionado."]
}

### ESQUEMA DE BASE DE DATOS (PARCIAL)
{$schemaString}
PROMPT;
    }
}
