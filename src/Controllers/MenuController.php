<?php
// =============================================================
//  src/Controllers/MenuController.php
//  Rotas:
//    GET /api/cardapio          — cardápio do dia atual
//    GET /api/cardapio?data=... — cardápio de uma data específica
// =============================================================

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use PDO;

class MenuController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ---------------------------------------------------------
    //  GET /api/cardapio
    // ---------------------------------------------------------
    public function cardapio(): never
    {
        AuthMiddleware::require();

        // Aceita data via query string; padrão: hoje
        $dataParam = $_GET['data'] ?? date('Y-m-d');

        // Valida formato da data
        $data = \DateTime::createFromFormat('Y-m-d', $dataParam);
        if (!$data || $data->format('Y-m-d') !== $dataParam) {
            Response::error('Formato de data inválido. Use YYYY-MM-DD.', 422);
        }

        // Busca todos os cardápios do dia com seus itens
        $stmt = $this->db->prepare(
            'SELECT c.id, c.refeicao,
                    i.categoria, i.descricao, i.calorias, i.proteinas_g, i.carboidratos_g
             FROM   cardapio c
             JOIN   itens_cardapio i ON i.cardapio_id = c.id
             WHERE  c.data_ref = ? AND c.ativo = 1
             ORDER  BY c.refeicao, i.categoria'
        );
        $stmt->execute([$dataParam]);
        $rows = $stmt->fetchAll();

        // Agrupa itens por refeição
        $cardapios = [];
        foreach ($rows as $row) {
            $ref = $row['refeicao'];
            if (!isset($cardapios[$ref])) {
                $cardapios[$ref] = [
                    'id'       => $row['id'],
                    'refeicao' => $ref,
                    'itens'    => [],
                ];
            }
            $cardapios[$ref]['itens'][] = [
                'categoria'      => $row['categoria'],
                'descricao'      => $row['descricao'],
                'calorias'       => $row['calorias'],
                'proteinas_g'    => $row['proteinas_g']    ? (float) $row['proteinas_g']    : null,
                'carboidratos_g' => $row['carboidratos_g'] ? (float) $row['carboidratos_g'] : null,
            ];
        }

        Response::success([
            'data'      => $dataParam,
            'cardapios' => array_values($cardapios),
        ]);
    }
}