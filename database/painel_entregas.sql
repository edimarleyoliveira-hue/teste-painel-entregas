-- Painel de Entregas: estrutura e dados iniciais
-- Importe este arquivo pelo phpMyAdmin (aba Importar). Ele cria o banco painel_entregas.

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS painel_entregas;
CREATE DATABASE painel_entregas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE painel_entregas;


CREATE TABLE empresas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NULL UNIQUE COMMENT 'ID da empresa: letras e números maiúsculos',
  tipo_documento ENUM('CNPJ', 'CPF') NOT NULL DEFAULT 'CNPJ',
  documento VARCHAR(18) NOT NULL UNIQUE COMMENT 'CNPJ 00.000.000/0000-00 ou CPF 000.000.000-00',
  razao_social VARCHAR(150) NOT NULL,
  nome_fantasia VARCHAR(150) NULL,
  regime ENUM('Simples Nacional', 'Lucro Presumido', 'Lucro Real') NOT NULL,
  cep CHAR(9) NOT NULL,
  logradouro VARCHAR(150) NOT NULL,
  numero VARCHAR(20) NOT NULL,
  complemento VARCHAR(80) NULL,
  bairro VARCHAR(80) NOT NULL,
  cidade VARCHAR(80) NOT NULL,
  uf CHAR(2) NOT NULL,
  contato_nome VARCHAR(100) NULL,
  contato_telefone VARCHAR(20) NULL,
  contato_email VARCHAR(150) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuração de recorrência (usada em obrigacoes como padrão e em empresa_obrigacoes como regra da empresa):
-- recorrencia: diaria | semanal | mensal | trimestral | semestral | anual
-- somente_dias_uteis: só na diária (1 = só dias úteis)
-- dia_semana: só na semanal (1 = segunda ... 7 = domingo)
-- dia_mes: só na mensal (1 a 31; em meses mais curtos vale o último dia)
-- data_inicio: trimestral/semestral/anual (primeira cobrança; repete a cada 3/6/12 meses)
-- ajuste_vencimento: o que fazer se o vencimento cair em fim de semana ou feriado nacional
CREATE TABLE obrigacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL UNIQUE,
  departamento ENUM('Fiscal', 'Pessoal', 'Contábil') NOT NULL,
  recorrencia ENUM('diaria', 'semanal', 'mensal', 'trimestral', 'semestral', 'anual') NOT NULL DEFAULT 'mensal',
  somente_dias_uteis TINYINT(1) NOT NULL DEFAULT 1,
  dia_semana TINYINT NULL,
  dia_mes TINYINT NULL,
  data_inicio DATE NULL,
  ajuste_vencimento ENUM('postergar', 'antecipar', 'manter') NOT NULL DEFAULT 'postergar'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obrigações de cada empresa. A configuração começa como cópia do padrão da obrigação
-- e pode ser alterada só para a empresa: o que está aqui é a regra que vale.
CREATE TABLE empresa_obrigacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  obrigacao_id INT NOT NULL,
  recorrencia ENUM('diaria', 'semanal', 'mensal', 'trimestral', 'semestral', 'anual') NOT NULL,
  somente_dias_uteis TINYINT(1) NOT NULL DEFAULT 1,
  dia_semana TINYINT NULL,
  dia_mes TINYINT NULL,
  data_inicio DATE NULL,
  ajuste_vencimento ENUM('postergar', 'antecipar', 'manter') NOT NULL DEFAULT 'postergar',
  UNIQUE KEY uq_empresa_obrigacao (empresa_id, obrigacao_id),
  CONSTRAINT fk_eo_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_eo_obrigacao FOREIGN KEY (obrigacao_id) REFERENCES obrigacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obrigações diárias e semanais têm várias entregas na mesma competência,
-- por isso o vencimento também faz parte da chave única.
CREATE TABLE entregas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  obrigacao_id INT NOT NULL,
  competencia CHAR(7) NOT NULL COMMENT 'Formato AAAA-MM',
  vencimento DATE NOT NULL,
  data_entrega DATE NULL,
  UNIQUE KEY uq_entrega (empresa_id, obrigacao_id, competencia, vencimento),
  CONSTRAINT fk_entrega_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_entrega_obrigacao FOREIGN KEY (obrigacao_id) REFERENCES obrigacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Histórico das ações nas entregas: quem marcou como entregue ou voltou para pendente, e quando.
