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

// Valida variáveis obrigatórias (incluindo APP_URL para o CORS)
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'JWT_SECRET', 'APP_URL'])->notEmpty();
$dotenv->required(['DB_PASS']);

// ------------------------------------------------------------------
// 2. TIMEZONE
// ------------------------------------------------------------------
date_default_timezone_set('America/Sao_Paulo');

// ------------------------------------------------------------------
// 3. TRATA PREFLIGHT CORS (OPTIONS)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // APP_URL é validado como obrigatório na inicialização: sem fallback para '*'
    header('Access-Control-Allow-Origin: ' . $_ENV['APP_URL']);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// 4. EXTRAI MÉTODO E CAMINHO DA REQUISIÇÃO
// ------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Descobre automaticamente a pasta base do XAMPP (ex: /RU-Digital/public)
$basePath = dirname($_SERVER['SCRIPT_NAME']);

// Remove a pasta base da URL
if ($basePath !== '/' && stripos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

// Garante que somente rotas /api/* sejam processadas aqui.
// Qualquer outra coisa que chegou ao PHP (ex: arquivo estático inexistente)
// recebe 404 limpo, sem entrar no roteador da API.
if (strpos($uri, '/api') !== 0 && $uri !== '/') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'mensagem' => 'Recurso não encontrado.', 'codigo' => 404]);
    exit;
}

// Remove o /api do começo do caminho
$uri = substr($uri, 4);

// Normaliza a URI para o formato final
$uri = rtrim($uri, '/') ?: '/';

if ($uri === '/' || $uri === '/index.html') {
    include __DIR__ . '/index.html';
    exit;
}

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