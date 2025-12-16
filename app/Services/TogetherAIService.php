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

        // Laravel 12 / Http Client
        $this->client = Http::withToken($this->apiKey)
            ->timeout(60)
            ->baseUrl($this->baseUrl)
            ->withHeaders(['Content-Type' => 'application/json']);
        // ->withoutVerifying(); // Solo usar en local si tienes problemas de SSL
    }

    /**
     * @param array $schemaTablesObjects Array de objetos App\Models\SchemaTable
     */
    public function generateSql(
        string $userQuestion,
        string $dialect,
        array $schemaTablesObjects,
        ?string $dbPrefix,
        User $user,
        ?string $conversationId
    ): array {

        // 1. Construir el prompt del sistema optimizado
        $systemPrompt = $this->buildSystemPrompt($dialect, $schemaTablesObjects, $dbPrefix);

        // 2. Construir historial
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        if ($conversationId) {
            $history = PromptHistory::where('user_id', $user->id)
                ->where('conversation_id', $conversationId)
                ->latest()
                ->take(4) // Aumentamos un poco el contexto previo
                ->get()
                ->reverse();

            foreach ($history as $record) {
                // Filtramos mensajes vacíos por seguridad
                if (!empty($record->question) && !empty($record->raw_response)) {
                    $messages[] = ['role' => 'user', 'content' => $record->question];
                    $messages[] = ['role' => 'assistant', 'content' => $record->raw_response];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userQuestion];

        Log::debug('Prompt System Generado:', ['content' => $systemPrompt]);

        try {
            $response = $this->client->post('chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.0, // Cero para máxima determinismo en SQL
                'max_tokens' => 1500,
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
            Log::error('TogetherAI Error: ' . $e->getMessage(), ['body' => $e->response->body()]);
            throw new Exception('Error de comunicación con el servicio de IA: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('TogetherAI Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Construye un contexto rico semánticamente basado en el JSON del frontend.
     */
    public function getSchemaContext(array $schemaTablesObjects): string
    {
        $schemaStringParts = [];

        foreach ($schemaTablesObjects as $table) {
            $tableName = $table->table_name;
            $tableString = "TABLA/VISTA: `{$tableName}`\n";
            $tableString .= "DESCRIPCIÓN: Esta fuente de datos contiene información consolidada.\n";
            $tableString .= "COLUMNAS DISPONIBLES:\n";

            $columnsDefinitions = [];

            if (!empty($table->column_metadata) && is_array($table->column_metadata)) {
                foreach ($table->column_metadata as $meta) {
                    // Validar integridad mínima
                    if (empty($meta['col']) || empty($meta['sql_def'])) continue;

                    $colName = $meta['col'];
                    $sqlType = $meta['sql_def'];

                    // 1. Procesar "desc" para extraer sinónimos
                    // Ejemplo desc: "Estado, Situación, Status"
                    $synonyms = [];
                    $primaryConcept = $colName; // Fallback

                    if (!empty($meta['desc'])) {
                        $descParts = array_map('trim', explode(',', $meta['desc']));
                        $primaryConcept = $descParts[0]; // El primero es el concepto principal
                        if (count($descParts) > 1) {
                            $synonyms = array_slice($descParts, 1);
                        }
                    }

                    // 2. Procesar Origen (Entidad de negocio)
                    $origin = $meta['origin'] ?? 'Sistema';

                    // 3. Procesar Instrucciones (Lógica de transformación)
                    $instruction = $meta['instructions'] ?? '';

                    // Construcción de la línea de definición para la IA
                    // Formato: - col_name (TYPE) | Concepto: "X" | [Entidad: Y] | {Instrucciones}
                    $line = "  - `{$colName}` ({$sqlType})";
                    $line .= " | Concepto Principal: \"{$primaryConcept}\"";

                    if (!empty($synonyms)) {
                        $line .= " | Sinónimos: \"" . implode('", "', $synonyms) . "\"";
                    }

                    $line .= " | Entidad: \"{$origin}\"";

                    if (!empty($instruction)) {
                        // Etiqueta fuerte para forzar lógica SQL
                        $line .= " | [LOGIC_REQUIRED]: \"{$instruction}\"";
                    }

                    $columnsDefinitions[] = $line;
                }
            }

            if (empty($columnsDefinitions)) {
                $tableString .= "  (ADVERTENCIA: Contexto restringido. No hay columnas visibles para esta tabla en esta solicitud.)\n";
            } else {
                $tableString .= implode("\n", $columnsDefinitions) . "\n";
            }

            $schemaStringParts[] = $tableString;
        }

        return implode("\n\n--------------------------------\n\n", $schemaStringParts);
    }

    /**
     * Prompt del sistema diseñado para interpretar las reglas de negocio del JSON.
     */
    private function buildSystemPrompt(string $dialect, array $schemaTablesObjects, ?string $dbPrefix): string
    {
        $schemaString = $this->getSchemaContext($schemaTablesObjects);

        $prefixRule = "";
        if (!empty($dbPrefix)) {
            $prefixRule = "   - Prefijo de BD OBLIGATORIO: `{$dbPrefix}`. (Ej: `FROM {$dbPrefix}.tabla`).";
        }

        return <<<PROMPT
Eres un Arquitecto de Datos y Experto en SQL ({$dialect}).
Tu objetivo es traducir lenguaje natural a una consulta SQL precisa, respetando reglas de negocio estrictas definidas en los metadatos.

### TUS INSTRUCCIONES PRIORITARIAS

1.  **INTERPRETACIÓN DE METADATOS (CRÍTICO):**
    Analiza la sección "COLUMNAS DISPONIBLES" cuidadosamente.
    * **Concepto Principal y Sinónimos:** Usa estos términos para entender a qué columna se refiere el usuario (Ej: si piden "Estado", busca en "Concepto Principal" o "Sinónimos").
    * **[LOGIC_REQUIRED]:** Si una columna tiene esta etiqueta, NO la selecciones directamente. DEBES escribir código SQL para transformar el dato según la instrucción.
        * *Ejemplo:* Si `state` dice "[LOGIC_REQUIRED]: valor después del último slash", tu SQL debe ser `SUBSTRING_INDEX(state, '/', -1)` (o equivalente en {$dialect}), y NO simplemente `state`.

2.  **SINTAXIS Y REGLAS SQL:**
    * Dialecto: **{$dialect}**.
    * Usa siempre alias de tabla (ej: `t1.columna`).
    * Si el usuario pide nombres de columnas específicos en el SELECT (como "Muéstrame el Estado"), usa `AS 'Nombre Amigable'` basado en el "Concepto Principal".
{$prefixRule}

3.  **SEGURIDAD Y ALCANCE:**
    * SOLO usa las tablas y columnas listadas en el contexto.
    * Si el usuario pide un dato que NO está en las "COLUMNAS DISPONIBLES" (aunque sepas que existe en una tabla real), responde con un error de `missing_context`.
    * Nunca inventes columnas ni uses `SELECT *`.

### FORMATO DE RESPUESTA (JSON)

**Caso Éxito:**
{
  "sql": "SELECT ...",
  "thoughts": "Breve explicación de por qué elegiste esas columnas y qué transformaciones lógicas aplicaste."
}

**Caso Falta Información (Ambigüedad):**
{
  "error": "missing_context",
  "missing_context": ["Explica qué columna o dato falta para responder la pregunta."]
}

### ESQUEMA DE DATOS DEFINIDO
{$schemaString}
PROMPT;
    }
}
