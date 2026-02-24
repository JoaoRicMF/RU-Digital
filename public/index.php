<?php
// =============================================================
//  public/index.php  —  Front Controller
//  ÚNICO ponto de entrada da API. O .htaccess redireciona
//  todas as requisições para este arquivo.
//
//  Rotas disponíveis:
//    POST   /api/auth/login
//    POST   /api/auth/recuperar
//    GET    /api/saldo
//    POST   /api/recarga
//    GET    /api/extrato
//    GET    /api/cardapio
//    POST   /api/avaliacao
//    GET    /api/avaliacao
// =============================================================

declare(strict_types=1);

// Carrega o autoloader do Composer
require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers\Response;
use App\Controllers\AuthController;
use App\Controllers\WalletController;
use App\Controllers\MenuController;
use App\Controllers\RatingController;
use Dotenv\Dotenv;

// ------------------------------------------------------------------
// 1. CARREGA VARIÁVEIS DE AMBIENTE (.env)
// ------------------------------------------------------------------
$envPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') ?: dirname(__DIR__);
$dotenv = Dotenv::createImmutable($envPath);
$dotenv->load();

// Valida variáveis obrigatórias
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'])->notEmpty();

// ------------------------------------------------------------------
// 2. TIMEZONE
// ------------------------------------------------------------------
date_default_timezone_set('America/Sao_Paulo');

// ------------------------------------------------------------------
// 3. TRATA PREFLIGHT CORS (OPTIONS)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_ENV['APP_URL'] ?? '*'));
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// 4. EXTRAI MÉTODO E CAMINHO DA REQUISIÇÃO
// ------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];

// Remove a base path e query string; normaliza barras
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim(str_replace('/api', '', $uri), '/') ?: '/';

// ------------------------------------------------------------------
// 5. LÊ O BODY JSON (para POST/PUT)
// ------------------------------------------------------------------
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $body = json_decode($raw, true) ?? [];

        // Se json_decode falhou, responde com erro claro
        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::error('JSON malformado: ' . json_last_error_msg(), 400);
        }
    }
}

// ------------------------------------------------------------------
// 6. ROTEAMENTO
// ------------------------------------------------------------------
try {

    match (true) {

        // --- Autenticação (pública) ---
        $method === 'POST' && $uri === '/auth/login'
            => (new AuthController())->login($body),

        $method === 'POST' && $uri === '/auth/recuperar'
            => (new AuthController())->recuperar($body),

        // --- Carteira (protegidas) ---
        $method === 'GET'  && $uri === '/saldo'
            => (new WalletController())->saldo(),

        $method === 'POST' && $uri === '/recarga'
            => (new WalletController())->recarga($body),

        $method === 'GET'  && $uri === '/extrato'
            => (new WalletController())->extrato(),

        // --- Cardápio (protegida) ---
        $method === 'GET'  && $uri === '/cardapio'
            => (new MenuController())->cardapio(),

        // --- Avaliações (protegidas) ---
        $method === 'POST' && $uri === '/avaliacao'
            => (new RatingController())->salvar($body),

        $method === 'GET'  && $uri === '/avaliacao'
            => (new RatingController())->buscar(),

        // --- Rota não encontrada ---
        default => Response::error("Rota não encontrada: {$method} /api{$uri}", 404),
    };

} catch (\RuntimeException $e) {
    // Erros de infraestrutura (ex: BD indisponível)
    error_log('[API] RuntimeException: ' . $e->getMessage());
    Response::error($e->getMessage(), $e->getCode() ?: 503);

} catch (\Throwable $e) {
    // Qualquer outro erro não tratado
    error_log('[API] Erro inesperado: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());

    // Em produção, não expõe detalhes do erro
    $msg = ($_ENV['APP_ENV'] ?? 'production') === 'development'
        ? $e->getMessage()
        : 'Erro interno do servidor.';

    Response::error($msg, 500);
}
