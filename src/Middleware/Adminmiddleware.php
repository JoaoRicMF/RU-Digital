<?php
// =============================================================
//  src/Middleware/AdminMiddleware.php
//  Valida o JWT E verifica se o utilizador tem perfil 'admin'.
//  Uso: $payload = AdminMiddleware::require();
// =============================================================

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Exception;

class AdminMiddleware
{
    /**
     * Extrai, valida o JWT e confirma que o campo `tipo` é 'admin'.
     * Encerra com 401 se o token for inválido/expirado.
     * Encerra com 403 se o utilizador não for administrador.
     *
     * @return object  stdClass com os campos do payload (sub, nome, tipo, iat, exp)
     */
    public static function require(): object
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Suporte a CGI/FastCGI
        if (empty($authHeader)) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Token de acesso não fornecido.', 401);
        }

        $token  = substr($authHeader, 7);
        $secret = $_ENV['JWT_SECRET'] ?? '';

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));

        } catch (ExpiredException) {
            Response::error('Sessão expirada. Faça login novamente.', 401);

        } catch (SignatureInvalidException) {
            Response::error('Token inválido.', 401);

        } catch (Exception $e) {
            error_log('[JWT Admin] Erro ao decodificar: ' . $e->getMessage());
            Response::error('Autenticação falhou.', 401);
        }

        // Verifica se o utilizador tem perfil de administrador
        if (($payload->tipo ?? '') !== 'admin') {
            Response::error('Acesso negado. Permissão de administrador necessária.', 403);
        }

        return $payload;
    }
}