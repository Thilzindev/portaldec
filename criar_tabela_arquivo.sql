-- ============================================================
-- Tabela de arquivo de pagamentos
-- Executar UMA VEZ no banco antes de usar a funcionalidade
-- ============================================================
CREATE TABLE IF NOT EXISTS `pagamentos_arquivo` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `tipo`            ENUM('inscricao','multa') NOT NULL DEFAULT 'inscricao',
  `usuario_id_orig` INT(11)      DEFAULT NULL COMMENT 'ID original do usuário (pode não existir mais)',
  `nome`            VARCHAR(255) NOT NULL,
  `rgpm`            VARCHAR(50)  NOT NULL,
  `discord`         VARCHAR(100) DEFAULT NULL,
  `curso`           VARCHAR(255) DEFAULT NULL,
  `valor`           DECIMAL(10,2) DEFAULT 0.00,
  `comprovante`     LONGBLOB     DEFAULT NULL COMMENT 'Imagem do comprovante',
  `mime_type`       VARCHAR(60)  DEFAULT 'image/jpeg',
  `status_original` VARCHAR(50)  DEFAULT NULL COMMENT 'Status que tinha quando arquivado',
  `motivo_exclusao` TEXT         DEFAULT NULL COMMENT 'Motivo informado pelo admin ao excluir',
  `arquivado_por`   VARCHAR(255) DEFAULT NULL COMMENT 'RGPM do admin que arquivou',
  `data_pagamento`  DATETIME     DEFAULT NULL COMMENT 'Data original do pagamento',
  `data_arquivado`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `pagamentos_arquivo` ADD PRIMARY KEY (`id`);
ALTER TABLE `pagamentos_arquivo` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;
