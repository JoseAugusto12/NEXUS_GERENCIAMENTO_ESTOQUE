-- ============================================================
-- NEXUS - MCX Inventario  |  Schema completo (fresh install)
-- ============================================================

CREATE DATABASE IF NOT EXISTS mcx_inventario
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE mcx_inventario;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS anexos_nf;
DROP TABLE IF EXISTS historico_ativos;
DROP TABLE IF EXISTS compra_itens;
DROP TABLE IF EXISTS compras;
DROP TABLE IF EXISTS requisicoes;
DROP TABLE IF EXISTS hiring_requests;
DROP TABLE IF EXISTS purchase_request_audit;
DROP TABLE IF EXISTS purchase_requests;
DROP TABLE IF EXISTS inventory_scans;
DROP TABLE IF EXISTS inventory_balances;
DROP TABLE IF EXISTS inventory_sessions;
DROP TABLE IF EXISTS movimentacoes;
DROP TABLE IF EXISTS ativos;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS usuarios;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- usuarios
--   perfil: administrador | usuario | gestor | diretoria | coordenacao_ti | financeiro | rh
-- ----------------------------------------------------------
CREATE TABLE usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(120)  NOT NULL,
    email         VARCHAR(160)  NOT NULL UNIQUE,
    senha         VARCHAR(255)  NOT NULL,
    perfil        ENUM('administrador','usuario','gestor','diretoria','coordenacao_ti','financeiro','rh') NOT NULL DEFAULT 'usuario',
    cpf           VARCHAR(14),
    matricula     VARCHAR(20),
    departamento  VARCHAR(120),
    funcao        VARCHAR(120),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuarios_perfil (perfil),
    INDEX idx_usuarios_email  (email)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- categorias
