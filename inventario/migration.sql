-- =============================================================
-- NEXUS — Migration Script (merged, non-destructive, safe to re-run)
-- Apply to existing databases running the previous schema version.
-- Gerado a partir da junção de múltiplos migration.sql
-- =============================================================

USE nexus_inventario;

-- =============================================================
-- PARTE 1 — Extensão de categorias, perfis, seeds iniciais
-- =============================================================

-- ------------------------------------------------------------
-- 1. Extend categorias with a "tipo" column for role scoping.
--    This maps each category to a department bucket (ti, limpeza,
--    escritorio, …) so the "gestor" role can be restricted
--    to only "limpeza" and "escritorio" assets.
-- ------------------------------------------------------------
ALTER TABLE categorias
    ADD COLUMN IF NOT EXISTS tipo ENUM('ti','limpeza','escritorio','outro') NOT NULL DEFAULT 'ti'
    AFTER descricao;

-- Index for fast WHERE tipo IN (…) queries used by scope helpers
ALTER TABLE categorias
    ADD INDEX IF NOT EXISTS idx_categorias_tipo (tipo);

-- Backfill seed data: map the pre-existing categories by name.
UPDATE categorias SET tipo = 'ti'         WHERE nome IN ('Notebook','Monitor','Perifericos','Rede','Impressora');
UPDATE categorias SET tipo = 'limpeza'    WHERE nome IN ('Limpeza','Material de Limpeza','Higiene e Limpeza');
UPDATE categorias SET tipo = 'escritorio' WHERE nome IN ('Escritorio','Material de Escritorio','Papelaria');
-- Any others keep the default 'ti' (safest: excluded from gestor scope).

-- ------------------------------------------------------------
-- 2. Add new perfil values: 'gestor' and 'diretoria'.
--    MySQL ENUM alteration requires redefining the full set.
-- ------------------------------------------------------------
ALTER TABLE usuarios
    MODIFY COLUMN perfil
        ENUM('administrador','usuario','gestor','diretoria','coordenacao_ti','financeiro','rh')
        NOT NULL DEFAULT 'usuario';

