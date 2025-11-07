<?php

namespace App\Services;

use App\Exceptions\SQLValidationException;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use Throwable;

class SQLValidationService
{
    /**
     * Valida un string de consulta SQL contra un conjunto de reglas de seguridad.
     *
     * @param string $sqlQuery El string SQL crudo devuelto por la IA.
     * @return bool True si la validación pasa.
     * @throws SQLValidationException Si la consulta es insegura o inválida.
     */
    public function validate(string $sqlQuery): bool
    {
        try {
            // 1. Intentar parsear el SQL
            $parser = new Parser($sqlQuery);
        } catch (Throwable $e) {
            // Falla si la IA genera sintaxis inválida (ej. texto simple)
            throw new SQLValidationException('Sintaxis SQL inválida o no reconocible.');
        }

        // 2. Regla de Seguridad: Solo se permite UNA (1) consulta
        if (count($parser->statements) !== 1) {
            throw new SQLValidationException('Se detectaron múltiples consultas. Solo se permite una (1) consulta SELECT.');
        }

        $statement = $parser->statements[0];

        // 3. Regla de Seguridad: El tipo de consulta DEBE ser SELECT
        if (!($statement instanceof SelectStatement)) {
            $queryType = $this->getStatementType($statement);
            throw new SQLValidationException("Operación no permitida. Solo se permiten consultas SELECT, pero se recibió un {$queryType}.");
        }

        // (Aquí se podrían añadir reglas futuras, ej. bloquear 'SELECT ... INTO OUTFILE')

        return true;
    }

    /**
     * Método auxiliar para obtener el tipo de consulta para los logs de error.
     */
    private function getStatementType($statement): string
    {
        $className = get_class($statement);
        $parts = explode('\\', $className);
        // Convierte 'PhpMyAdmin\SqlParser\Statements\DropStatement' en 'DropStatement'
        return str_replace('Statement', '', end($parts));
    }
}
