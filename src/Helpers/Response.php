<?php
// =============================================================
//  src/Helpers/Response.php
//  Padroniza todas as respostas JSON da API.
//  Todo endpoint termina chamando Response::send() ou
//  um dos helpers (success / error).
// =============================================================

declare(strict_types=1);

namespace App\Helpers;

class Response
{
    /**
     * Envia uma resposta JSON e encerra a execução.
     *
     * @param mixed $data    Dados a serializar
     * @param int   $status  Código HTTP (200, 201, 400, 401, 404, 422, 500…)
     */
    public static function send(mixed $data, int $status = 200): never
    {
        // Cabeçalhos de segurança — enviados em todas as respostas
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        // CORS — ajuste para o domínio real em produção
        $origin = $_ENV['APP_URL'] ?? '*';
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Resposta de sucesso padronizada:
     * { "status": "success", "data": { ... } }
     */
    public static function success(mixed $data = null, int $status = 200): never
    {
        self::send([
            'status' => 'success',
            'data'   => $data,
        ], $status);
    }

    /**
     * Resposta de erro padronizada:
     * { "status": "error", "mensagem": "...", "codigo": 400 }
     */
    public static function error(string $mensagem, int $status = 400): never
    {
        self::send([
            'status'   => 'error',
            'mensagem' => $mensagem,
            'codigo'   => $status,
        ], $status);
    }

    /**
     * Resposta para requisições OPTIONS (preflight CORS)
     */
    public static function preflight(): never
    {
        self::send(null, 204);
    }
}
