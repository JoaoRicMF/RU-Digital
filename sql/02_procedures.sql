-- =============================================================
--  RU Digital — UFCAT  |  Stored Procedures & Triggers
--  Arquivo: 02_procedures.sql
--  Execução: mysql -u root -p ru_digital < sql/02_procedures.sql
-- =============================================================

USE ru_digital;

DELIMITER $$

-- =============================================================
-- PROCEDURE: registrar_transacao
-- Garante atomicidade: atualiza saldo + insere transação em
-- uma única operação com lock pessimista (FOR UPDATE).
-- Impede que o saldo fique negativo em qualquer circunstância,
-- mesmo com requisições simultâneas.
-- =============================================================
DROP PROCEDURE IF EXISTS registrar_transacao $$

CREATE PROCEDURE registrar_transacao(
    IN  p_usuario_id  INT UNSIGNED,
    IN  p_tipo        VARCHAR(10),      -- 'recarga' | 'debito' | 'estorno'
    IN  p_valor       DECIMAL(10,2),
    IN  p_descricao   VARCHAR(200),
    IN  p_metodo      VARCHAR(50),
    OUT p_sucesso     TINYINT,
    OUT p_mensagem    VARCHAR(200),
    OUT p_saldo_atual DECIMAL(10,2)
)
BEGIN
    DECLARE v_saldo DECIMAL(10,2);

    -- Handler genérico: qualquer erro SQL faz ROLLBACK
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_sucesso     = 0;
        SET p_mensagem    = 'Erro interno. Operação revertida.';
        SET p_saldo_atual = NULL;
    END;

    START TRANSACTION;

    -- Lock exclusivo na linha do usuário: evita race conditions
    SELECT saldo
    INTO   v_saldo
    FROM   usuarios
    WHERE  id = p_usuario_id
      AND  ativo = 1
    FOR UPDATE;

    -- Usuário não encontrado ou inativo
    IF v_saldo IS NULL THEN
        ROLLBACK;
        SET p_sucesso     = 0;
        SET p_mensagem    = 'Usuário não encontrado ou inativo.';
        SET p_saldo_atual = NULL;

    -- Débito com saldo insuficiente
    ELSEIF p_tipo = 'debito' AND v_saldo < p_valor THEN
        ROLLBACK;
        SET p_sucesso     = 0;
        SET p_mensagem    = 'Saldo insuficiente.';
        SET p_saldo_atual = v_saldo;

    ELSE
        -- Atualiza saldo conforme o tipo
        IF p_tipo = 'debito' THEN
            UPDATE usuarios SET saldo = saldo - p_valor WHERE id = p_usuario_id;
        ELSE
            -- 'recarga' ou 'estorno'
            UPDATE usuarios SET saldo = saldo + p_valor WHERE id = p_usuario_id;
        END IF;

        -- Lê saldo atualizado para registrar snapshot
        SELECT saldo INTO v_saldo FROM usuarios WHERE id = p_usuario_id;

        -- Insere registro no extrato
        INSERT INTO transacoes (usuario_id, tipo, valor, descricao, metodo_pgto, saldo_apos)
        VALUES (p_usuario_id, p_tipo, p_valor, p_descricao, p_metodo, v_saldo);

        COMMIT;

        SET p_sucesso     = 1;
        SET p_mensagem    = 'Operação realizada com sucesso.';
        SET p_saldo_atual = v_saldo;
    END IF;
END $$


-- =============================================================
-- TRIGGER: antes de qualquer UPDATE no saldo
-- Segunda camada de defesa: impede saldo negativo mesmo que
-- alguém chame UPDATE direto, sem usar a procedure.
-- =============================================================
DROP TRIGGER IF EXISTS tgr_saldo_nao_negativo $$

CREATE TRIGGER tgr_saldo_nao_negativo
BEFORE UPDATE ON usuarios
FOR EACH ROW
BEGIN
    IF NEW.saldo < 0.00 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'TRIGGER: Operação recusada — saldo não pode ser negativo.';
    END IF;
END $$


-- =============================================================
-- PROCEDURE: limpar_tentativas_login
-- Deve ser chamada via Event Scheduler (cron do MySQL) a cada
-- 15 minutos para remover registros expirados.
-- =============================================================
DROP PROCEDURE IF EXISTS limpar_tentativas_login $$

CREATE PROCEDURE limpar_tentativas_login()
BEGIN
    DELETE FROM login_attempts
    WHERE ultima_em < NOW() - INTERVAL 15 MINUTE;
END $$


-- =============================================================
-- EVENT: limpa tentativas de login automaticamente
-- Requer que o Event Scheduler esteja ativo:
--   SET GLOBAL event_scheduler = ON;
-- =============================================================
DROP EVENT IF EXISTS evt_limpar_login_attempts $$

CREATE EVENT evt_limpar_login_attempts
ON SCHEDULE EVERY 15 MINUTE
DO
    CALL limpar_tentativas_login() $$


DELIMITER ;


-- =============================================================
-- DADOS INICIAIS (seed)
-- =============================================================

-- Cardápio de exemplo
INSERT IGNORE INTO cardapio (data_ref, refeicao) VALUES (CURDATE(), 'almoco');

INSERT IGNORE INTO itens_cardapio (cardapio_id, categoria, descricao, calorias, proteinas_g, carboidratos_g)
VALUES
    (1, 'principal',    'Frango Grelhado',                   180, 35.0, 0.0),
    (1, 'guarnicao',    'Purê de Batata',                    120, 2.5,  25.0),
    (1, 'arroz_feijao', 'Arroz Branco, Integral e Feijão',   310, 12.0, 65.0),
    (1, 'salada',       'Alface, Tomate e Milho',             40,  1.5,  8.0),
    (1, 'sobremesa',    'Laranja ou Gelatina',                60,  0.5, 15.0),
    (1, 'suco',         'Suco de Caju',                       80,  0.5, 20.0);

-- Usuário de teste (senha: 123456)
-- Hash gerado com: password_hash('123456', PASSWORD_BCRYPT, ['cost' => 12])
INSERT IGNORE INTO usuarios (matricula, nome, email, senha_hash, curso, saldo)
VALUES (
    '2021001',
    'João Ricardo',
    'joao@ufcat.edu.br',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Ciência da Computação',
    24.00
);
