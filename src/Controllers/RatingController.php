<?php
// =============================================================
//  src/Controllers/RatingController.php
//  Rotas:
//    POST /api/avaliacao      — submete avaliação
//    GET  /api/avaliacao      — busca avaliação do usuário p/ hoje
// =============================================================

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use PDO;

class RatingController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ---------------------------------------------------------
    //  POST /api/avaliacao
    //  Body: {
    //    "cardapio_id": 1,
    //    "nota_sabor": 5, "nota_temp": 4, "nota_atend": 5,
    //    "nota_limpeza": 4, "nota_geral": 5,
    //    "comentario": "Ótimo!"
    //  }
    // ---------------------------------------------------------
    public function salvar(array $body): never
    {
        $payload   = AuthMiddleware::require();
        $usuarioId = (int) $payload->sub;

        $cardapioId = filter_var($body['cardapio_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$cardapioId) {
            Response::error('cardapio_id inválido.', 422);
        }

        // Valida notas (1–5, opcionais individualmente, mas pelo menos uma obrigatória)
        $notas = [];
        foreach (['nota_sabor', 'nota_temp', 'nota_atend', 'nota_limpeza', 'nota_geral'] as $campo) {
            if (isset($body[$campo])) {
                $val = filter_var($body[$campo], FILTER_VALIDATE_INT);
                if ($val === false || $val < 1 || $val > 5) {
                    Response::error("{$campo} deve ser um número entre 1 e 5.", 422);
                }
                $notas[$campo] = $val;
            } else {
                $notas[$campo] = null;
            }
        }

        $temNota = array_filter($notas, fn($v) => $v !== null);
        if (empty($temNota)) {
            Response::error('Avalie pelo menos um critério.', 422);
        }

        $comentario = isset($body['comentario'])
            ? mb_substr(trim($body['comentario']), 0, 1000)
            : null;

        // Verifica se o cardápio existe
        $stmt = $this->db->prepare('SELECT id FROM cardapio WHERE id = ? LIMIT 1');
        $stmt->execute([$cardapioId]);
        if (!$stmt->fetch()) {
            Response::error('Cardápio não encontrado.', 404);
        }

        // Upsert: atualiza se já avaliou, insere se não
        $sql = 'INSERT INTO avaliacoes
                    (usuario_id, cardapio_id, nota_sabor, nota_temp, nota_atend,
                     nota_limpeza, nota_geral, comentario)
                VALUES
                    (:uid, :cid, :sabor, :temp, :atend, :limpeza, :geral, :comentario)
                ON DUPLICATE KEY UPDATE
                    nota_sabor   = VALUES(nota_sabor),
                    nota_temp    = VALUES(nota_temp),
                    nota_atend   = VALUES(nota_atend),
                    nota_limpeza = VALUES(nota_limpeza),
                    nota_geral   = VALUES(nota_geral),
                    comentario   = VALUES(comentario)';

        $this->db->prepare($sql)->execute([
            ':uid'        => $usuarioId,
            ':cid'        => $cardapioId,
            ':sabor'      => $notas['nota_sabor'],
            ':temp'       => $notas['nota_temp'],
            ':atend'      => $notas['nota_atend'],
            ':limpeza'    => $notas['nota_limpeza'],
            ':geral'      => $notas['nota_geral'],
            ':comentario' => $comentario,
        ]);

        Response::success(['mensagem' => 'Avaliação enviada com sucesso! O RU agradece seu feedback.'], 201);
    }

    // ---------------------------------------------------------
    //  GET /api/avaliacao?cardapio_id=1
    // ---------------------------------------------------------
    public function buscar(): never
    {
        $payload   = AuthMiddleware::require();
        $usuarioId = (int) $payload->sub;

        $cardapioId = filter_var($_GET['cardapio_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$cardapioId) {
            Response::error('cardapio_id inválido.', 422);
        }

        $stmt = $this->db->prepare(
            'SELECT nota_sabor, nota_temp, nota_atend, nota_limpeza, nota_geral,
                    comentario, criado_em
             FROM   avaliacoes
             WHERE  usuario_id = ? AND cardapio_id = ?
             LIMIT  1'
        );
        $stmt->execute([$usuarioId, $cardapioId]);
        $aval = $stmt->fetch();

        if (!$aval) {
            Response::success(['avaliacao' => null]);
        }

        Response::success(['avaliacao' => $aval]);
    }
}