-- Competência e vencimento são copiados para o histórico não se perder se a entrega for regerada.
CREATE TABLE historico_entregas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entrega_id INT NOT NULL,
  empresa_id INT NOT NULL,
  obrigacao_id INT NOT NULL,
  competencia CHAR(7) NOT NULL,
  vencimento DATE NOT NULL,
  acao ENUM('entregue', 'pendente') NOT NULL COMMENT 'entregue = marcou como entregue; pendente = desfez a entrega',
  data_entrega DATE NULL COMMENT 'Data de entrega registrada (só quando acao = entregue)',
  usuario VARCHAR(100) NOT NULL,
  realizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_historico_obrigacao (obrigacao_id, realizado_em),
  CONSTRAINT fk_historico_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_historico_obrigacao FOREIGN KEY (obrigacao_id) REFERENCES obrigacoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Datas de cadastro relativas ao dia da importação, para o filtro por período ter o que mostrar.
INSERT INTO empresas (codigo, tipo_documento, documento, razao_social, nome_fantasia, regime, cep, logradouro, numero, complemento, bairro, cidade, uf, contato_nome, contato_telefone, contato_email, criado_em) VALUES
  ('MERC01', 'CNPJ', '12.345.678/0001-95', 'Mercado Bom Preço Ltda', 'Bom Preço', 'Simples Nacional', '30130-010', 'Avenida Afonso Pena', '1500', NULL, 'Centro', 'Belo Horizonte', 'MG', 'Marcos Lima', '(31) 3222-1000', 'financeiro@bompreco.example', DATE_SUB(NOW(), INTERVAL 400 DAY)),
  ('PADA01', 'CNPJ', '23.456.789/0001-95', 'Padaria D''Ávila Ltda', 'Padaria D''Ávila', 'Simples Nacional', '30140-071', 'Rua da Bahia', '820', 'Loja 2', 'Lourdes', 'Belo Horizonte', 'MG', 'Ana D''Ávila', '(31) 98877-1234', 'ana@padariadavila.example', DATE_SUB(NOW(), INTERVAL 300 DAY)),
  ('CONS01', 'CNPJ', '34.567.890/0001-30', 'Construtora Horizonte S.A.', 'Horizonte', 'Lucro Real', '01310-100', 'Avenida Paulista', '1000', '12º andar', 'Bela Vista', 'São Paulo', 'SP', 'Ricardo Souza', '(11) 3030-4040', 'contabil@horizonte.example', DATE_SUB(NOW(), INTERVAL 250 DAY)),
  ('CLIN01', 'CNPJ', '45.678.901/0001-75', 'Clínica Vida Plena Ltda', 'Vida Plena', 'Lucro Presumido', '20040-002', 'Avenida Rio Branco', '156', 'Sala 801', 'Centro', 'Rio de Janeiro', 'RJ', 'Dra. Paula Reis', '(21) 2233-4455', 'adm@vidaplena.example', DATE_SUB(NOW(), INTERVAL 180 DAY)),
  ('AUTO01', 'CNPJ', '56.789.012/0001-00', 'Auto Peças Minas Ltda', 'Minas Peças', 'Lucro Presumido', '32010-000', 'Avenida João César de Oliveira', '2300', NULL, 'Eldorado', 'Contagem', 'MG', 'José Ferreira', '(31) 3391-2020', 'jose@minaspecas.example', DATE_SUB(NOW(), INTERVAL 120 DAY)),
  ('TECH01', 'CNPJ', '67.890.123/0001-16', 'Tech Serra Sistemas Ltda', 'Tech Serra', 'Simples Nacional', '30310-030', 'Rua Professor Moraes', '476', NULL, 'Savassi', 'Belo Horizonte', 'MG', 'Camila Rocha', '(31) 99123-4567', 'camila@techserra.example', DATE_SUB(NOW(), INTERVAL 60 DAY)),
  ('TRAN01', 'CNPJ', '78.901.234/0001-05', 'Transportadora Rota 40 Ltda', 'Rota 40', 'Lucro Real', '74003-010', 'Avenida Goiás', '400', NULL, 'Setor Central', 'Goiânia', 'GO', 'Luiz Martins', '(62) 3212-0040', 'fiscal@rota40.example', DATE_SUB(NOW(), INTERVAL 30 DAY)),
  ('CAFE01', 'CNPJ', '89.012.345/0001-79', 'Café & Prosa ME', 'Café & Prosa', 'Simples Nacional', '36010-000', 'Rua Halfeld', '300', NULL, 'Centro', 'Juiz de Fora', 'MG', 'Beatriz Alves', '(32) 3215-0300', 'contato@cafeprosa.example', DATE_SUB(NOW(), INTERVAL 10 DAY)),
  ('PROD01', 'CPF', '123.456.789-09', 'João da Silva', 'Sítio Boa Vista', 'Simples Nacional', '37200-000', 'Estrada Municipal', 'S/N', 'Km 5', 'Zona Rural', 'Lavras', 'MG', 'João da Silva', '(35) 99876-5432', 'joao.sitio@example.com', DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO obrigacoes (nome, departamento, recorrencia, somente_dias_uteis, dia_semana, dia_mes, data_inicio, ajuste_vencimento) VALUES
  ('DAS', 'Fiscal', 'mensal', 1, NULL, 20, NULL, 'antecipar'),
  ('EFD ICMS/IPI', 'Fiscal', 'mensal', 1, NULL, 20, NULL, 'postergar'),
  ('DCTFWeb', 'Fiscal', 'mensal', 1, NULL, 15, NULL, 'antecipar'),
  ('FGTS Digital', 'Pessoal', 'mensal', 1, NULL, 20, NULL, 'antecipar'),
  ('eSocial - Folha', 'Pessoal', 'mensal', 1, NULL, 15, NULL, 'antecipar'),
  ('Balancete Mensal', 'Contábil', 'mensal', 1, NULL, 10, NULL, 'postergar'),
  ('ECD', 'Contábil', 'anual', 1, NULL, NULL, CONCAT(YEAR(CURDATE()), '-05-31'), 'antecipar');

-- As datas são relativas ao dia em que o banco é criado,
-- para que sempre existam entregas pendentes, atrasadas e entregues.
INSERT INTO entregas (empresa_id, obrigacao_id, competencia, vencimento, data_entrega) VALUES
  (1, 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_SUB(CURDATE(), INTERVAL 17 DAY)),
  (1, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
  (1, 5, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL),
  (1, 1, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL),
  (2, 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), NULL),
  (2, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 13 DAY)),
  (2, 5, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), NULL),
  (3, 2, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 5 DAY), NULL),
  (3, 3, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
  (3, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), NULL),
  (3, 6, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 6 DAY), NULL),
  (3, 7, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 12 MONTH), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 25 DAY), NULL),
  (4, 3, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 9 DAY)),
  (4, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 14 DAY)),
  (4, 5, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL),
  (4, 6, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 2 DAY), NULL),
  (5, 2, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
  (5, 3, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 9 DAY), NULL),
  (5, 4, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 20 DAY), NULL),
  (6, 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY)),
  (6, 5, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL),
  (6, 1, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL),
  (7, 2, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 5 DAY), NULL),
  (7, 3, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 11 DAY)),
  (7, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_SUB(CURDATE(), INTERVAL 12 DAY)),
  (7, 6, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 6 DAY), NULL),
  (8, 1, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), NULL),
  (8, 4, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m'), DATE_SUB(CURDATE(), INTERVAL 12 DAY), NULL),
  (8, 5, DATE_FORMAT(CURDATE(), '%Y-%m'), CURDATE(), NULL),
  (8, 1, DATE_FORMAT(CURDATE(), '%Y-%m'), DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL);

-- Vincula às empresas as obrigações que já têm entregas, com a configuração padrão de cada obrigação.
INSERT INTO empresa_obrigacoes (empresa_id, obrigacao_id, recorrencia, somente_dias_uteis, dia_semana, dia_mes, data_inicio, ajuste_vencimento)
SELECT DISTINCT e.empresa_id, e.obrigacao_id, o.recorrencia, o.somente_dias_uteis, o.dia_semana, o.dia_mes, o.data_inicio, o.ajuste_vencimento
  FROM entregas e
  JOIN obrigacoes o ON o.id = e.obrigacao_id;
