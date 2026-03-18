<?php
/**
 * blacklist_setup.php
 * Executa UMA VEZ para criar a estrutura do sistema de Blacklist.
 * Acesse via browser autenticado como ADM ou rode manualmente via CLI.
 */
require __DIR__ . '/conexao.php';

$erros = [];
$ok    = [];

// ── 1. Colunas de fingerprint na tabela usuarios ──────────────────────────────
$fingerprints = [
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_user_agent   TEXT          DEFAULT NULL COMMENT 'User-Agent do navegador'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_idioma       VARCHAR(20)   DEFAULT NULL COMMENT 'navigator.language'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_timezone     VARCHAR(80)   DEFAULT NULL COMMENT 'Intl.DateTimeFormat timezone'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_resolucao    VARCHAR(20)   DEFAULT NULL COMMENT 'screen.width x screen.height'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_cores_tela   TINYINT       DEFAULT NULL COMMENT 'screen.colorDepth'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_plataforma   VARCHAR(60)   DEFAULT NULL COMMENT 'navigator.platform'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_plugins      TEXT          DEFAULT NULL COMMENT 'navigator.plugins JSON'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_canvas_hash  VARCHAR(64)   DEFAULT NULL COMMENT 'Canvas fingerprint hash'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_webgl_hash   VARCHAR(64)   DEFAULT NULL COMMENT 'WebGL fingerprint hash'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_audio_hash   VARCHAR(64)   DEFAULT NULL COMMENT 'AudioContext fingerprint hash'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_fonts        TEXT          DEFAULT NULL COMMENT 'Fontes detectadas JSON'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_touch        TINYINT(1)    DEFAULT 0   COMMENT 'Suporte a touch'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_do_not_track VARCHAR(5)    DEFAULT NULL COMMENT 'DNT header'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_ip_publico   VARCHAR(60)   DEFAULT NULL COMMENT 'IP no momento do cadastro (redundante p/ BL)'",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fp_coletado_em  DATETIME      DEFAULT NULL COMMENT 'Quando o fingerprint foi coletado'",
];

foreach ($fingerprints as $sql) {
    if ($conexao->query($sql)) {
        $ok[] = $sql;
    } else {
        $erros[] = $conexao->error . ' | SQL: ' . $sql;
    }
}

// ── 2. Tabela blacklist ───────────────────────────────────────────────────────
$sqlBL = "
CREATE TABLE IF NOT EXISTS blacklist (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(200)    NOT NULL,
    rgpm            VARCHAR(30)     NOT NULL,
    discord         VARCHAR(80)     DEFAULT NULL,
    ip_publico      VARCHAR(60)     DEFAULT NULL,

    -- fingerprint snapshot no momento do banimento
    fp_user_agent   TEXT            DEFAULT NULL,
    fp_idioma       VARCHAR(20)     DEFAULT NULL,
    fp_timezone     VARCHAR(80)     DEFAULT NULL,
    fp_resolucao    VARCHAR(20)     DEFAULT NULL,
    fp_plataforma   VARCHAR(60)     DEFAULT NULL,
    fp_canvas_hash  VARCHAR(64)     DEFAULT NULL,
    fp_webgl_hash   VARCHAR(64)     DEFAULT NULL,
    fp_audio_hash   VARCHAR(64)     DEFAULT NULL,
    fp_fonts        TEXT            DEFAULT NULL,

    motivo_tipo     ENUM('texto','pdf') NOT NULL DEFAULT 'texto',
    motivo_texto    TEXT            DEFAULT NULL,
    motivo_pdf      LONGBLOB        DEFAULT NULL,
    motivo_pdf_nome VARCHAR(200)    DEFAULT NULL,

    tempo           ENUM('permanente','temporario') NOT NULL DEFAULT 'permanente',
    expiracao       DATETIME        DEFAULT NULL   COMMENT 'Preenchido quando tempo=temporario',

    adicionado_por  VARCHAR(200)    DEFAULT NULL   COMMENT 'Nome do admin que baniu',
    adicionado_em   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_rgpm    (rgpm),
    INDEX idx_discord (discord(40)),
    INDEX idx_ip      (ip_publico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if ($conexao->query($sqlBL)) {
    $ok[] = 'Tabela blacklist criada/verificada.';
} else {
    $erros[] = 'Tabela blacklist: ' . $conexao->error;
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/plain; charset=utf-8');
echo "=== BLACKLIST SETUP ===\n\n";
echo "OK (" . count($ok) . "):\n";
foreach ($ok as $m) echo "  ✓ " . (strlen($m) > 90 ? substr($m, 0, 87) . '...' : $m) . "\n";
if ($erros) {
    echo "\nERROS (" . count($erros) . "):\n";
    foreach ($erros as $e) echo "  ✗ $e\n";
} else {
    echo "\nTudo configurado com sucesso!\n";
    echo "Você pode apagar este arquivo agora.\n";
}