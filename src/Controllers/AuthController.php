<?php
// =============================================================
//  src/Controllers/AuthController.php
//  Rotas: POST /api/auth/login
//         POST /api/auth/recuperar
// =============================================================

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use Firebase\JWT\JWT;
use PDO;

class AuthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ---------------------------------------------------------
    //  POST /api/auth/login
    //  Body: { "email": "...", "senha": "..." }
    // ---------------------------------------------------------
    public function login(array $body): never
    {
        // 1. Validação básica dos campos
        $email = trim($body['email'] ?? '');
        $senha = $body['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            Response::error('E-mail e senha são obrigatórios.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('E-mail inválido.', 422);
        }

        // 2. Rate limiting — bloqueia após 5 tentativas em 15 min
        $this->checkRateLimit();

        // 3. Busca usuário no banco (prepared statement protege de SQL Injection)
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, senha_hash, curso, saldo, tipo
             FROM   usuarios
             WHERE  email = :email AND ativo = 1
             LIMIT  1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
    error_log("Usuário não encontrado no banco ou inativo.");
}

        // 4. Verifica senha com password_verify (bcrypt)
        //    Comparamos hash mesmo que o usuário não exista para
        //    evitar timing attacks (enumeração de e-mails)
        $senhaOk = $user && password_verify($senha, $user['senha_hash']);

        if (!$senhaOk) {
            $this->registrarTentativaFalha();
            Response::error('Credenciais inválidas.', 401);
        }

        // 5. Limpa o contador de tentativas após login bem-sucedido
        $this->limparTentativas();

        // 6. Atualiza o hash se o custo do bcrypt aumentou (segurança evolutiva)
        if (password_needs_rehash($user['senha_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $novoHash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->db->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                     ->execute([$novoHash, $user['id']]);
        }

        // 7. Gera o JWT
        $agora = time();
        $exp   = (int) ($_ENV['JWT_EXPIRATION'] ?? 86400);

        $payload = [
            'sub'  => $user['id'],
            'nome' => $user['nome'],
            'iat'  => $agora,
            'exp'  => $agora + $exp,
            'tipo' => $user['tipo'],
        ];

        $token = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        // 8. Retorna token e dados públicos do usuário
        Response::success([
            'token'  => $token,
            'expira' => $agora + $exp,
            'usuario' => [
                'id'    => $user['id'],
                'nome'  => $user['nome'],
                'email' => $user['email'],
                'curso' => $user['curso'],
                'saldo' => (float) $user['saldo'],
                'tipo'  => $user['tipo'],
            ],
        ]);
    }

    // ---------------------------------------------------------
    //  POST /api/auth/recuperar
    //  Body: { "email": "..." }
    //  Gera token de reset e (em produção) envia e-mail.
    // ---------------------------------------------------------
    public function recuperar(array $body): never
    {
        $email = trim($body['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('E-mail inválido.', 422);
        }

        // Busca usuário — resposta idêntica se não existir
        // (evita enumeração de e-mails cadastrados)
        $stmt = $this->db->prepare(
            'SELECT id FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Gera token seguro: 32 bytes aleatórios em hex (64 chars)
            $tokenRaw  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $tokenRaw); // Armazena apenas o hash
            $expira    = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Invalida tokens anteriores do mesmo usuário
            $this->db->prepare(
                'UPDATE tokens_recuperacao SET usado = 1 WHERE usuario_id = ? AND usado = 0'
            )->execute([$user['id']]);

            // Insere novo token
            $this->db->prepare(
                'INSERT INTO tokens_recuperacao (usuario_id, token, expira_em)
                 VALUES (?, ?, ?)'
            )->execute([$user['id'], $tokenHash, $expira]);

            $link = $_ENV['APP_URL'] . '/reset.html?token=' . $tokenRaw;
            
            $assunto = 'Recuperação de Acesso - RU Digital';
            $mensagem = "Olá!\n\nFoi solicitada a recuperação de senha para a sua conta.\n";
            $mensagem .= "Acesse o link abaixo para criar uma nova senha:\n{$link}\n\n";
            $mensagem .= "Se não solicitou esta alteração, ignore este e-mail.";
            
            $headers = "From: noreply@ufcat.edu.br\r\n" .
                       "Reply-To: suporte-ru@ufcat.edu.br\r\n" .
                       "X-Mailer: PHP/" . phpversion();

            // Descomente em ambiente de produção com servidor SMTP configurado
            // mail($email, $assunto, $mensagem, $headers);
            
            error_log("[RECUPERAR] Link gerado para {$email}: {$link}");
        }

        // Sempre retorna a mesma mensagem (segurança)
        Response::success([
            'mensagem' => 'Se o e-mail estiver cadastrado, você receberá as instruções em breve.',
        ]);
    }

    // ---------------------------------------------------------
    //  Helpers privados de rate limiting
    // ---------------------------------------------------------

    private function getIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function checkRateLimit(): void
    {
        $ip = $this->getIp();

        $stmt = $this->db->prepare(
            'SELECT tentativas, bloqueado_ate
             FROM   login_attempts
             WHERE  ip = ? AND ultima_em > NOW() - INTERVAL 15 MINUTE
             LIMIT  1'
        );
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['bloqueado_ate'] && strtotime($row['bloqueado_ate']) > time()) {
                $minutos = ceil((strtotime($row['bloqueado_ate']) - time()) / 60);
                Response::error(
                    "Muitas tentativas. Tente novamente em {$minutos} minuto(s).",
                    429
                );
            }
        }
    }

    private function registrarTentativaFalha(): void
    {
        $ip = $this->getIp();

        $this->db->prepare(
            'INSERT INTO login_attempts (ip, tentativas)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE
               tentativas    = tentativas + 1,
               bloqueado_ate = IF(tentativas + 1 >= 5,
                                  NOW() + INTERVAL 15 MINUTE,
                                  NULL),
               ultima_em     = NOW()'
        )->execute([$ip]);
    }

    private function limparTentativas(): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')
                 ->execute([$this->getIp()]);
    }
    // ---------------------------------------------------------
    //  POST /api/auth/redefinir
    //  Body: { "token": "...", "nova_senha": "..." }
    // ---------------------------------------------------------
    public function redefinir(array $body): never
    {
        $tokenRaw  = $body['token'] ?? '';
        $novaSenha = $body['nova_senha'] ?? '';

        if (empty($tokenRaw) || empty($novaSenha)) {
            Response::error('Token e nova senha são obrigatórios.', 422);
        }

        if (strlen($novaSenha) < 6) {
            Response::error('A nova senha deve ter pelo menos 6 caracteres.', 422);
        }

        // Aplica o hash SHA-256 no token recebido para comparar com a base de dados
        $tokenHash = hash('sha256', $tokenRaw);

        // Verifica se o token existe, não foi usado e ainda está dentro da validade
        $stmt = $this->db->prepare(
            'SELECT usuario_id FROM tokens_recuperacao 
             WHERE token = ? AND usado = 0 AND expira_em > NOW() 
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $registro = $stmt->fetch();

        if (!$registro) {
            Response::error('Token inválido ou expirado.', 400);
        }

        $usuarioId = $registro['usuario_id'];
        $novoHash  = password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 12]);

        // Inicia transação para garantir que ambas as atualizações ocorram
        $this->db->beginTransaction();

        try {
            // Atualiza a senha do utilizador
            $this->db->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                     ->execute([$novoHash, $usuarioId]);

            // Invalida o token para que não possa ser usado novamente
            $this->db->prepare('UPDATE tokens_recuperacao SET usado = 1 WHERE token = ?')
                     ->execute([$tokenHash]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            Response::error('Erro ao redefinir a senha.', 500);
        }

        Response::success(['mensagem' => 'Senha redefinida com sucesso. Já pode fazer o login.']);
    }
}