-- ------------------------------------------------------------
-- 3. Seed users for the two new roles (only if absent).
-- ------------------------------------------------------------
INSERT IGNORE INTO usuarios (nome, email, senha, perfil, cpf, matricula, departamento, funcao) VALUES
('Gestor Limpeza',   'gestor@nexus.local',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'gestor',    '222.222.222-22', '2001', 'Limpeza',  'Gestor'),
('Diretoria Board',  'diretoria@nexus.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'diretoria', '333.333.333-33', '3001', 'Diretoria','Diretor');

-- ------------------------------------------------------------
-- 4. Seed Limpeza and Escritorio categories if missing.
-- ------------------------------------------------------------
INSERT IGNORE INTO categorias (nome, descricao, tipo) VALUES
('Limpeza',             'Materiais de limpeza e higiene',    'limpeza'),
('Material de Escritorio', 'Papelaria, canetas e suprimentos', 'escritorio');

-- =============================================================
-- Default password for all seed users above: "password"
-- Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- Change immediately in production.
-- =============================================================


-- =============================================================
-- PARTE 2 — Compatibilidade NEXUS: fluxo de compras e inventário
-- (obs: este bloco veio duplicado nos arquivos originais;
--  mantido uma única vez aqui pois é idempotente/redundante)
-- =============================================================
-- NEXUS: compatibilidade para bases existentes.
-- Instalacoes novas devem importar somente database.sql.

UPDATE usuarios SET nome = 'Gestor', funcao = 'Gestor' WHERE email = 'gestor@mcx.local';

-- O fluxo atual e Administrador -> Coordenacao TI -> Financeiro.
-- Recrie as tabelas de fluxo em bases legadas para remover etapas e campos obsoletos.
DROP TABLE IF EXISTS purchase_request_audit;
DROP TABLE IF EXISTS purchase_requests;
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
  it_by INT NULL, it_at DATETIME NULL, it_comments TEXT NULL,
  finance_by INT NULL, finance_at DATETIME NULL, finance_comments TEXT NULL,
  supplier VARCHAR(180) NULL, invoice_number VARCHAR(100) NULL, purchase_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_purchase_request_status (status), INDEX idx_purchase_request_requester (requester_id),
  CONSTRAINT fk_purchase_request_requester FOREIGN KEY (requester_id) REFERENCES usuarios(id),
  CONSTRAINT fk_purchase_request_it FOREIGN KEY (it_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_request_finance FOREIGN KEY (finance_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE purchase_request_audit (
  id INT AUTO_INCREMENT PRIMARY KEY, purchase_request_id INT NOT NULL, user_id INT NULL,
  event VARCHAR(120) NOT NULL, comments TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_purchase_audit_request (purchase_request_id),
  CONSTRAINT fk_purchase_audit_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_audit_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Inventario permite leituras repetidas: nao ha chave unica por ativo/sessao.
DROP TABLE IF EXISTS inventory_scans;
CREATE TABLE inventory_scans (
  id INT AUTO_INCREMENT PRIMARY KEY, inventory_session_id INT NOT NULL, ativo_id INT NOT NULL, scanned_by INT NOT NULL,
  scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_inventory_scan_session_asset (inventory_session_id, ativo_id),
  CONSTRAINT fk_inventory_scan_session FOREIGN KEY (inventory_session_id) REFERENCES inventory_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_scan_asset FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_scan_user FOREIGN KEY (scanned_by) REFERENCES usuarios(id)
) ENGINE=InnoDB;


-- =============================================================
-- PARTE 3 — Criação da tabela hiring_requests
-- =============================================================

CREATE TABLE IF NOT EXISTS hiring_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  position VARCHAR(180) NOT NULL,
  department VARCHAR(120) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  candidate_names JSON NULL,
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


-- =============================================================
-- PARTE 4 — Ajustes em hiring_requests (candidate_names + status)
-- =============================================================

SET @has_candidate_names := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'hiring_requests'
    AND COLUMN_NAME = 'candidate_names'
);
SET @add_candidate_names := IF(
  @has_candidate_names = 0,
  'ALTER TABLE hiring_requests ADD COLUMN candidate_names JSON NULL AFTER quantity',
  'SELECT 1'
);
PREPARE add_candidate_names_stmt FROM @add_candidate_names;
EXECUTE add_candidate_names_stmt;
DEALLOCATE PREPARE add_candidate_names_stmt;

UPDATE hiring_requests SET status = 'Aprovada' WHERE status = 'Em processo de compra';
UPDATE hiring_requests SET status = 'Recusada' WHERE status = 'Negado';

ALTER TABLE hiring_requests
  MODIFY status ENUM('Em análise','Aprovada','Recusada') NOT NULL DEFAULT 'Em análise';

-- Integra requisicoes de equipamento com o estoque e a movimentacao de saida.
ALTER TABLE requisicoes ADD COLUMN IF NOT EXISTS ativo_id INT NULL AFTER usuario_id;
ALTER TABLE requisicoes ADD INDEX IF NOT EXISTS idx_requisicoes_ativo (ativo_id);
SET @has_fk_requisicoes_ativo := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'requisicoes' AND CONSTRAINT_NAME = 'fk_requisicoes_ativo');
SET @add_fk_requisicoes_ativo := IF(@has_fk_requisicoes_ativo = 0, 'ALTER TABLE requisicoes ADD CONSTRAINT fk_requisicoes_ativo FOREIGN KEY (ativo_id) REFERENCES ativos(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE add_fk_requisicoes_ativo_stmt FROM @add_fk_requisicoes_ativo;
EXECUTE add_fk_requisicoes_ativo_stmt;
DEALLOCATE PREPARE add_fk_requisicoes_ativo_stmt;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS origem VARCHAR(50) NULL AFTER observacao;
ALTER TABLE movimentacoes ADD COLUMN IF NOT EXISTS solicitacao_id INT NULL AFTER origem;
ALTER TABLE movimentacoes ADD INDEX IF NOT EXISTS idx_movimentacoes_solicitacao (solicitacao_id);
