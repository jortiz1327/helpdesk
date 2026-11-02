<?php

namespace App\Services\Zkong;

use RuntimeException;

/**
 * Error de un servidor ZKONG (caído, rechaza el acceso, responde otra cosa). Su mensaje
 * está escrito para la pantalla; cualquier otra excepción se queda en el log.
 */
class ZkongException extends RuntimeException
{
}
