<?php
// ── AÇÕES DE MULTA PARA O ALUNO (sem dependências do painel_admin) ────────────
// Incluído via require_once dentro do bloco ajax do painel_aluno.php
// $acao, $uid e $conexao já estão definidos pelo painel_aluno.php

// ── CRIAR TABELA SE NÃO EXISTIR ──────────────────────────────────────────────
$conexao->query("CREATE TABLE IF NOT EXISTS multas (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id          INT UNSIGNED NOT NULL,
    nome_aluno          VARCHAR(200) NOT NULL,
    rgpm_aluno          VARCHAR(30)  NOT NULL,
    discord_aluno       VARCHAR(80)  DEFAULT NULL,
    curso_aluno         VARCHAR(200) DEFAULT NULL,
    valor               DECIMAL(15,2) NOT NULL DEFAULT 0,
    link_adv            TEXT NOT NULL,
    prazo_horas         INT NOT NULL DEFAULT 24,
    prazo_expira        DATETIME NOT NULL,
    status              ENUM('pendente','aguardando_validacao','paga','nao_paga') NOT NULL DEFAULT 'pendente',
    comprovante_multa   LONGBLOB DEFAULT NULL,
    mime_comprovante    VARCHAR(60) DEFAULT NULL,
    aplicada_por        VARCHAR(200) DEFAULT NULL,
    aplicada_por_rgpm   VARCHAR(30)  DEFAULT NULL,
    validada_por        VARCHAR(200) DEFAULT NULL,
    validada_por_rgpm   VARCHAR(30)  DEFAULT NULL,
    validada_em         DATETIME DEFAULT NULL,
    observacao          TEXT DEFAULT NULL,
    aplicada_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processada          TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_uid'");
if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_uid (usuario_id)");
$idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_status'");
if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_status (status)");
$idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_expira'");
if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_expira (prazo_expira)");

// ── HELPER: processa multas vencidas ─────────────────────────────────────────
if (!function_exists('multa_processarTodas')) {
    function multa_processarTodas(mysqli $db): void {
        $agora = date('Y-m-d H:i:s');
        $q = $db->query("SELECT * FROM multas WHERE status IN ('pendente','aguardando_validacao') AND prazo_expira<='{$agora}' AND processada=0");
        if (!$q) return;
        while ($row = $q->fetch_assoc()) {
            $id  = intval($row['id']);
            $uid = intval($row['usuario_id']);
            $nome   = $db->real_escape_string($row['nome_aluno']);
            $rgpm   = $db->real_escape_string($row['rgpm_aluno']);
            $disc   = $db->real_escape_string($row['discord_aluno'] ?? '');
            $motivo = $db->real_escape_string("Multa #{$id} nao paga no prazo.");
            $admin  = $db->real_escape_string($row['aplicada_por'] ?? 'Sistema');
            $expira = date('Y-m-d H:i:s', strtotime('+30 days'));

            // 1. Marca multa como não paga
            $db->query("UPDATE multas SET status='nao_paga',processada=1 WHERE id={$id}");

            // 2. Adiciona à blacklist PERMANENTE (se ainda não estiver)
            $chkBl  = $db->query("SELECT id FROM blacklist WHERE rgpm='{$rgpm}' LIMIT 1");
            if ($chkBl && $chkBl->num_rows === 0) {
                $db->query("INSERT INTO blacklist (nome,rgpm,discord,motivo_tipo,motivo_texto,tempo,expiracao,adicionado_por)
                            VALUES ('{$nome}','{$rgpm}','{$disc}','texto','{$motivo}','permanente',NULL,'{$admin}')");
            }

            // 3. Limpa dados mas mantém conta — bloqueia o usuário
            $db->query("DELETE FROM pagamentos_pendentes WHERE usuario_id={$uid}");
            $db->query("UPDATE usuarios SET meus_cursos='', status='Bloqueado', tags='[]' WHERE id={$uid}");
        }
    }
}

// ── LISTAR MULTAS DO ALUNO ────────────────────────────────────────────────────
if ($acao === 'multa_listar_aluno') {
    if (!$uid) { responderJSON(['sucesso' => false, 'erro' => 'Nao autorizado']); }
    multa_processarTodas($conexao);
    $res   = $conexao->query("SELECT id,valor,link_adv,prazo_horas,prazo_expira,status,aplicada_em,curso_aluno
                               FROM multas
                               WHERE usuario_id={$uid} AND status IN ('pendente','aguardando_validacao')
                               ORDER BY aplicada_em DESC");
    $lista = [];
    if ($res) while ($r = $res->fetch_assoc()) $lista[] = $r;
    responderJSON(['sucesso' => true, 'multas' => $lista]);
}

// ── ENVIAR COMPROVANTE ────────────────────────────────────────────────────────
if ($acao === 'multa_enviar_comprovante') {
    $multaId = intval($_POST['multa_id'] ?? 0);
    if (!$uid || !$multaId) { responderJSON(['sucesso' => false, 'erro' => 'Dados invalidos']); }

    $row   = $conexao->query("SELECT id,prazo_expira FROM multas WHERE id={$multaId} AND usuario_id={$uid} AND status='pendente' LIMIT 1");
    $multa = $row ? $row->fetch_assoc() : null;
    if (!$multa)  { responderJSON(['sucesso' => false, 'erro' => 'Multa nao encontrada']); }
    if (strtotime($multa['prazo_expira']) <= time()) { responderJSON(['sucesso' => false, 'erro' => 'Prazo encerrado']); }
    if (empty($_FILES['comprovante_multa']['tmp_name'])) { responderJSON(['sucesso' => false, 'erro' => 'Arquivo nao recebido']); }

    $gdImg = @imagecreatefromstring(file_get_contents($_FILES['comprovante_multa']['tmp_name']));
    if (!$gdImg) { responderJSON(['sucesso' => false, 'erro' => 'Formato de imagem invalido']); }

    $origW = imagesx($gdImg); $origH = imagesy($gdImg);
    if ($origW > 900) {
        $novaH = intval($origH * 900 / $origW);
        $novo  = imagecreatetruecolor(900, $novaH);
        imagecopyresampled($novo, $gdImg, 0, 0, 0, 0, 900, $novaH, $origW, $origH);
        imagedestroy($gdImg); $gdImg = $novo;
    }
    $imgData = null;
    foreach ([80, 65, 50, 38] as $q) {
        ob_start(); imagejpeg($gdImg, null, $q); $t = ob_get_clean();
        $imgData = $t; if (strlen($t) <= 500000) break;
    }
    imagedestroy($gdImg);
    if (!$imgData) { responderJSON(['sucesso' => false, 'erro' => 'Falha ao processar imagem']); }

    $mime = 'image/jpeg';
    $iesc = $conexao->real_escape_string($imgData);
    if ($conexao->query("UPDATE multas SET status='aguardando_validacao',comprovante_multa='{$iesc}',mime_comprovante='{$mime}' WHERE id={$multaId} AND usuario_id={$uid}")) {
        responderJSON(['sucesso' => true]);
    } else {
        responderJSON(['sucesso' => false, 'erro' => $conexao->error]);
    }
}