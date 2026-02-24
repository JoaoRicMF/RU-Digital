<?php
// =============================================================
//  public/index.php  —  Front Controller
//  ÚNICO ponto de entrada da API.
//
//  Rotas disponíveis:
//    POST   /api/auth/login
//    POST   /api/auth/recuperar
//    POST   /api/auth/redefinir
//    GET    /api/saldo
//    POST   /api/recarga
//    GET    /api/extrato
//    GET    /api/cardapio
//    POST   /api/avaliacao
//    GET    /api/avaliacao
//    --- Admin (requerem tipo = 'admin' no JWT) ---
//    GET    /api/admin/cardapio
//    POST   /api/admin/cardapio
//    PUT    /api/admin/cardapio/:id
//    DELETE /api/admin/cardapio/:id
//    GET    /api/admin/usuarios
//    PUT    /api/admin/usuarios/:id
//    GET    /api/admin/avaliacoes
// =============================================================

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers\Response;
use App\Controllers\AuthController;
use App\Controllers\WalletController;
use App\Controllers\MenuController;
use App\Controllers\RatingController;
use App\Controllers\AdminController;
use Dotenv\Dotenv;

// ------------------------------------------------------------------
// 1. VARIÁVEIS DE AMBIENTE
// ------------------------------------------------------------------
$envPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') ?: dirname(__DIR__);
$dotenv  = Dotenv::createImmutable($envPath);
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'JWT_SECRET', 'APP_URL'])->notEmpty();
$dotenv->required(['DB_PASS']);

// ------------------------------------------------------------------
// 2. TIMEZONE
// ------------------------------------------------------------------
date_default_timezone_set('America/Sao_Paulo');

// ------------------------------------------------------------------
// 3. CORS PREFLIGHT
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $_ENV['APP_URL']);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// Adiciona CORS em todas as respostas
header('Access-Control-Allow-Origin: ' . $_ENV['APP_URL']);

// ------------------------------------------------------------------
// 4. MÉTODO E URI
// ------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/' && stripos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

if (strpos($uri, '/api') !== 0 && $uri !== '/') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'mensagem' => 'Recurso não encontrado.', 'codigo' => 404]);
    exit;
}

$uri = substr($uri, 4);          // Remove /api
$uri = rtrim($uri, '/') ?: '/';

if ($uri === '/' || $uri === '/index.html') {
    include __DIR__ . '/index.html';
    exit;
}

// ------------------------------------------------------------------
// 5. BODY JSON
// ------------------------------------------------------------------
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $body = json_decode($raw, true) ?? [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::error('JSON malformado: ' . json_last_error_msg(), 400);
        }
    }
}

// ------------------------------------------------------------------
// 6. ROTEAMENTO
// ------------------------------------------------------------------

/**
 * Extrai um segmento numérico de ID de uma URI.
 * Ex: /admin/cardapio/42  → 42
 *     /admin/usuarios/7   → 7
 */
function extractId(string $uri, string $prefix): ?int
{
    if (preg_match('#^' . preg_quote($prefix, '#') . '/(\d+)$#', $uri, $m)) {
        return (int) $m[1];
    }
    return null;
}

try {
    // --- Rotas de administração ---
    $adminCardapioId  = extractId($uri, '/admin/cardapio');
    $adminUsuarioId   = extractId($uri, '/admin/usuarios');

    $result = match (true) {

        // ── Autenticação (pública) ──────────────────────────────────
        $method === 'POST' && $uri === '/auth/login'
            => (new AuthController())->login($body),

        $method === 'POST' && $uri === '/auth/recuperar'
            => (new AuthController())->recuperar($body),

        $method === 'POST' && $uri === '/auth/redefinir'
            => (new AuthController())->redefinir($body),

        // ── Carteira (protegidas) ───────────────────────────────────
        $method === 'GET'  && $uri === '/saldo'
            => (new WalletController())->saldo(),

        $method === 'POST' && $uri === '/recarga'
            => (new WalletController())->recarga($body),

        $method === 'GET'  && $uri === '/extrato'
            => (new WalletController())->extrato(),

        // ── Cardápio público (protegida) ────────────────────────────
        $method === 'GET'  && $uri === '/cardapio'
            => (new MenuController())->cardapio(),

        // ── Avaliações (protegidas) ─────────────────────────────────
        $method === 'POST' && $uri === '/avaliacao'
            => (new RatingController())->salvar($body),

        $method === 'GET'  && $uri === '/avaliacao'
            => (new RatingController())->buscar(),

        // ── Admin: Cardápio ─────────────────────────────────────────
        $method === 'GET'  && $uri === '/admin/cardapio'
            => (new AdminController())->listarCardapios(),

        $method === 'POST' && $uri === '/admin/cardapio'
            => (new AdminController())->criarCardapio($body),

        $method === 'PUT'  && $adminCardapioId !== null
            => (new AdminController())->editarCardapio($adminCardapioId, $body),

        $method === 'DELETE' && $adminCardapioId !== null
            => (new AdminController())->excluirCardapio($adminCardapioId),

        // ── Admin: Utilizadores ─────────────────────────────────────
        $method === 'GET'  && $uri === '/admin/usuarios'
            => (new AdminController())->listarUsuarios(),

        $method === 'PUT'  && $adminUsuarioId !== null
            => (new AdminController())->editarUsuario($adminUsuarioId, $body),

        // ── Admin: Avaliações (relatório) ───────────────────────────
        $method === 'GET'  && $uri === '/admin/avaliacoes'
            => (new AdminController())->relatorioAvaliacoes(),

        // ── Rota não encontrada ─────────────────────────────────────
        default => Response::error("Rota não encontrada: {$method} /api{$uri}", 404),
    };

} catch (\RuntimeException $e) {
    error_log('[API] RuntimeException: ' . $e->getMessage());
    Response::error($e->getMessage(), $e->getCode() ?: 503);

} catch (\Throwable $e) {
    error_log('[API] Erro inesperado: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    $msg = ($_ENV['APP_ENV'] ?? 'production') === 'development'
        ? $e->getMessage()
        : 'Erro interno do servidor.';
    Response::error($msg, 500);
}