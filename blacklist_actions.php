<?php
// ── BUSCAR ALUNO — ABSOLUTAMENTE PRIMEIRO, SEM NADA ANTES ────────────────────
if ($acao === 'multa_buscar_aluno') {
    exigirNivel(NIVEL_ADM);
    $termo = trim($_POST['termo'] ?? '');
    if (!$termo) {
        echo json_encode(['sucesso' => false, 'erro' => 'Informe o termo']);
        exit;
    }
    $like = '%' . $conexao->real_escape_string($termo) . '%';
    $sql  = "SELECT id, nome, rgpm, discord, meus_cursos, status, nivel FROM usuarios WHERE rgpm LIKE '{$like}' OR discord LIKE '{$like}' OR nome LIKE '{$like}' LIMIT 10";
    $res  = $conexao->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro BD: ' . $conexao->error]);
        exit;
    }
    echo json_encode(['sucesso' => true, 'usuarios' => $rows]);
    exit;
}

// ── CRIAR TABELA MULTAS (só para ações que precisam) ──────────────────────────
$acoesTabela = ['aplicar_multa','multa_listar_aluno','multa_enviar_comprovante',
                'multa_ver_comprovante','multa_validar','multa_negar',
                'multa_listar_pendentes_adm','multa_logs'];

if (in_array($acao, $acoesTabela, true)) {
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

    // Índices — cria apenas se não existirem
    $idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_uid'");
    if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_uid (usuario_id)");
    $idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_status'");
    if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_status (status)");
    $idxCheck = $conexao->query("SHOW INDEX FROM multas WHERE Key_name='idx_m_expira'");
    if ($idxCheck && $idxCheck->num_rows === 0) $conexao->query("ALTER TABLE multas ADD INDEX idx_m_expira (prazo_expira)");
}

// ── HELPER: processa multas vencidas ─────────────────────────────────────────
if (!function_exists('multa_processarTodas')) {
    function multa_processarTodas(mysqli $db): void {
        $agora = date('Y-m-d H:i:s');
        $q = $db->query("SELECT * FROM multas WHERE status IN ('pendente','aguardando_validacao') AND prazo_expira<='{$agora}' AND processada=0");
        if (!$q) return;
        while ($row = $q->fetch_assoc()) {
            $id  = intval($row['id']);
            $uid = intval($row['usuario_id']);
            $db->query("UPDATE multas SET status='nao_paga',processada=1 WHERE id={$id}");
            $db->query("UPDATE usuarios SET meus_cursos='',status='Bloqueado',tags='[]' WHERE id={$uid}");
            $db->query("DELETE FROM pagamentos_pendentes WHERE usuario_id={$uid}");
            $nome   = $db->real_escape_string($row['nome_aluno']);
            $rgpm   = $db->real_escape_string($row['rgpm_aluno']);
            $disc   = $db->real_escape_string($row['discord_aluno'] ?? '');
            $motivo = $db->real_escape_string("Multa #{$id} nao paga no prazo.");
            $admin  = $db->real_escape_string($row['aplicada_por'] ?? 'Sistema');
            $chk    = $db->query("SELECT id FROM blacklist WHERE rgpm='{$rgpm}' LIMIT 1");
            if ($chk && $chk->num_rows === 0) {
                // Blacklist de multa é PERMANENTE — só admin pode remover manualmente
                $db->query("INSERT INTO blacklist (nome,rgpm,discord,motivo_tipo,motivo_texto,tempo,expiracao,adicionado_por) VALUES ('{$nome}','{$rgpm}','{$disc}','texto','{$motivo}','permanente',NULL,'{$admin}')");
            }
        }
    }
}

