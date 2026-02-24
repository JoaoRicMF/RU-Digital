<?php
// public/criar-admin.php
require_once __DIR__ . '/../vendor/autoload.php';

// Carrega as variáveis de ambiente
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

try {
    // Conecta usando a exata mesma classe da sua API
    $db = App\Config\Database::getConnection();
    
    $email = 'admin@ufcat.edu.br';
    $senha = '123456';
    $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

    // 1. Remove o usuário antigo para evitar conflito de UNIQUE
    $db->prepare("DELETE FROM usuarios WHERE email = ?")->execute([$email]);

    // 2. Insere o administrador com os dados corretos
    $sql = "INSERT INTO usuarios (matricula, nome, email, senha_hash, curso, saldo, tipo, ativo) 
            VALUES ('admin999', 'Administrador RU', ?, ?, 'Gestão', 0.00, 'admin', 1)";
    $db->prepare($sql)->execute([$email, $hash]);

    // 3. Limpa qualquer bloqueio de tentativas de login (rate limiting)
    $db->query("DELETE FROM login_attempts");

    echo "<div style='font-family: sans-serif; text-align: center; margin-top: 50px;'>";
    echo "<h1 style='color: #16a34a;'>✅ Administrador configurado!</h1>";
    echo "<p>Utilize os dados abaixo para fazer login:</p>";
    echo "<strong>E-mail:</strong> {$email}<br>";
    echo "<strong>Senha:</strong> {$senha}<br><br>";
    echo "<a href='/admin.html' style='padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 5px;'>Ir para o Login Admin</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<h1 style='color: red; font-family: sans-serif;'>❌ Erro ao acessar o banco</h1>";
    echo "<p>{$e->getMessage()}</p>";
}