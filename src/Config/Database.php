<?php
// =============================================================
//  src/Config/Database.php
//  Conexão PDO singleton — reutiliza a mesma instância durante
//  todo o ciclo de vida da requisição.
// =============================================================

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Retorna a instância PDO, criando-a na primeira chamada.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }

        return self::$instance;
    }

    private static function connect(): PDO
    {
        $host    = $_ENV['DB_HOST'] ?? 'localhost';
        $port    = $_ENV['DB_PORT'] ?? '3306';
        $dbname  = $_ENV['DB_NAME'] ?? '';
        $user    = $_ENV['DB_USER'] ?? '';
        $pass    = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        $options = [
            // Lança exceções em erros — nunca retorna false silenciosamente
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Retorna arrays associativos por padrão
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // CRÍTICO: desativa prepared statements emulados
            // Com false, o MySQL processa os parâmetros separadamente do SQL
            // — proteção real contra SQL Injection
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Garante charset correto na conexão
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Loga o erro real internamente, mas não expõe credenciais ao cliente
            error_log('[DB] Falha na conexão: ' . $e->getMessage());
            throw new RuntimeException('Serviço de banco de dados indisponível.', 503);
        }
    }

    // Evita clone e unserialize do singleton
    private function __clone() {}
    private function __construct() {}
}
