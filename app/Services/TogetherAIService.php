<?php

namespace App\Services;

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

    /**
     * Inicializa el servicio con las credenciales de la API.
     */
    public function __construct()
    {
        $this->apiKey = config('services.together.api_key');
        $this->baseUrl = config('services.together.api_base_url');
        $this->model = config('services.together.model');

        if (empty($this->apiKey)) {
            throw new Exception('La API Key de Together.ai no está configurada.');
        }

        $this->client = Http::withoutVerifying() // <-- ¡AÑADE ESTA LÍNEA!
        ->withToken($this->apiKey)
            ->timeout(60)
            ->baseUrl($this->baseUrl);
    }

    /**
     * Genera una consulta SQL basada en una pregunta y un contexto de esquema.
     *
     * @param string $userQuestion La pregunta en lenguaje natural.
     * @param string $dialect El dialecto SQL ('mysql', 'postgres', etc.).
     * @param array $schemaTables Un array de strings de "CREATE TABLE...".
     * @return string El string SQL crudo o un JSON de error de la IA.
     * @throws \Exception Si la API de Together.ai falla.
     */
    public function generateSql(string $userQuestion, string $dialect, array $schemaTables, ?string $dbPrefix): array
    {
        $systemPrompt = $this->buildSystemPrompt($dialect, $schemaTables, $dbPrefix);

        try {
            $response = $this->client->post('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userQuestion],
                ],
                'temperature' => 0.0,
                'max_tokens' => 1024,
                'response_format' => ['type' => 'json_object'],
            ]);

            $response->throw();

            // --- LÓGICA DE EXTRACCIÓN MODIFICADA ---
            $data = $response->json(); // Obtener la respuesta JSON completa

            // Extraer el contenido
            $aiResponse = $data['choices'][0]['message']['content'] ?? null;
            if (empty($aiResponse)) {
                throw new Exception('La respuesta de la IA estaba vacía.');
            }

            // Extraer el uso de tokens
            $usage = $data['usage'] ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ];

            return [
                'sql_or_error' => $aiResponse, // Esto ahora es un JSON, no un string
                'usage' => $usage,
            ];
            // --- FIN DE LA MODIFICACIÓN ---

        } catch (RequestException $e) {
            Log::error('Error en la API de Together.ai: ' . $e->getMessage(), [
                'status' => $e->response->status(),
                'response' => $e->response->body(),
            ]);
            throw new Exception('El servicio de traducción no está disponible: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Error en TogetherAIService: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Construye el "mega-prompt" del sistema (Punto 2 del resumen).
     */
private function buildSystemPrompt(string $dialect, array $schemaTables, ?string $dbPrefix): string
    {
        $schemaString = implode("\n\n", $schemaTables);

        // --- ¡NUEVA LÓGICA DEL PROMPT! ---
        $prefixRule = ""; // Por defecto, no hay regla de prefijo
        if (!empty($dbPrefix)) {
            // Si el usuario proporcionó un prefijo, creamos la Regla #4
            $prefixRule = <<<RULE
4.  **Prefijo de BD (Obligatorio):** TODAS las tablas en la consulta SQL generada DEBEN usar el prefijo '{$dbPrefix}'.
    Ejemplo de formato: `SELECT * FROM {$dbPrefix}.nombre_tabla;`
RULE;
        }

        return <<<PROMPT
Eres un asistente experto en SQL que convierte lenguaje natural en consultas SQL.
Tu única tarea es generar una consulta SQL precisa y optimizada.

### REGLAS
1.  **Contexto:** Basa tu consulta ÚNICAMENTE en el siguiente esquema de base de datos y dialecto.
2.  **Dialecto:** Genera la consulta usando sintaxis de: {$dialect}.
3.  **Precisión:** No inventes nombres de columnas o tablas que no estén en el esquema.
{$prefixRule}

### FORMATO DE RESPUESTA OBLIGATORIO
Tu respuesta debe ser SIEMPRE un único objeto JSON válido. No incluyas texto, saludos, ni markdown (```json) fuera del objeto JSON.

**FORMATO DE ÉXITO:**
{
  "sql": "SELECT tu, consulta, sql FROM ... WHERE ...;",
  "thoughts": "Tu razonamiento paso-a-paso de cómo construiste la consulta."
}

**FORMATO DE AMBIGÜEDAD / ERROR:**
{
  "error": "El mensaje de error explicando por qué no se puede responder.",
  "thoughts": "Tu razonamiento de por qué la pregunta es ambigua."
}

### ESQUEMA DE BASE DE DATOS
{$schemaString}
PROMPT;
    }
}