// ── APLICAR MULTA ─────────────────────────────────────────────────────────────
if ($acao === 'aplicar_multa') {
    exigirNivel(NIVEL_ADM);
    $uid       = intval($_POST['usuario_id'] ?? 0);
    $valorStr  = trim($_POST['valor'] ?? '0');
    $valorNum  = str_replace(['.','R$',' '], ['','',''], $valorStr);
    $valorNum  = str_replace(',', '.', $valorNum);
    $valor     = floatval($valorNum);
    $linkAdv   = trim($_POST['link_adv'] ?? '');
    $prazoH    = intval($_POST['prazo_horas'] ?? 24);
    $adminNome = $conexao->real_escape_string($_SESSION['usuario'] ?? 'Desconhecido');
    $adminRgpm = $conexao->real_escape_string($_SESSION['rgpm'] ?? '');

    if (!$uid)       { echo json_encode(['sucesso' => false, 'erro' => 'Selecione o aluno']); exit; }
    if ($valor <= 0) { echo json_encode(['sucesso' => false, 'erro' => 'Valor invalido']); exit; }
    if (!$linkAdv)   { echo json_encode(['sucesso' => false, 'erro' => 'Informe o link da advertencia']); exit; }

    $expira = $prazoH === 0
        ? date('Y-m-d H:i:s', strtotime('+1 minute'))
        : date('Y-m-d H:i:s', strtotime("+{$prazoH} hours"));

    $uRow  = $conexao->query("SELECT nome,rgpm,discord,meus_cursos FROM usuarios WHERE id={$uid} LIMIT 1");
    $aluno = $uRow ? $uRow->fetch_assoc() : null;
    if (!$aluno) { echo json_encode(['sucesso' => false, 'erro' => "Usuario id={$uid} nao encontrado"]); exit; }

    $nome  = $conexao->real_escape_string($aluno['nome']);
    $rgpm  = $conexao->real_escape_string($aluno['rgpm']);
    $disc  = $conexao->real_escape_string($aluno['discord'] ?? '');
    $curso = $conexao->real_escape_string($aluno['meus_cursos'] ?? '');
    $ladv  = $conexao->real_escape_string($linkAdv);

    $sql = "INSERT INTO multas (usuario_id,nome_aluno,rgpm_aluno,discord_aluno,curso_aluno,valor,link_adv,prazo_horas,prazo_expira,status,aplicada_por,aplicada_por_rgpm,aplicada_em)
            VALUES ({$uid},'{$nome}','{$rgpm}','{$disc}','{$curso}',{$valor},'{$ladv}',{$prazoH},'{$expira}','pendente','{$adminNome}','{$adminRgpm}',NOW())";

    if ($conexao->query($sql)) {
        echo json_encode(['sucesso' => true, 'id' => $conexao->insert_id]);
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'Erro DB: ' . $conexao->error]);
    }
    exit;
}

// ── LISTAR MULTAS DO ALUNO ────────────────────────────────────────────────────
if ($acao === 'multa_listar_aluno') {
    $uid = intval($_SESSION['id'] ?? 0);
    if (!$uid) { echo json_encode(['sucesso' => false, 'erro' => 'Nao autorizado']); exit; }
    multa_processarTodas($conexao);
    $res   = $conexao->query("SELECT id,valor,link_adv,prazo_horas,prazo_expira,status,aplicada_em,curso_aluno FROM multas WHERE usuario_id={$uid} AND status IN ('pendente','aguardando_validacao') ORDER BY aplicada_em DESC");
    $lista = [];
    if ($res) while ($r = $res->fetch_assoc()) $lista[] = $r;
    echo json_encode(['sucesso' => true, 'multas' => $lista]);
    exit;
}

// ── ENVIAR COMPROVANTE ────────────────────────────────────────────────────────
if ($acao === 'multa_enviar_comprovante') {
    $uid     = intval($_SESSION['id'] ?? 0);
    $multaId = intval($_POST['multa_id'] ?? 0);
    if (!$uid || !$multaId) { echo json_encode(['sucesso' => false, 'erro' => 'Dados invalidos']); exit; }

    $row   = $conexao->query("SELECT id,prazo_expira FROM multas WHERE id={$multaId} AND usuario_id={$uid} AND status='pendente' LIMIT 1");
    $multa = $row ? $row->fetch_assoc() : null;
    if (!$multa) { echo json_encode(['sucesso' => false, 'erro' => 'Multa nao encontrada']); exit; }
    if (strtotime($multa['prazo_expira']) <= time()) { echo json_encode(['sucesso' => false, 'erro' => 'Prazo encerrado']); exit; }
    if (empty($_FILES['comprovante_multa']['tmp_name'])) { echo json_encode(['sucesso' => false, 'erro' => 'Arquivo nao recebido']); exit; }

    $gdImg = @imagecreatefromstring(file_get_contents($_FILES['comprovante_multa']['tmp_name']));
    if (!$gdImg) { echo json_encode(['sucesso' => false, 'erro' => 'Formato invalido']); exit; }
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
    if (!$imgData) { echo json_encode(['sucesso' => false, 'erro' => 'Falha ao processar imagem']); exit; }

    $mime = 'image/jpeg';
    $iesc = $conexao->real_escape_string($imgData);
    if ($conexao->query("UPDATE multas SET status='aguardando_validacao',comprovante_multa='{$iesc}',mime_comprovante='{$mime}' WHERE id={$multaId} AND usuario_id={$uid}")) {
        echo json_encode(['sucesso' => true]);
    } else {
        echo json_encode(['sucesso' => false, 'erro' => $conexao->error]);
    }
    exit;
}

