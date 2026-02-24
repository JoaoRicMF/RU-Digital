<?php
// =============================================================
//  src/Middleware/AuthMiddleware.php
//  Valida o JWT enviado no cabeçalho Authorization.
//  Uso: $payload = AuthMiddleware::require();
// =============================================================

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Exception;

class AuthMiddleware
{
    /**
     * Extrai e valida o JWT. Encerra com 401 se inválido.
     * Retorna o payload decodificado em caso de sucesso.
     *
     * @return object  stdClass com os campos do payload (sub, nome, iat, exp)
     */
    public static function require(): object
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader)) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        // FORÇA a captura do cabeçalho caso o servidor PHP local o tenha ocultado
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            if (isset($headers['authorization'])) {
                $authHeader = $headers['authorization'];
            }
        }

        // Valida se o cabeçalho existe e começa com "Bearer " (ignorando maiúsculas/minúsculas)
        if (empty($authHeader) || !preg_match('/^Bearer\s+(.*)$/i', $authHeader, $matches)) {
            Response::error('Token de acesso não fornecido.', 401);
        }

        $token  = $matches[1]; // Pega apenas a chave, sem a palavra Bearer
        $secret = $_ENV['JWT_SECRET'] ?? '';

        try {
            return JWT::decode($token, new Key($secret, 'HS256'));

        } catch (ExpiredException) {
            Response::error('Sessão expirada. Faça login novamente.', 401);

        } catch (SignatureInvalidException) {
            Response::error('Token inválido.', 401);

        } catch (Exception $e) {
            error_log('[JWT] Erro ao decodificar: ' . $e->getMessage());
            Response::error('Autenticação falhou.', 401);
        }
    }
}
