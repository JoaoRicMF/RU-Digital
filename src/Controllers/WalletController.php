<?php
// =============================================================
//  src/Controllers/WalletController.php
//  Rotas (todas protegidas por JWT):
//    GET  /api/saldo
//    POST /api/recarga
//    GET  /api/extrato
// =============================================================

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use PDO;

class WalletController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ---------------------------------------------------------
    //  GET /api/saldo
    //  Header: Authorization: Bearer <token>
    // ---------------------------------------------------------
    public function saldo(): never
    {
        $payload    = AuthMiddleware::require();
        $usuarioId  = (int) $payload->sub;

        $stmt = $this->db->prepare(
            'SELECT saldo FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Usuário não encontrado.', 404);
        }

        Response::success(['saldo' => (float) $row['saldo']]);
    }

    // ---------------------------------------------------------
    //  POST /api/recarga
    //  Header: Authorization: Bearer <token>
    //  Body:   { "valor": 20.00, "metodo": "pix" }
    // ---------------------------------------------------------
    public function recarga(array $body): never
    {
        $payload   = AuthMiddleware::require();
        $usuarioId = (int) $payload->sub;

        // Validação de entrada
        $valor = filter_var($body['valor'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($valor === false || $valor === null) {
            Response::error('Valor inválido.', 422);
        }

        if ($valor < 1.00 || $valor > 1000.00) {
            Response::error('Valor deve ser entre R$ 1,00 e R$ 1.000,00.', 422);
        }

        $metodo    = $body['metodo'] ?? 'app';
        $descricao = 'Recarga - ' . ucfirst($metodo);

        // Chama a Stored Procedure atômica
        // A procedure usa SELECT ... FOR UPDATE para evitar race conditions
        $stmt = $this->db->prepare(
            'CALL registrar_transacao(:uid, "recarga", :valor, :desc, :metodo,
                                     @p_sucesso, @p_msg, @p_saldo)'
        );
        $stmt->execute([
            ':uid'    => $usuarioId,
            ':valor'  => $valor,
            ':desc'   => $descricao,
            ':metodo' => $metodo,
        ]);

        // Lê os parâmetros OUT da procedure
        $out = $this->db->query(
            'SELECT @p_sucesso AS sucesso, @p_msg AS mensagem, @p_saldo AS saldo'
        )->fetch();

        if (!$out['sucesso']) {
            Response::error($out['mensagem'], 400);
        }

        Response::success([
            'mensagem'      => $out['mensagem'],
            'valor_recarga' => $valor,
            'saldo_atual'   => (float) $out['saldo'],
        ], 201);
    }

    // ---------------------------------------------------------
    //  GET /api/extrato?limit=10&offset=0
    // ---------------------------------------------------------
    public function extrato(): never
    {
        $payload   = AuthMiddleware::require();
        $usuarioId = (int) $payload->sub;

        $limit  = min((int) ($_GET['limit']  ?? 10), 50); // máx 50 por página
        $offset = max((int) ($_GET['offset'] ?? 0),  0);

        $stmt = $this->db->prepare(
            'SELECT id, tipo, valor, descricao, metodo_pgto, saldo_apos, criado_em
             FROM   transacoes
             WHERE  usuario_id = ?
             ORDER  BY criado_em DESC
             LIMIT  ? OFFSET ?'
        );
        $stmt->execute([$usuarioId, $limit, $offset]);
        $transacoes = $stmt->fetchAll();

        // Formata valores para o frontend
        $resultado = array_map(fn($t) => [
            'id'         => $t['id'],
            'tipo'       => $t['tipo'],
            'valor'      => (float) $t['valor'],
            'descricao'  => $t['descricao'],
            'metodo'     => $t['metodo_pgto'],
            'saldo_apos' => (float) $t['saldo_apos'],
            'data'       => $t['criado_em'],
            'isIncome'   => $t['tipo'] !== 'debito',
        ], $transacoes);

        Response::success([
            'transacoes' => $resultado,
            'total'      => count($resultado),
        ]);
    }
}