// ── VER COMPROVANTE ───────────────────────────────────────────────────────────
if ($acao === 'multa_ver_comprovante') {
    exigirNivel(NIVEL_ADM);
    $id  = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['sucesso' => false, 'erro' => 'ID invalido']); exit; }
    $res = $conexao->query("SELECT comprovante_multa,mime_comprovante,LENGTH(comprovante_multa) as tam FROM multas WHERE id={$id}");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !$row['tam']) { echo json_encode(['sucesso' => false, 'erro' => 'Comprovante nao encontrado']); exit; }
    echo json_encode(['sucesso' => true, 'imagem' => base64_encode($row['comprovante_multa']), 'mime' => $row['mime_comprovante']]);
    exit;
}

// ── VALIDAR ───────────────────────────────────────────────────────────────────
if ($acao === 'multa_validar') {
    exigirNivel(NIVEL_ADM);
    $id        = intval($_POST['id'] ?? 0);
    $adminNome = $conexao->real_escape_string($_SESSION['usuario'] ?? 'Desconhecido');
    $adminRgpm = $conexao->real_escape_string($_SESSION['rgpm'] ?? '');
    $agora     = date('Y-m-d H:i:s');
    if (!$id) { echo json_encode(['sucesso' => false, 'erro' => 'ID invalido']); exit; }

    // Busca dados da multa antes de validar
    $mRow = $conexao->query("SELECT usuario_id,nome_aluno,rgpm_aluno,discord_aluno,curso_aluno,valor FROM multas WHERE id={$id} AND status='aguardando_validacao' LIMIT 1");
    $multa = $mRow ? $mRow->fetch_assoc() : null;
    if (!$multa) { echo json_encode(['sucesso' => false, 'erro' => 'Nao encontrada ou sem comprovante']); exit; }

    // Marca como paga
    $conexao->query("UPDATE multas SET status='paga',validada_por='{$adminNome}',validada_por_rgpm='{$adminRgpm}',validada_em='{$agora}',processada=1 WHERE id={$id}");

    // Registra o pagamento da multa no histórico de pagamentos do curso
    $uidM   = intval($multa['usuario_id']);
    $nomeM  = $conexao->real_escape_string($multa['nome_aluno']);
    $rgpmM  = $conexao->real_escape_string($multa['rgpm_aluno']);
    $discM  = $conexao->real_escape_string($multa['discord_aluno'] ?? '');
    $cursoM = $conexao->real_escape_string($multa['curso_aluno'] ?? '');
    $valorM = floatval($multa['valor']);
    // Insere como pagamento validado de multa (tipo especial)
    $conexao->query("INSERT INTO pagamentos_pendentes (usuario_id,nome,rgpm,discord,curso,comprovante,mime_type,data_envio,status,notificado,tipo_pagamento,valor_multa)
        SELECT {$uidM},'{$nomeM}','{$rgpmM}','{$discM}','{$cursoM}',comprovante_multa,mime_comprovante,'{$agora}','validado',1,'multa',{$valorM}
        FROM multas WHERE id={$id} LIMIT 1");

    echo json_encode(['sucesso' => true]);
    exit;
}

// ── NEGAR ─────────────────────────────────────────────────────────────────────
if ($acao === 'multa_negar') {
    exigirNivel(NIVEL_ADM);
    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['sucesso' => false, 'erro' => 'ID invalido']); exit; }
    $conexao->query("UPDATE multas SET status='pendente',comprovante_multa=NULL,mime_comprovante=NULL WHERE id={$id} AND status='aguardando_validacao'");
    echo $conexao->affected_rows > 0
        ? json_encode(['sucesso' => true])
        : json_encode(['sucesso' => false, 'erro' => 'Nao encontrada']);
    exit;
}

// ── LISTAR PENDENTES ADM ──────────────────────────────────────────────────────
if ($acao === 'multa_listar_pendentes_adm') {
    exigirNivel(NIVEL_ADM);
    multa_processarTodas($conexao);
    $busca = trim($_POST['busca'] ?? '');
    $where = $busca !== '' ? "AND (nome_aluno LIKE '%" . $conexao->real_escape_string($busca) . "%' OR rgpm_aluno LIKE '%" . $conexao->real_escape_string($busca) . "%')" : '';
    $res   = $conexao->query("SELECT id,usuario_id,nome_aluno,rgpm_aluno,discord_aluno,curso_aluno,valor,link_adv,prazo_horas,prazo_expira,status,aplicada_por,aplicada_por_rgpm,aplicada_em FROM multas WHERE status IN ('pendente','aguardando_validacao') {$where} ORDER BY aplicada_em DESC");
    $lista = [];
    if ($res) while ($r = $res->fetch_assoc()) $lista[] = $r;
    echo json_encode(['sucesso' => true, 'multas' => $lista, 'total' => count($lista)]);
    exit;
}

