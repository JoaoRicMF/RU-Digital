-- =============================================================
--  RU Digital — UFCAT  |  Schema MySQL
--  Arquivo: 01_schema.sql
--  Execução: mysql -u root -p < sql/01_schema.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS ru_digital
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ru_digital;

-- -------------------------------------------------------------
-- 1. USUARIOS
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    matricula      VARCHAR(20)      NOT NULL,
    nome           VARCHAR(120)     NOT NULL,
    email          VARCHAR(150)     NOT NULL,
    senha_hash     VARCHAR(255)     NOT NULL,   -- bcrypt, custo 12
    curso          VARCHAR(100)     DEFAULT NULL,
    saldo          DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    ativo          TINYINT(1)       NOT NULL DEFAULT 1,
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  DATETIME         DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_email      (email),
    UNIQUE KEY uq_matricula  (matricula),
    CONSTRAINT chk_saldo_nao_negativo CHECK (saldo >= 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 2. TRANSACOES
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transacoes (
    id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    usuario_id     INT UNSIGNED     NOT NULL,
    tipo           ENUM('recarga','debito','estorno') NOT NULL,
    valor          DECIMAL(10,2)    NOT NULL,
    descricao      VARCHAR(200)     DEFAULT NULL,
    metodo_pgto    VARCHAR(50)      DEFAULT NULL,  -- 'pix', 'cartao', 'app'
    ref_externa    VARCHAR(100)     DEFAULT NULL,  -- ID gateway/PIX
    saldo_apos     DECIMAL(10,2)    NOT NULL,      -- snapshot do saldo após op.
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    CONSTRAINT fk_transacao_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_valor_positivo CHECK (valor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_trans_usuario_data ON transacoes (usuario_id, criado_em DESC);


-- -------------------------------------------------------------
-- 3. CARDAPIO
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cardapio (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    data_ref       DATE             NOT NULL,
    refeicao       ENUM('almoco','jantar','cafe') NOT NULL,
    ativo          TINYINT(1)       NOT NULL DEFAULT 1,
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cardapio_data_ref (data_ref, refeicao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itens_cardapio (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    cardapio_id    INT UNSIGNED     NOT NULL,
    categoria      ENUM('principal','guarnicao','arroz_feijao','salada','sobremesa','suco') NOT NULL,
    descricao      VARCHAR(200)     NOT NULL,
    calorias       INT UNSIGNED     DEFAULT NULL,
    proteinas_g    DECIMAL(6,2)     DEFAULT NULL,
    carboidratos_g DECIMAL(6,2)     DEFAULT NULL,

    PRIMARY KEY (id),
    CONSTRAINT fk_item_cardapio
        FOREIGN KEY (cardapio_id) REFERENCES cardapio(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 4. AVALIACOES
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS avaliacoes (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    usuario_id     INT UNSIGNED     NOT NULL,
    cardapio_id    INT UNSIGNED     NOT NULL,
    nota_sabor     TINYINT UNSIGNED DEFAULT NULL,
    nota_temp      TINYINT UNSIGNED DEFAULT NULL,
    nota_atend     TINYINT UNSIGNED DEFAULT NULL,
    nota_limpeza   TINYINT UNSIGNED DEFAULT NULL,
    nota_geral     TINYINT UNSIGNED DEFAULT NULL,
    comentario     TEXT             DEFAULT NULL,
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Um usuário só pode avaliar cada refeição uma vez
    UNIQUE KEY uq_avaliacao_unica (usuario_id, cardapio_id),

    CONSTRAINT fk_aval_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_aval_cardapio
        FOREIGN KEY (cardapio_id) REFERENCES cardapio(id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT chk_nota_sabor   CHECK (nota_sabor   BETWEEN 1 AND 5),
    CONSTRAINT chk_nota_temp    CHECK (nota_temp    BETWEEN 1 AND 5),
    CONSTRAINT chk_nota_atend   CHECK (nota_atend   BETWEEN 1 AND 5),
    CONSTRAINT chk_nota_limpeza CHECK (nota_limpeza BETWEEN 1 AND 5),
    CONSTRAINT chk_nota_geral   CHECK (nota_geral   BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 5. TOKENS DE RECUPERACAO DE SENHA
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tokens_recuperacao (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    usuario_id     INT UNSIGNED     NOT NULL,
    token          CHAR(64)         NOT NULL,  -- SHA-256 hex
    expira_em      DATETIME         NOT NULL,
    usado          TINYINT(1)       NOT NULL DEFAULT 0,
    criado_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    CONSTRAINT fk_token_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 6. TENTATIVAS DE LOGIN (rate limiting)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    ip             VARCHAR(45)      NOT NULL,
    tentativas     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    bloqueado_ate  DATETIME         DEFAULT NULL,
    ultima_em      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
