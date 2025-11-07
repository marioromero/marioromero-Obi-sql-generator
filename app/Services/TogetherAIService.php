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

        $this->client = Http::withToken($this->apiKey)
            ->timeout(60) // 60 segundos de timeout
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
    public function generateSql(string $userQuestion, string $dialect, array $schemaTables): string
    {
        $systemPrompt = $this->buildSystemPrompt($dialect, $schemaTables);

        try {
            $response = $this->client->post('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userQuestion],
                ],
                'temperature' => 0.0, // Punto 3 del resumen: Control de "Alucinaciones"
                'max_tokens' => 1024,
                'response_format' => ['type' => 'text'], // Forzar respuesta de texto
            ]);

            // Lanza una excepción si la API devuelve 4xx o 5xx
            $response->throw();

            // Extraer el contenido de la respuesta
            $aiResponse = $response->json('choices.0.message.content');

            if (empty($aiResponse)) {
                throw new Exception('La respuesta de la IA estaba vacía.');
            }

            // Devolver el string crudo. El controlador se encargará de
            // revisar si es SQL o un JSON de error de ambigüedad.
            return $aiResponse;

        } catch (RequestException $e) {
            Log::error('Error en la API de Together.ai: ' . $e->getMessage(), [
                'status' => $e->response->status(),
                'response' => $e->response->body(),
            ]);
            throw new Exception('El servicio de traducción no está disponible: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Error en TogetherAIService: ' . $e->getMessage());
            throw $e; // Re-lanzar la excepción
        }
    }

    /**
     * Construye el "mega-prompt" del sistema (Punto 2 del resumen).
     */
    private function buildSystemPrompt(string $dialect, array $schemaTables): string
    {
        $schemaString = implode("\n\n", $schemaTables);

        // Este prompt instruye a la IA sobre todas nuestras reglas de negocio.
        return <<<PROMPT
Eres un asistente experto en SQL que convierte lenguaje natural en consultas SQL.
Tu única tarea es generar una consulta SQL precisa y optimizada.

### REGLAS
1.  **Contexto:** Basa tu consulta ÚNICAMENTE en el siguiente esquema de base de datos y dialecto.
2.  **Dialecto:** Genera la consulta usando sintaxis de: {$dialect}.
3.  **Precisión:** No inventes nombres de columnas o tablas que no estén en el esquema.
4.  **Respuesta:** Responde SOLAMENTE con el código SQL. No añadas explicaciones, saludos, ni markdown (```sql).
5.  **Ambigüedad (Punto 3 del resumen):** Si la pregunta del usuario es ambigua, vaga o no se puede responder con el esquema proporcionado (ej. "dame las ventas" pero "ventas" no está definido), NO generes SQL. En su lugar, responde con un objeto JSON de error, y NADA MÁS, con el formato:
    {"error": true, "message": "Tu pregunta es ambigua. Por favor, sé más específico."}

### ESQUEMA DE BASE DE DATOS
{$schemaString}
PROMPT;
    }
}