// ── DELETAR MULTA PENDENTE (cancela sem aplicar) ─────────────────────────────
if ($acao === 'multa_deletar') {
    exigirNivel(NIVEL_ADM);
    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']); exit; }
    // Só permite deletar multas ainda ativas (pendente ou aguardando_validacao)
    $chk = $conexao->query("SELECT id FROM multas WHERE id={$id} AND status IN ('pendente','aguardando_validacao') LIMIT 1");
    if (!$chk || $chk->num_rows === 0) { echo json_encode(['sucesso' => false, 'erro' => 'Multa não encontrada ou já encerrada']); exit; }
    $conexao->query("DELETE FROM multas WHERE id={$id}");
    echo $conexao->affected_rows > 0
        ? json_encode(['sucesso' => true])
        : json_encode(['sucesso' => false, 'erro' => 'Não foi possível deletar']);
    exit;
}

// ── DELETAR LOG DE MULTA (paga ou não paga) ───────────────────────────────────
if ($acao === 'multa_log_deletar') {
    exigirNivel(NIVEL_ADM);
    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']); exit; }
    // Só permite deletar logs (status paga ou nao_paga)
    $chk = $conexao->query("SELECT id, status, usuario_id, valor FROM multas WHERE id={$id} AND status IN ('paga','nao_paga') LIMIT 1");
    if (!$chk || $chk->num_rows === 0) { echo json_encode(['sucesso' => false, 'erro' => 'Log não encontrado']); exit; }
    $logRow = $chk->fetch_assoc();
    $eraPaga = ($logRow['status'] === 'paga');
    $uid = intval($logRow['usuario_id']);

    $conexao->query("DELETE FROM multas WHERE id={$id}");
    if ($conexao->affected_rows > 0) {
        // Se o log era pago, remover o pagamento de multa correspondente em pagamentos_pendentes
        if ($eraPaga && $uid) {
            $conexao->query("DELETE FROM pagamentos_pendentes WHERE usuario_id={$uid} AND tipo_pagamento='multa' ORDER BY id DESC LIMIT 1");
        }
        echo json_encode(['sucesso' => true, 'era_paga' => $eraPaga]);
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível deletar']);
    }
    exit;
}

// ── APAGAR TODOS OS LOGS ─────────────────────────────────────────────────────
if ($acao === 'multa_logs_apagar_todos') {
    exigirNivel(NIVEL_ADM);
    $res = $conexao->query("SELECT COUNT(*) as total FROM multas WHERE status IN ('paga','nao_paga')");
    $total = intval($res ? $res->fetch_assoc()['total'] : 0);
    // Remover pagamentos de multas vinculados antes de deletar os logs
    $conexao->query("DELETE FROM pagamentos_pendentes WHERE tipo_pagamento='multa'");
    $conexao->query("DELETE FROM multas WHERE status IN ('paga','nao_paga')");
    echo json_encode(['sucesso' => true, 'total' => $total]);
    exit;
}

// ── LOGS ──────────────────────────────────────────────────────────────────────
if ($acao === 'multa_logs') {
    exigirNivel(NIVEL_ADM);
    multa_processarTodas($conexao);
    $busca = trim($_POST['busca'] ?? '');
    $where = $busca !== '' ? "AND (nome_aluno LIKE '%" . $conexao->real_escape_string($busca) . "%' OR rgpm_aluno LIKE '%" . $conexao->real_escape_string($busca) . "%')" : '';
    $res   = $conexao->query("SELECT id,usuario_id,nome_aluno,rgpm_aluno,discord_aluno,curso_aluno,valor,link_adv,prazo_horas,prazo_expira,status,aplicada_por,aplicada_por_rgpm,aplicada_em,validada_por,validada_por_rgpm,validada_em,observacao FROM multas WHERE status IN ('paga','nao_paga') {$where} ORDER BY aplicada_em DESC");
    $lista = [];
    if ($res) while ($r = $res->fetch_assoc()) $lista[] = $r;
    echo json_encode(['sucesso' => true, 'logs' => $lista, 'total' => count($lista)]);
    exit;
}