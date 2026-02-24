<?php
// =============================================================
//  src/Controllers/AdminController.php
//  Rotas (todas protegidas por AdminMiddleware):
//    GET    /api/admin/cardapio          — lista cardápios com itens
//    POST   /api/admin/cardapio          — cria cardápio + itens
//    PUT    /api/admin/cardapio/:id      — edita cardápio + itens
//    DELETE /api/admin/cardapio/:id      — desativa cardápio (soft delete)
//    GET    /api/admin/usuarios          — lista utilizadores
//    PUT    /api/admin/usuarios/:id      — edita perfil/tipo de utilizador
//    GET    /api/admin/avaliacoes        — relatório de avaliações
// =============================================================

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\AdminMiddleware;
use PDO;

class AdminController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // =========================================================
    //  CARDÁPIO — CRUD
    // =========================================================

    /**
     * GET /api/admin/cardapio[?data=YYYY-MM-DD]
     * Lista os cardápios (padrão: hoje) com todos os seus itens.
     */
    public function listarCardapios(): never
    {
        AdminMiddleware::require();

        $dataParam = $_GET['data'] ?? date('Y-m-d');

        $data = \DateTime::createFromFormat('Y-m-d', $dataParam);
        if (!$data || $data->format('Y-m-d') !== $dataParam) {
            Response::error('Formato de data inválido. Use YYYY-MM-DD.', 422);
        }

        $stmt = $this->db->prepare(
            'SELECT c.id, c.refeicao, c.data_ref, c.ativo,
                    i.id AS item_id, i.categoria, i.descricao,
                    i.imagem_url, i.calorias, i.proteinas_g, i.carboidratos_g
             FROM   cardapio c
             LEFT   JOIN itens_cardapio i ON i.cardapio_id = c.id
             WHERE  c.data_ref = ?
             ORDER  BY c.refeicao, i.categoria'
        );
        $stmt->execute([$dataParam]);
        $rows = $stmt->fetchAll();

        $cardapios = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!isset($cardapios[$id])) {
                $cardapios[$id] = [
                    'id'       => $id,
                    'refeicao' => $row['refeicao'],
                    'data_ref' => $row['data_ref'],
                    'ativo'    => (bool) $row['ativo'],
                    'itens'    => [],
                ];
            }
            if ($row['item_id']) {
                $cardapios[$id]['itens'][] = [
                    'id'             => $row['item_id'],
                    'categoria'      => $row['categoria'],
                    'descricao'      => $row['descricao'],
                    'imagem_url'     => $row['imagem_url'],
                    'calorias'       => $row['calorias'] ? (int) $row['calorias'] : null,
                    'proteinas_g'    => $row['proteinas_g'] ? (float) $row['proteinas_g'] : null,
                    'carboidratos_g' => $row['carboidratos_g'] ? (float) $row['carboidratos_g'] : null,
                ];
            }
        }

        Response::success([
            'data'      => $dataParam,
            'cardapios' => array_values($cardapios),
        ]);
    }

    /**
     * POST /api/admin/cardapio
     * Cria um cardápio com os seus itens.
     *
     * Body esperado:
     * {
     *   "data_ref": "2025-07-10",
     *   "refeicao": "almoco",          // almoco | jantar
     *   "itens": [
     *     { "categoria": "principal",  "descricao": "Frango grelhado", "calorias": 320, "proteinas_g": 35.5, "carboidratos_g": 5.0, "imagem_url": "" },
     *     { "categoria": "guarnicao",  "descricao": "Batata sauté" },
     *     { "categoria": "salada",     "descricao": "Salada verde" },
     *     { "categoria": "sobremesa",  "descricao": "Banana" }
     *   ]
     * }
     */
    public function criarCardapio(array $body): never
    {
        AdminMiddleware::require();

        $this->validarBodyCardapio($body);

        $dataRef  = $body['data_ref'];
        $refeicao = $body['refeicao'];
        $itens    = $body['itens'];

        // Impede duplicação de refeição no mesmo dia
        $chk = $this->db->prepare(
            'SELECT id FROM cardapio WHERE data_ref = ? AND refeicao = ? LIMIT 1'
        );
        $chk->execute([$dataRef, $refeicao]);
        if ($chk->fetch()) {
            Response::error("Já existe um cardápio de '{$refeicao}' para a data {$dataRef}.", 409);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO cardapio (data_ref, refeicao, ativo) VALUES (?, ?, 1)'
            )->execute([$dataRef, $refeicao]);

            $cardapioId = (int) $this->db->lastInsertId();
            $this->inserirItens($cardapioId, $itens);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('[AdminController::criarCardapio] ' . $e->getMessage());
            Response::error('Erro ao criar cardápio.', 500);
        }

        Response::success(['mensagem' => 'Cardápio criado com sucesso.', 'cardapio_id' => $cardapioId], 201);
    }

    /**
     * PUT /api/admin/cardapio/:id
     * Substitui completamente os itens de um cardápio existente.
     */
    public function editarCardapio(int $id, array $body): never
    {
        AdminMiddleware::require();

        // Verifica se o cardápio existe
        $chk = $this->db->prepare('SELECT id FROM cardapio WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            Response::error('Cardápio não encontrado.', 404);
        }

        // Campos opcionais de atualização
        $campos  = [];
        $valores = [];

        if (isset($body['data_ref'])) {
            $dt = \DateTime::createFromFormat('Y-m-d', $body['data_ref']);
            if (!$dt || $dt->format('Y-m-d') !== $body['data_ref']) {
                Response::error('Formato de data_ref inválido.', 422);
            }
            $campos[]  = 'data_ref = ?';
            $valores[] = $body['data_ref'];
        }

        if (isset($body['refeicao'])) {
            if (!in_array($body['refeicao'], ['almoco', 'jantar'], true)) {
                Response::error("Refeição inválida. Use 'almoco' ou 'jantar'.", 422);
            }
            $campos[]  = 'refeicao = ?';
            $valores[] = $body['refeicao'];
        }

        if (isset($body['ativo'])) {
            $campos[]  = 'ativo = ?';
            $valores[] = (int) $body['ativo'];
        }

        $this->db->beginTransaction();
        try {
            if (!empty($campos)) {
                $valores[] = $id;
                $this->db->prepare(
                    'UPDATE cardapio SET ' . implode(', ', $campos) . ' WHERE id = ?'
                )->execute($valores);
            }

            // Substituição completa dos itens (se enviados)
            if (isset($body['itens']) && is_array($body['itens'])) {
                $this->validarItens($body['itens']);
                $this->db->prepare('DELETE FROM itens_cardapio WHERE cardapio_id = ?')->execute([$id]);
                $this->inserirItens($id, $body['itens']);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('[AdminController::editarCardapio] ' . $e->getMessage());
            Response::error('Erro ao editar cardápio.', 500);
        }

        Response::success(['mensagem' => 'Cardápio atualizado com sucesso.']);
    }

    /**
     * DELETE /api/admin/cardapio/:id
     * Soft delete — apenas desativa o cardápio (ativo = 0).
     */
    public function excluirCardapio(int $id): never
    {
        AdminMiddleware::require();

        $stmt = $this->db->prepare('UPDATE cardapio SET ativo = 0 WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Cardápio não encontrado.', 404);
        }

        Response::success(['mensagem' => 'Cardápio desativado com sucesso.']);
    }

    // =========================================================
    //  UTILIZADORES
    // =========================================================

    /**
     * GET /api/admin/usuarios[?page=1&limit=20&search=nome]
     * Lista paginada de utilizadores.
     */
    public function listarUsuarios(): never
    {
        AdminMiddleware::require();

        $page   = max(1, (int) ($_GET['page']   ?? 1));
        $limit  = min(50, max(1, (int) ($_GET['limit']  ?? 20)));
        $search = trim($_GET['search'] ?? '');
        $offset = ($page - 1) * $limit;

        $where  = 'WHERE 1=1';
        $params = [];

        if ($search !== '') {
            $where   .= ' AND (nome LIKE ? OR email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $total = $this->db->prepare("SELECT COUNT(*) FROM usuarios {$where}");
        $total->execute($params);
        $totalRegistros = (int) $total->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT id, nome, email, curso, saldo, tipo, ativo, criado_em
             FROM   usuarios
             {$where}
             ORDER  BY nome
             LIMIT  ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        Response::success([
            'total'    => $totalRegistros,
            'pagina'   => $page,
            'limite'   => $limit,
            'usuarios' => $stmt->fetchAll(),
        ]);
    }

    /**
     * PUT /api/admin/usuarios/:id
     * Edita tipo (estudante/admin) e/ou estado (ativo) de um utilizador.
     *
     * Body: { "tipo": "admin", "ativo": 1 }
     */
    public function editarUsuario(int $id, array $body): never
    {
        AdminMiddleware::require();

        $campos  = [];
        $valores = [];

        if (isset($body['tipo'])) {
            if (!in_array($body['tipo'], ['estudante', 'admin'], true)) {
                Response::error("Tipo inválido. Use 'estudante' ou 'admin'.", 422);
            }
            $campos[]  = 'tipo = ?';
            $valores[] = $body['tipo'];
        }

        if (isset($body['ativo'])) {
            $campos[]  = 'ativo = ?';
            $valores[] = (int) (bool) $body['ativo'];
        }

        if (empty($campos)) {
            Response::error('Nenhum campo válido para atualizar (tipo, ativo).', 422);
        }

        $valores[] = $id;
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = ?'
        );
        $stmt->execute($valores);

        if ($stmt->rowCount() === 0) {
            Response::error('Utilizador não encontrado.', 404);
        }

        Response::success(['mensagem' => 'Utilizador atualizado com sucesso.']);
    }

    // =========================================================
    //  AVALIAÇÕES — Relatório
    // =========================================================

    /**
     * GET /api/admin/avaliacoes[?data=YYYY-MM-DD&refeicao=almoco]
     * Relatório agregado de avaliações.
     */
    public function relatorioAvaliacoes(): never
    {
        AdminMiddleware::require();

        $dataParam  = $_GET['data']     ?? null;
        $refeicao   = $_GET['refeicao'] ?? null;

        $where  = 'WHERE 1=1';
        $params = [];

        if ($dataParam) {
            $dt = \DateTime::createFromFormat('Y-m-d', $dataParam);
            if (!$dt || $dt->format('Y-m-d') !== $dataParam) {
                Response::error('Formato de data inválido.', 422);
            }
            $where   .= ' AND c.data_ref = ?';
            $params[] = $dataParam;
        }

        if ($refeicao) {
            if (!in_array($refeicao, ['almoco', 'jantar'], true)) {
                Response::error("Refeição inválida. Use 'almoco' ou 'jantar'.", 422);
            }
            $where   .= ' AND c.refeicao = ?';
            $params[] = $refeicao;
        }

        $stmt = $this->db->prepare(
            "SELECT c.data_ref,
                    c.refeicao,
                    COUNT(a.id)               AS total_avaliacoes,
                    ROUND(AVG(a.nota_sabor),   2) AS media_sabor,
                    ROUND(AVG(a.nota_temp),    2) AS media_temperatura,
                    ROUND(AVG(a.nota_atend),   2) AS media_atendimento,
                    ROUND(AVG(a.nota_limpeza), 2) AS media_limpeza,
                    ROUND(AVG(a.nota_geral),   2) AS media_geral
             FROM   cardapio c
             LEFT   JOIN avaliacoes a ON a.cardapio_id = c.id
             {$where}
             GROUP  BY c.id
             ORDER  BY c.data_ref DESC, c.refeicao"
        );
        $stmt->execute($params);
        $relatorio = $stmt->fetchAll();

        // Busca também os comentários recentes (até 20) para o filtro aplicado
        $stmtComentarios = $this->db->prepare(
            "SELECT u.nome AS autor, a.comentario, a.nota_geral, a.criado_em
             FROM   avaliacoes a
             JOIN   usuarios u ON u.id = a.usuario_id
             JOIN   cardapio c ON c.id = a.cardapio_id
             {$where}
               AND  a.comentario IS NOT NULL
               AND  a.comentario <> ''
             ORDER  BY a.criado_em DESC
             LIMIT  20"
        );
        $stmtComentarios->execute($params);

        Response::success([
            'resumo'      => $relatorio,
            'comentarios' => $stmtComentarios->fetchAll(),
        ]);
    }

    // =========================================================
    //  Helpers privados
    // =========================================================

    private function validarBodyCardapio(array $body): void
    {
        $dataRef  = $body['data_ref']  ?? '';
        $refeicao = $body['refeicao']  ?? '';
        $itens    = $body['itens']     ?? null;

        if (empty($dataRef)) {
            Response::error('O campo data_ref é obrigatório.', 422);
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $dataRef);
        if (!$dt || $dt->format('Y-m-d') !== $dataRef) {
            Response::error('Formato de data_ref inválido. Use YYYY-MM-DD.', 422);
        }

        if (!in_array($refeicao, ['almoco', 'jantar'], true)) {
            Response::error("Campo refeicao inválido. Use 'almoco' ou 'jantar'.", 422);
        }

        if (!is_array($itens) || empty($itens)) {
            Response::error('É necessário enviar pelo menos um item no cardápio.', 422);
        }

        $this->validarItens($itens);
    }

    private function validarItens(array $itens): void
    {
        $categoriasValidas = ['principal', 'guarnicao', 'arroz_feijao', 'salada', 'sobremesa', 'suco', 'outro'];

        foreach ($itens as $idx => $item) {
            $num = $idx + 1;

            if (empty($item['categoria'])) {
                Response::error("Item #{$num}: o campo 'categoria' é obrigatório.", 422);
            }
            if (!in_array($item['categoria'], $categoriasValidas, true)) {
                Response::error("Item #{$num}: categoria '{$item['categoria']}' inválida.", 422);
            }
            if (empty($item['descricao'])) {
                Response::error("Item #{$num}: o campo 'descricao' é obrigatório.", 422);
            }
            if (isset($item['calorias']) && !is_numeric($item['calorias'])) {
                Response::error("Item #{$num}: 'calorias' deve ser numérico.", 422);
            }
            if (isset($item['proteinas_g']) && !is_numeric($item['proteinas_g'])) {
                Response::error("Item #{$num}: 'proteinas_g' deve ser numérico.", 422);
            }
            if (isset($item['carboidratos_g']) && !is_numeric($item['carboidratos_g'])) {
                Response::error("Item #{$num}: 'carboidratos_g' deve ser numérico.", 422);
            }
        }
    }

    private function inserirItens(int $cardapioId, array $itens): void
    {
        $sql = 'INSERT INTO itens_cardapio
                    (cardapio_id, categoria, descricao, imagem_url, calorias, proteinas_g, carboidratos_g)
                VALUES (?, ?, ?, ?, ?, ?, ?)';

        $stmt = $this->db->prepare($sql);

        foreach ($itens as $item) {
            $stmt->execute([
                $cardapioId,
                $item['categoria'],
                $item['descricao'],
                $item['imagem_url']     ?? null,
                $item['calorias']       ?? null,
                $item['proteinas_g']    ?? null,
                $item['carboidratos_g'] ?? null,
            ]);
        }
    }
}