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

            // 1. Recuperamos la cabecera informativa
            $tableString = "TABLA/VISTA: `{$tableName}`\n";
            $tableString .= "DESCRIPCIÓN: Vista lógica de datos.\n";
            $tableString .= "COLUMNAS VISIBLES (Solo estas están permitidas):\n";

            $columnsDefinitions = [];

            if (!empty($table->column_metadata) && is_array($table->column_metadata)) {
                foreach ($table->column_metadata as $meta) {
                    if (empty($meta['col']) || empty($meta['sql_def'])) continue;

                    $colName = $meta['col'];
                    $sqlType = $meta['sql_def'];

                    // 2. Recuperamos la lógica de Sinónimos y Concepto Principal
                    $synonyms = [];
                    $primaryConcept = $colName;

                    if (!empty($meta['desc'])) {
                        // Separar por comas para extraer sinónimos
                        $descParts = array_map('trim', explode(',', $meta['desc']));
                        $primaryConcept = $descParts[0]; // El primero es el concepto canónico
                        if (count($descParts) > 1) {
                            $synonyms = array_slice($descParts, 1); // El resto son alias de búsqueda
                        }
                    }

                    $origin = $meta['origin'] ?? 'Sistema';
                    $instruction = $meta['instructions'] ?? '';

                    // 3. Construcción de la línea rica en contexto
                    $line = "  - `{$colName}` ({$sqlType})";
                    $line .= " | Concepto: \"{$primaryConcept}\"";

                    // Insertamos de nuevo los sinónimos
                    if (!empty($synonyms)) {
                        $line .= " | Sinónimos: \"" . implode('", "', $synonyms) . "\"";
                    }

                    $line .= " | Origen: \"{$origin}\"";

                    // 4. EL ÉNFASIS CRÍTICO (La parte nueva que asegura la lectura de la instrucción)
                    // Usamos mayúsculas y asteriscos para romper el patrón visual y forzar atención
                    if (!empty($instruction)) {
                        $line .= " | *** [TRANSFORMACIÓN_OBLIGATORIA]: \"{$instruction}\" ***";
                    }

                    $columnsDefinitions[] = $line;
                }
            }

            // 5. Recuperamos el pie de página de gobernanza
            if (empty($columnsDefinitions)) {
                $tableString .= "  (ADVERTENCIA CRÍTICA: No hay columnas visibles. El usuario debe seleccionarlas explícitamente.)\n";
            } else {
                $tableString .= implode("\n", $columnsDefinitions) . "\n";
                $tableString .= "  -- GOBERNANZA: El esquema real tiene más columnas (OCULTAS). Solo puedes usar las listadas arriba. PROHIBIDO SELECT *.\n";
            }

            $schemaStringParts[] = $tableString;
        }

        return implode("\n\n--------------------------------\n\n", $schemaStringParts);
    }

    private function buildSystemPrompt(string $dialect, array $schemaTablesObjects, ?string $dbPrefix): string
    {
        // Obtenemos el esquema con la nueva etiqueta visual fuerte
        $schemaString = $this->getSchemaContext($schemaTablesObjects);

        $prefixRule = "";
        if (!empty($dbPrefix)) {
            $prefixRule = "   - Prefijo de BD OBLIGATORIO: `{$dbPrefix}`. (Ej: `FROM {$dbPrefix}.tabla`).";
        }

        return <<<PROMPT
Eres un Arquitecto de Datos y Experto en SQL ({$dialect}).
Tu objetivo es traducir lenguaje natural a una consulta SQL precisa, respetando reglas de negocio estrictas.

### REGLA DE ORO: TRANSFORMACIONES E INSTRUCCIONES (PRIORIDAD MÁXIMA)
Antes de escribir el SQL, verifica si alguna columna tiene la etiqueta `[TRANSFORMACIÓN_OBLIGATORIA]`.
1.  **Prioridad Absoluta:** Si existe una instrucción, esta anula la interpretación estándar del dato. Debes aplicar la lógica solicitada obligatoriamente en el `SELECT` y en el `WHERE`.
2.  **Manejo de Backslashes (Rutas/FQCN):**
    * Si la instrucción pide "extraer valor tras el último backslash" (ej: `App\Entity\Estado` -> `Estado`), ten cuidado con el carácter de escape.
    * Para MySQL/MariaDB, usa: `SUBSTRING_INDEX(columna, '\\\\', -1)`. (Nota: Se requieren cuatro backslashes en el string JSON para representar uno literal en la query).
    * Nunca devuelvas la ruta completa si se pide solo el nombre final.

### INSTRUCCIONES DE METADATOS Y ALIAS
1.  **Concepto Principal (Aliases):**
    * Analiza la sección "COLUMNAS VISIBLES".
    * **DEBES** usar el valor de "Concepto" para generar un alias amigable en el SELECT.
    * *Ejemplo:* Si la columna es `code` y el concepto es "Código Caso", el SQL debe ser: `SELECT t1.code AS "Código Caso"`.

2.  **Inferencia de Tipos:**
    * Si piden filtrar por un valor (ej: "Estado Ingreso") y la columna no es ENUM, asume que es texto.
    * Usa `LIKE` o igualdad directa aplicando la transformación de la Regla de Oro si corresponde.

### SINTAXIS SQL
1.  **Dialecto:** **{$dialect}**.
2.  **Estructura:**
    * Usa alias de tabla explícitos (ej: `t1.columna`).
    * **Fechas:** Usa funciones dinámicas (`CURRENT_DATE`, `NOW()`) para conceptos relativos como "este mes" o "hoy".
    {$prefixRule}

### FORMATO DE SALIDA (ESTRICTO)
1.  **ORDEN:** Lista las columnas en el `SELECT` EXACTAMENTE en el orden visual de "COLUMNAS VISIBLES".
2.  **ALIASES (Obligatorio):** TODAS las columnas del SELECT deben tener `AS "Nombre"`. No devuelvas nombres de columna crudos.
3.  **PROHIBIDO `SELECT *`**.

### SEGURIDAD
* Solo usa columnas listadas en "COLUMNAS VISIBLES".
* Si la pregunta requiere datos que no están en el esquema, responde con el error `missing_context`.

### FORMATO DE RESPUESTA (JSON)

**Caso Éxito:**
{
  "sql": "SELECT SUBSTRING_INDEX(t1.state, '\\\\', -1) AS 'Estado', t1.code AS 'Código' FROM ...",
  "thoughts": "Detecté instrucción de limpieza en 'state'. Apliqué SUBSTRING_INDEX y asigné alias."
}

**Caso Error/Ambigüedad:**
{
  "error": "missing_context",
  "missing_context": ["Explica qué falta en el esquema para responder."],
  "thoughts": "El usuario pide 'fecha de nacimiento' pero esa columna no está visible."
}

### ESQUEMA DE DATOS DEFINIDO (VISTA PARCIAL)
{$schemaString}
PROMPT;
    }


    public function generateChartSql(
        string  $userQuestion,
        string  $dialect,
        array   $schemaTablesObjects,
        ?string $dbPrefix,
        User    $user,
        ?string $conversationId,
        ?array  $chartConfig = null
    ): array
    {
        // Usamos un System Prompt especializado para visualización
        $systemPrompt = $this->buildChartSystemPrompt($dialect, $schemaTablesObjects, $dbPrefix, $chartConfig);

        // Construcción de mensajes
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        // Historial (opcional, útil para contexto)
        if ($conversationId) {
            $history = PromptHistory::where('user_id', $user->id)
                ->where('conversation_id', $conversationId)
                ->latest()
                ->take(3)
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
                'temperature' => 0.0, // Cero alucinación
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
                'chart_response' => $aiResponse, // Esto trae SQL + Chart Config
                'usage' => $data['usage'] ?? [],
            ];

        } catch (RequestException $e) {
            Log::error('TogetherAI Chart Error: ' . $e->getMessage());
            throw new Exception('Error generando gráfico: ' . $e->getMessage());
        }
    }

    /**
     * Prompt Especializado para Gráficos (ApexCharts Friendly)
     */
    /**
     * Prompt Especializado para Gráficos (ApexCharts Friendly)
     */
    private function buildChartSystemPrompt(string $dialect, array $schemaTablesObjects, ?string $dbPrefix, ?array $chartConfig = null): string
    {
        // Reutilizamos el contexto rico (que ahora trae la etiqueta [TRANSFORMACIÓN_OBLIGATORIA])
        $schemaString = $this->getSchemaContext($schemaTablesObjects);

        $prefixRule = "";
        if (!empty($dbPrefix)) {
            $prefixRule = "   - Prefijo de BD OBLIGATORIO: `{$dbPrefix}`. (Ej: `FROM {$dbPrefix}.tabla`).";
        }

        // Procesar configuración de ejes si está disponible
        $axisConfigInstructions = "";
        if ($chartConfig) {
            $axisConfigInstructions .= "\n### CONFIGURACIÓN ESPECÍFICA DE EJES (PROPORCIONADA POR EL USUARIO)\n";

            if (!empty($chartConfig['x_axis'])) {
                $xAxis = $chartConfig['x_axis'];
                $axisConfigInstructions .= "**EJE X:**\n";
                if (!empty($xAxis['label'])) {
                    $axisConfigInstructions .= "  - Etiqueta: \"{$xAxis['label']}\"\n";
                }
                if (!empty($xAxis['format'])) {
                    $axisConfigInstructions .= "  - Formato: \"{$xAxis['format']}\"\n";
                    $axisConfigInstructions .= "  - **OBLIGATORIO:** Aplica este formato en el SQL para que las etiquetas del eje X sean legibles.\n";
                }
                $axisConfigInstructions .= "\n";
            }

            if (!empty($chartConfig['y_axis'])) {
                $yAxis = $chartConfig['y_axis'];
                $axisConfigInstructions .= "**EJE Y:**\n";
                if (!empty($yAxis['label'])) {
                    $axisConfigInstructions .= "  - Etiqueta: \"{$yAxis['label']}\"\n";
                }
                if (!empty($yAxis['format'])) {
                    $axisConfigInstructions .= "  - Formato: \"{$yAxis['format']}\"\n";
                }
                $axisConfigInstructions .= "\n";
            }

            if (!empty($chartConfig['title'])) {
                $axisConfigInstructions .= "**TÍTULO DEL GRÁFICO:** \"{$chartConfig['title']}\"\n\n";
            }

            if (!empty($chartConfig['type'])) {
                $axisConfigInstructions .= "**TIPO DE GRÁFICO FORZADO:** \"{$chartConfig['type']}\"\n\n";
            }
        }

        return <<<PROMPT
Eres un Experto en Visualización de Datos y SQL ({$dialect}).
Tu objetivo es generar una consulta SQL para alimentar un gráfico (ApexCharts) basado en la solicitud del usuario.

### REGLA DE ORO: TRANSFORMACIONES EN AGREGACIONES (PRIORIDAD MÁXIMA)
Antes de generar el SQL, verifica si la columna a graficar tiene la etiqueta `[TRANSFORMACIÓN_OBLIGATORIA]`.

1.  **LIMPIEZA EN EL GROUP BY (CRÍTICO):**
    * Para que el gráfico sea legible, **DEBES aplicar la transformación tanto en el `SELECT` como en el `GROUP BY`**.
    * Si la instrucción pide "extraer después del último backslash" (FQCN), usa: `SUBSTRING_INDEX(columna, '\\\\', -1)`.
    * *Ejemplo Incorrecto:* `SELECT ... GROUP BY state` (Esto crea etiquetas sucias como 'App\Entity\Closed').
    * *Ejemplo Correcto:* `SELECT ... GROUP BY SUBSTRING_INDEX(state, '\\\\', -1)` (Esto crea etiquetas limpias como 'Closed').

2.  **ALIASES:**
    * Usa el "Concepto" listado en el esquema para nombrar las series si es posible, pero mantén claves simples (`eje_x`, `eje_y`) para la configuración técnica.

3.  **FORMATO DE ETIQUETAS (CRÍTICO PARA LEGIBILIDAD):**
    * **FECHAS:** Si especifican un formato de fecha, **OBLIGATORIO** aplicarlo tanto en SELECT como en GROUP BY.
    * **EJEMPLOS DE FORMATOS:**
        - "meses_del_año" → `DATE_FORMAT(date_col, '%M')` → "January", "February", etc.
        - "meses_abreviados" → `DATE_FORMAT(date_col, '%b')` → "Jan", "Feb", etc.
        - "nombres_meses" → Usa CASE o MONTH() + array de nombres.
        - "trimestres" → `CONCAT('Q', QUARTER(date_col), ' ', YEAR(date_col))` → "Q1 2025".
    * **NUNCA** devuelvas códigos crudos como "2025-01", "2025-02" si el usuario pidió nombres de meses.

### TUS REGLAS DE ORO (VISUALIZACIÓN):

1.  **AGREGACIÓN OBLIGATORIA:**
    * Los gráficos resumen datos. Tu SQL **SIEMPRE** debe tener `GROUP BY` (a menos que pidan un KPI único).
    * Usa `COUNT(*)`, `SUM(columna)`, `AVG(columna)` para el eje Y (Series).
    * Usa columnas de categoría (Estado, Fecha, Usuario) para el eje X (Categorías).

2.  **FECHAS EN EJE X:**
    * Si piden evolución temporal ("por mes", "por día"), formatea la fecha en el SQL para que sea legible y agrupe correctamente.
    * Ejemplo MySQL: `DATE_FORMAT(date_col, '%Y-%m')` para meses.

3.  **TIPOS DE GRÁFICO:** Sugiere el mejor tipo en `chart_config`:
    * Comparar categorías (ej: estados) -> `"bar"` o `"pie"`.
    * Evolución tiempo (ej: meses) -> `"line"` o `"area"`.
    {$prefixRule}

### FORMATO DE RESPUESTA (JSON)
Debes devolver un JSON con la estructura exacta para que el Frontend (ApexCharts) sepa cómo pintar el gráfico.

**Ejemplo de Respuesta:**
{
  "sql": "SELECT SUBSTRING_INDEX(state, '\\\\', -1) AS eje_x, COUNT(*) AS eje_y FROM t1 GROUP BY SUBSTRING_INDEX(state, '\\\\', -1)",
  "chart_config": {
    "type": "bar",
    "title": "Cantidad de Casos por Estado",
    "xaxis_column": "eje_x",  // Nombre exacto de la columna en el SQL que va al Eje X
    "series_column": "eje_y", // Nombre exacto de la columna numérica
    "series_name": "Total Casos" // Etiqueta para la leyenda
  },
  "thoughts": "Detecté instrucción de limpieza en 'state'. Apliqué SUBSTRING_INDEX en el GROUP BY para tener categorías limpias."
}

### MANEJO DE ERRORES
Si la pregunta no se puede graficar (ej: "Muestrame el detalle del caso 1" o "Lista de usuarios"), responde:
{ "error": "not_chartable", "message": "Esta consulta pide un detalle, no un gráfico." }

### ESQUEMA DE DATOS
{$schemaString}{$axisConfigInstructions}
PROMPT;
    }
}