--   tipo: ti | limpeza | escritorio
--     ti         -> somente administrador pode gerenciar
--     limpeza    -> administrador + gestor
--     escritorio -> administrador + gestor
-- ----------------------------------------------------------
CREATE TABLE categorias (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL UNIQUE,
    tipo        ENUM('ti','limpeza','escritorio') NOT NULL DEFAULT 'ti',
    descricao   TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_categorias_nome (nome),
    INDEX idx_categorias_tipo (tipo)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- ativos
-- ----------------------------------------------------------
CREATE TABLE ativos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    codigo           VARCHAR(30)  NOT NULL UNIQUE,
    categoria_id     INT NULL,
    nome             VARCHAR(140) NOT NULL,
    marca            VARCHAR(100),
    modelo           VARCHAR(100),
    fornecedor       VARCHAR(140),
    nota_fiscal      VARCHAR(80),
    valor_compra     DECIMAL(12,2) NOT NULL DEFAULT 0,
    data_compra      DATE NULL,
    garantia         DATE NULL,
    quantidade_atual INT          NOT NULL DEFAULT 0,
    estoque_minimo   INT          NOT NULL DEFAULT 1,
    estoque_maximo   INT          NOT NULL DEFAULT 0,
    foto             VARCHAR(255),
    estado           ENUM('Otimo','Bom','Medio','Ruim','Manutencao','Inutilizado') DEFAULT 'Bom',
    localizacao      VARCHAR(140),
    observacoes      TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ativos_codigo    (codigo),
    INDEX idx_ativos_nome      (nome),
    INDEX idx_ativos_categoria (categoria_id),
    INDEX idx_ativos_estoque   (quantidade_atual, estoque_minimo),

    CONSTRAINT fk_ativos_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- compras
-- ----------------------------------------------------------
CREATE TABLE compras (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    cnpj          VARCHAR(20)  NOT NULL,
    razao_social  VARCHAR(180) NOT NULL,
    chave_acesso  VARCHAR(80),
    numero_nf     VARCHAR(40)  NOT NULL,
    data_emissao  DATE NULL,
    data_entrada  DATE NOT NULL,
    observacao    TEXT,
    valor_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
    usuario_id    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_compras_nf           (numero_nf),
    INDEX idx_compras_data_entrada (data_entrada),
    INDEX idx_compras_usuario      (usuario_id),

    CONSTRAINT fk_compras_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- compra_itens
-- ----------------------------------------------------------
CREATE TABLE compra_itens (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    compra_id      INT NOT NULL,
    ativo_id       INT NOT NULL,
    quantidade     INT          NOT NULL,
    valor_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
    valor_total    DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_compra_itens_compra (compra_id),
    INDEX idx_compra_itens_ativo  (ativo_id),

    CONSTRAINT fk_compra_itens_compra
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    CONSTRAINT fk_compra_itens_ativo
        FOREIGN KEY (ativo_id)  REFERENCES ativos(id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- movimentacoes
-- ----------------------------------------------------------
CREATE TABLE movimentacoes (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    ativo_id             INT NOT NULL,
    tipo                 ENUM('Entrada','Saida','Ajuste') NOT NULL,
    quantidade           INT NOT NULL DEFAULT 0,
    colaborador_recebe   VARCHAR(120),
    funcionario_entrega  VARCHAR(120),
    observacao           TEXT,
    origem               VARCHAR(50) NULL,
    solicitacao_id       INT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_movimentacoes_ativo       (ativo_id),
    INDEX idx_movimentacoes_tipo        (tipo),
    INDEX idx_movimentacoes_created_at  (created_at),
    INDEX idx_movimentacoes_colaborador (colaborador_recebe),
    INDEX idx_movimentacoes_tipo_colab  (tipo, colaborador_recebe(100)),
    INDEX idx_movimentacoes_solicitacao (solicitacao_id),

    CONSTRAINT fk_movimentacoes_ativo
        FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- historico_ativos
-- ----------------------------------------------------------
CREATE TABLE historico_ativos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    ativo_id         INT NOT NULL,
    movimentacao_id  INT NULL,
    compra_id        INT NULL,
    tipo             VARCHAR(60) NOT NULL,
    quantidade       INT         NOT NULL DEFAULT 0,
    descricao        TEXT,
    usuario_id       INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_historico_ativo        (ativo_id),
    INDEX idx_historico_movimentacao (movimentacao_id),
    INDEX idx_historico_compra       (compra_id),
    INDEX idx_historico_tipo         (tipo),
    INDEX idx_historico_created_at   (created_at),

    CONSTRAINT fk_historico_ativo
        FOREIGN KEY (ativo_id)        REFERENCES ativos(id)        ON DELETE CASCADE,
    CONSTRAINT fk_historico_movimentacao
        FOREIGN KEY (movimentacao_id) REFERENCES movimentacoes(id) ON DELETE SET NULL,
    CONSTRAINT fk_historico_compra
        FOREIGN KEY (compra_id)       REFERENCES compras(id)       ON DELETE SET NULL,
    CONSTRAINT fk_historico_usuario
        FOREIGN KEY (usuario_id)      REFERENCES usuarios(id)      ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- anexos_nf
-- ----------------------------------------------------------
CREATE TABLE anexos_nf (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    compra_id      INT NOT NULL,
    nome_original  VARCHAR(255) NOT NULL,
    caminho        VARCHAR(255) NOT NULL,
    tipo           ENUM('pdf','xml') NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_anexos_compra (compra_id),
    INDEX idx_anexos_tipo   (tipo),

    CONSTRAINT fk_anexos_compra
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- requisicoes
-- ----------------------------------------------------------
CREATE TABLE requisicoes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    codigo_requisicao   VARCHAR(20) NOT NULL UNIQUE,
    usuario_id          INT NOT NULL,
    ativo_id            INT NULL,
    matricula           VARCHAR(20)  NOT NULL,
    cpf                 VARCHAR(14)  NOT NULL,
    nome                VARCHAR(120) NOT NULL,
    departamento        VARCHAR(120) NOT NULL,
    funcao              VARCHAR(120) NOT NULL,
    descricao           TEXT NOT NULL,
    status              ENUM('Em análise','Em processo de compra','Dependente','Negado','Concluído')
                            NOT NULL DEFAULT 'Em análise',
    aprovado_por        VARCHAR(120),
    data_solicitacao    DATE NOT NULL,
    hora_solicitacao    TIME NOT NULL,
    data_finalizacao    DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_requisicoes_usuario (usuario_id),
    INDEX idx_requisicoes_status  (status),
    INDEX idx_requisicoes_data    (data_solicitacao, hora_solicitacao),
    INDEX idx_requisicoes_codigo  (codigo_requisicao),
    INDEX idx_requisicoes_ativo   (ativo_id),

    CONSTRAINT fk_requisicoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_requisicoes_ativo
        FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ----------------------------------------------------------
-- hiring_requests: RH -> Administrador
-- ----------------------------------------------------------
CREATE TABLE hiring_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position VARCHAR(180) NOT NULL,
    department VARCHAR(120) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    candidate_names JSON NULL,
    data_inicio DATE NULL,
    admission_document VARCHAR(255) NOT NULL,
    status ENUM('Em análise','Aprovada','Recusada') NOT NULL DEFAULT 'Em análise',
    created_by INT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hiring_status (status),
    INDEX idx_hiring_created_by (created_by),
    CONSTRAINT fk_hiring_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_hiring_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- purchase_requests: Administrador -> Coordenacao TI -> Financeiro
-- ----------------------------------------------------------
CREATE TABLE purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(30) NOT NULL UNIQUE,
    requester_id INT NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    quantity INT NOT NULL,
    purchase_url VARCHAR(500) NULL,
    priority ENUM('Baixa','Normal','Alta','Urgente') NOT NULL DEFAULT 'Normal',
    justification TEXT NOT NULL,
    status ENUM('PENDING_IT','PENDING_FINANCE','PURCHASED','REJECTED') NOT NULL DEFAULT 'PENDING_IT',
    it_by INT NULL,
    it_at DATETIME NULL,
    it_comments TEXT NULL,
    finance_by INT NULL,
    finance_at DATETIME NULL,
    finance_comments TEXT NULL,
    supplier VARCHAR(180) NULL,
    invoice_number VARCHAR(100) NULL,
    purchase_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_purchase_request_status (status),
    INDEX idx_purchase_request_requester (requester_id),
    CONSTRAINT fk_purchase_request_requester FOREIGN KEY (requester_id) REFERENCES usuarios(id),
    CONSTRAINT fk_purchase_request_it FOREIGN KEY (it_by) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_request_finance FOREIGN KEY (finance_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchase_request_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_request_id INT NOT NULL,
    user_id INT NULL,
    event VARCHAR(120) NOT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purchase_audit_request (purchase_request_id),
    CONSTRAINT fk_purchase_audit_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_audit_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- inventory: cada leitura e registrada, inclusive repetida
-- ----------------------------------------------------------
CREATE TABLE inventory_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    started_by INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('OPEN','FINISHED') NOT NULL DEFAULT 'OPEN',
    finished_by INT NULL,
    finished_at DATETIME NULL,
    INDEX idx_inventory_session_status (status),
    CONSTRAINT fk_inventory_started_by FOREIGN KEY (started_by) REFERENCES usuarios(id),
    CONSTRAINT fk_inventory_finished_by FOREIGN KEY (finished_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inventory_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_session_id INT NOT NULL,
    ativo_id INT NOT NULL,
    opening_stock INT NOT NULL,
    counted INT NOT NULL DEFAULT 0,
    balance INT NOT NULL,
    UNIQUE KEY uq_inventory_balance (inventory_session_id, ativo_id),
    CONSTRAINT fk_inventory_balance_session FOREIGN KEY (inventory_session_id) REFERENCES inventory_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_balance_asset FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE inventory_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_session_id INT NOT NULL,
    ativo_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_scan_session_asset (inventory_session_id, ativo_id),
    CONSTRAINT fk_inventory_scan_session FOREIGN KEY (inventory_session_id) REFERENCES inventory_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_scan_asset FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_scan_user FOREIGN KEY (scanned_by) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ============================================================
-- Dados iniciais
-- ============================================================

-- Usuarios: senha padrao = "password"
INSERT INTO usuarios (nome, email, senha, perfil, cpf, matricula, departamento, funcao) VALUES
('Administrador', 'admin@mcx.local',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'administrador', '000.000.000-00', '0001', 'TI',        'Administrador'),
('Joao Silva',    'joao.silva@mcx.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'usuario',        '111.111.111-11', '1001', 'Operacoes', 'Analista'),
('Gestor',       'gestor@mcx.local',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gestor',         '222.222.222-22', '2001', 'Facilities','Gestor'),
('Diretoria',    'diretor@mcx.local',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'diretoria',      '333.333.333-33', '3001', 'Diretoria', 'Diretoria'),
('Coordenacao TI','coordenacao.ti@mcx.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordenacao_ti','444.444.444-44', '4001', 'TI', 'Coordenador'),
('Financeiro',   'financeiro@mcx.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'financeiro',     '555.555.555-55', '5001', 'Financeiro', 'Analista Financeiro'),
('RH',           'rh@mcx.local',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'rh',             '666.666.666-66', '6001', 'RH', 'Analista RH');

-- Categorias
INSERT INTO categorias (nome, tipo, descricao) VALUES
('Notebook',   'ti',         'Computadores portateis'),
('Monitor',    'ti',         'Monitores corporativos'),
('Perifericos','ti',         'Mouse, teclado, headset'),
('Rede',       'ti',         'Switches e Access Points'),
('Impressora', 'ti',         'Equipamentos de impressao'),
('Limpeza',    'limpeza',    'Material de limpeza geral'),
('Escritorio', 'escritorio', 'Papelaria e material de escritorio');

-- Ativo de exemplo (TI)
INSERT INTO ativos (
    codigo, categoria_id, nome, marca, modelo, fornecedor, nota_fiscal,
    valor_compra, data_compra, garantia, quantidade_atual, estoque_minimo,
    estado, localizacao, observacoes
) VALUES
(
    'MCX-000001', 1, 'Notebook Dell Latitude', 'Dell', '5440', 'Dell Brasil', 'NF1001',
    6200.00, '2025-01-10', '2028-01-10', 5, 1,
    'Otimo', 'Estoque TI', 'Notebook corporativo para entrega a colaboradores.'
);

-- Movimentacao e historico iniciais
INSERT INTO movimentacoes (ativo_id, tipo, quantidade, colaborador_recebe, funcionario_entrega, observacao)
    VALUES (1, 'Entrada', 5, NULL, 'Administrador', 'Estoque inicial importado.');

INSERT INTO historico_ativos (ativo_id, movimentacao_id, tipo, quantidade, descricao, usuario_id)
    VALUES (1, 1, 'Entrada', 5, 'Estoque inicial cadastrado.', 1);

-- Requisicao de exemplo
INSERT INTO requisicoes (
    codigo_requisicao, usuario_id, matricula, cpf, nome, departamento, funcao,
    descricao, status, aprovado_por, data_solicitacao, hora_solicitacao
) VALUES (
    'REQ-000001', 2, '1001', '111.111.111-11', 'Joao Silva', 'Operacoes', 'Analista',
    'Solicitacao de notebook para colaborador.',
    'Em análise', NULL, CURDATE(), CURTIME()
);
