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

        // Suporte a CGI/FastCGI que pode renomear o header
        if (empty($authHeader)) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Token de acesso não fornecido.', 401);
        }

        $token  = substr($authHeader, 7); // Remove 'Bearer '
        $secret = $_ENV['JWT_SECRET'] ?? '';

        try {
            $payload = JWT::decode($token, new Key($secret, 'HS256'));
            return $payload;

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
