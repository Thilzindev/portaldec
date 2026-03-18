<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
ob_start();
session_start();
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

// ══════════════════════════════════════════════════════════════════════════════
// Verificação de sessão feita ANTES de qualquer require ou query
// ══════════════════════════════════════════════════════════════════════════════
if (!isset($_SESSION["usuario"])) {
    if (!empty($_POST['ajax_action'])) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["sucesso" => false, "erro" => "Nao autorizado"]);
        exit;
    }
    ob_end_clean();
    header("Location: login.php");
    exit;
}

require "conexao.php";
$conexao->set_charset("utf8mb4");

$nivelSessao = intval($_SESSION["nivel"] ?? 0);

// ── Verifica se sessão foi invalidada pelo admin (mudança de nível) ──────────
if (!empty($_SESSION['rgpm'])) {
    $chkInvCol = db_val($conexao, "SHOW COLUMNS FROM usuarios LIKE 'session_invalidada'");
    if ($chkInvCol !== null) {
        $invRow = db_row($conexao, "SELECT session_invalidada FROM usuarios WHERE rgpm = ? LIMIT 1", 's', [$_SESSION['rgpm']]);
        if ($invRow && (int)$invRow['session_invalidada'] === 1) {
            db_query($conexao, "UPDATE usuarios SET session_invalidada = 0 WHERE rgpm = ?", 's', [$_SESSION['rgpm']]);
            echo json_encode(["sucesso"=>false,"force_logout"=>true,"erro"=>"Sessão expirada"]); exit;
        }
    }
}

define('NIVEL_ADM',       1);
define('NIVEL_INSTRUTOR', 2);
define('NIVEL_ALUNO',     3);

// ══════════════════════════════════════════════════════════════════════════════
// Token CSRF
// ══════════════════════════════════════════════════════════════════════════════
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verificarCsrf(): void
{
    $tokenPost   = $_POST['csrf_token']               ?? '';
    $tokenHeader = $_SERVER['HTTP_X_CSRF_TOKEN']      ?? '';
    $tokenSessao = $_SESSION['csrf_token']             ?? '';

    if (!$tokenSessao || (!hash_equals($tokenSessao, $tokenPost) && !hash_equals($tokenSessao, $tokenHeader))) {
        echo json_encode(["sucesso" => false, "erro" => "Token CSRF inválido"]);
        exit;
    }
}

function exigirNivel(int $nivelMinimo): void
{
    global $nivelSessao;
    if ($nivelSessao > $nivelMinimo) {
        echo json_encode(["sucesso" => false, "erro" => "Acesso não autorizado para seu nível"]);
        exit;
    }
}

function checkRateLimit(string $acao, int $maxPorMinuto = 30): void
{
    $chave = 'rl_' . $acao;
    $agora = time();
    if (!isset($_SESSION[$chave])) {
        $_SESSION[$chave] = ['count' => 0, 'window_start' => $agora];
    }
    if ($agora - $_SESSION[$chave]['window_start'] > 60) {
        $_SESSION[$chave] = ['count' => 0, 'window_start' => $agora];
    }
    $_SESSION[$chave]['count']++;
    if ($_SESSION[$chave]['count'] > $maxPorMinuto) {
        echo json_encode(["sucesso" => false, "erro" => "Muitas requisições. Aguarde um momento."]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// BLOCO AJAX
// ══════════════════════════════════════════════════════════════════════════════
if (!empty($_POST['ajax_action'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Captura erros fatais e retorna como JSON
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo json_encode(['sucesso' => false, 'erro' => "PHP Error [$errno]: $errstr em $errfile linha $errline"]);
        exit;
    });
    register_shutdown_function(function() {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => false, 'erro' => 'Fatal: ' . $e['message'] . ' em ' . $e['file'] . ':' . $e['line']]);
        }
    });

    verificarCsrf();

    $acao  = $_POST['ajax_action'];
    $curso = trim($_POST['curso'] ?? '');
    $tag   = trim($_POST['tag']   ?? '');
    $raw   = trim($_POST['ids']   ?? '');

    $acoesPermitidas = [
        'editar_ata',
        'setar_tags',
        'remover_tags',
        'verificar_aprovacao',
        'criar_curso',
        'editar_curso',
        'listar_cursos',
        'deletar_curso',
        'eliminar_curso',
        'listar_pagamentos',
        'validar_pagamento',
        'rejeitar_pagamento',
        'ver_comprovante',
        'logs_pagamentos',
        'ver_comprovante_log',
        'listar_cursos_abertos',
        'verificar_notificacao_pagamento',
        'relatorio_pagamentos',
        'editar_usuario',
        'deletar_usuario',
        'buscar_usuario',
        'buscar_aluno_nivel3',
        'buscar_auxiliar_nivel2',
        'dashboard_stats',
        'listar_atas',
        'deletar_ata',
        'listar_cronogramas',
        'deletar_cronograma',
        'adicionar_blacklist',
        'listar_blacklist',
        'remover_blacklist',
        'verificar_blacklist_cadastro',
        'multa_buscar_aluno',
        'aplicar_multa',
        'multa_listar_pendentes_adm',
        'multa_ver_comprovante',
        'multa_validar',
        'multa_negar',
        'multa_logs',
        'multa_deletar',
        'multa_log_deletar',
        'multa_logs_apagar_todos',
        'arquivar_pagamentos',
        'listar_arquivo',
        'ver_comprovante_arquivo',
    ];

    if (!in_array($acao, $acoesPermitidas, true)) {
        echo json_encode(["sucesso" => false, "erro" => "Acao desconhecida"]);
        exit;
    }

    // ── EDITAR ATA ─────────────────────────────────────────────────────────────
    if ($acao === 'editar_ata') {
        exigirNivel(NIVEL_ADM);
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $sub = trim($_POST['sub'] ?? '');

        $colsExist = [];
        $colInfo = $conexao->query("SHOW COLUMNS FROM registros_presenca");
        if ($colInfo) {
            while ($c = $colInfo->fetch_assoc()) $colsExist[] = $c['Field'];
        }
        $temInstrutor    = in_array('instrutor',      $colsExist);
        $temObs          = in_array('observacoes',    $colsExist);
        $temListaPresenca = in_array('lista_presenca', $colsExist);

        if ($sub === 'carregar') {
            $selectCols = 'id, nome_referencia';
            if ($temInstrutor)    $selectCols .= ', instrutor';
            if ($temObs)          $selectCols .= ', observacoes';
            if ($temListaPresenca) $selectCols .= ', lista_presenca';

            $stmt = $conexao->prepare("SELECT {$selectCols} FROM registros_presenca WHERE id=?");
            if (!$stmt) {
                echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]);
                exit;
            }
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                echo json_encode(["sucesso" => false, "erro" => "Erro execute: " . $stmt->error]);
                exit;
            }
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                echo json_encode(["sucesso" => false, "erro" => "Ata não encontrada (id={$id})"]);
                exit;
            }

            $row['instrutor']    = $row['instrutor']    ?? '';
            $row['observacoes']  = $row['observacoes']  ?? '';
            $row['lista_presenca'] = $row['lista_presenca'] ?? '';

            echo json_encode(["sucesso" => true, "ata" => $row]);
            exit;
        }

        if ($sub === 'add_rgpm') {
            $rgpm = trim($_POST['rgpm'] ?? '');
            if (!$rgpm) {
                echo json_encode(["sucesso" => false, "erro" => "RGPM vazio"]);
                exit;
            }
            $uStmt = $conexao->prepare("SELECT nome, rgpm FROM usuarios WHERE rgpm=? LIMIT 1");
            if (!$uStmt) {
                echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]);
                exit;
            }
            $uStmt->bind_param("s", $rgpm);
            $uStmt->execute();
            $uRow = $uStmt->get_result()->fetch_assoc();
            if (!$uRow) {
                echo json_encode(["sucesso" => false, "erro" => "RGPM {$rgpm} não encontrado"]);
                exit;
            }
            echo json_encode(["sucesso" => true, "nome" => $uRow['nome'], "rgpm" => $uRow['rgpm'], "linha" => $uRow['nome'] . ' - RG ' . $uRow['rgpm']]);
            exit;
        }

        $nomeRef   = trim($_POST['nome_referencia'] ?? '');
        $instrutor = trim($_POST['instrutor']       ?? '');
        $obs       = trim($_POST['observacoes']     ?? '');
        $lista     = trim($_POST['lista_presenca']  ?? '');

        if (!$nomeRef) {
            echo json_encode(["sucesso" => false, "erro" => "Nome da aula é obrigatório"]);
            exit;
        }

        if ($temListaPresenca) {
            if ($temInstrutor && $temObs) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, lista_presenca=?, instrutor=?, observacoes=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("ssssi", $nomeRef, $lista, $instrutor, $obs, $id);
            } elseif ($temInstrutor) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, lista_presenca=?, instrutor=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("sssi", $nomeRef, $lista, $instrutor, $id);
            } elseif ($temObs) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, lista_presenca=?, observacoes=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("sssi", $nomeRef, $lista, $obs, $id);
            } else {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, lista_presenca=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("ssi", $nomeRef, $lista, $id);
            }
        } else {
            if ($temInstrutor && $temObs) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, instrutor=?, observacoes=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("sssi", $nomeRef, $instrutor, $obs, $id);
            } elseif ($temInstrutor) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, instrutor=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("ssi", $nomeRef, $instrutor, $id);
            } elseif ($temObs) {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=?, observacoes=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("ssi", $nomeRef, $obs, $id);
            } else {
                $upd = $conexao->prepare("UPDATE registros_presenca SET nome_referencia=? WHERE id=?");
                if (!$upd) { echo json_encode(["sucesso" => false, "erro" => "Erro DB: " . $conexao->error]); exit; }
                $upd->bind_param("si", $nomeRef, $id);
            }
        }

        if ($upd->execute()) {
            echo json_encode(["sucesso" => true]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => "Erro ao salvar: " . $upd->error]);
        }
        exit;
    }

    // ── DASHBOARD STATS ───────────────────────────────────────────────────
    if ($acao === 'dashboard_stats') {
        checkRateLimit('dashboard_stats', 20);

        function contarPag($c, $tag)
        {
            $s = $c->prepare("SELECT COUNT(*) as t FROM usuarios WHERE JSON_CONTAINS(tags,?)");
            $j = json_encode($tag);
            $s->bind_param("s", $j);
            $s->execute();
            return intval($s->get_result()->fetch_assoc()['t']);
        }

        function contarAlistados($c, $nomeCurso)
        {
            // Conta nivel 3 + tem meus_cursos + tem tag "Pagou X"
            $nEsc  = $c->real_escape_string($nomeCurso);
            $chave = null;
            foreach (['CFSD','CFC','CFO','CFS'] as $k) {
                if (stripos($nomeCurso, $k) !== false) { $chave = $k; break; }
            }
            if ($chave) {
                $tagPag = $c->real_escape_string('Pagou ' . $chave);
                $res = $c->query("SELECT COUNT(*) as t FROM usuarios WHERE nivel=3 AND TRIM(meus_cursos)='{$nEsc}' AND JSON_CONTAINS(tags, '\"{$tagPag}\"')");
            } else {
                $res = $c->query("SELECT COUNT(*) as t FROM usuarios WHERE nivel=3 AND TRIM(meus_cursos)='{$nEsc}'");
            }
            return $res ? intval($res->fetch_assoc()['t']) : 0;
        }


        $qCursos    = $conexao->query("SELECT id,nome,status,alunos_matriculados,valor_taxa FROM cursos ORDER BY nome ASC");
        $cursosList = [];
        if ($qCursos) {
            while ($r = $qCursos->fetch_assoc()) $cursosList[] = $r;
        }

        $cursoStats = [];
        foreach ($cursosList as $c) {
            $nomeC = $c['nome'];
            $chave = null;
            foreach (['CFSD', 'CFC', 'CFO', 'CFS'] as $k) {
                if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $nomeC)) {
                    $chave = $k;
                    break;
                }
            }
            $pagantes  = $chave ? contarPag($conexao, 'Pagou ' . $chave) : 0;
            $alistados = contarAlistados($conexao, $nomeC);
            $valorTaxa = floatval($c['valor_taxa'] ?? 0);

            // Soma multas pagas de alunos que pertencem a este curso (pelo meus_cursos atual)
            $multasCurso = 0;
            $chkMT = $conexao->query("SHOW TABLES LIKE 'multas'");
            if ($chkMT && $chkMT->num_rows > 0) {
                $nomeEscC = $conexao->real_escape_string($nomeC);
                $qMC = $conexao->query("SELECT IFNULL(SUM(m.valor),0) as total
                    FROM multas m
                    INNER JOIN usuarios u ON u.id = m.usuario_id
                    WHERE m.status='paga' AND TRIM(u.meus_cursos)='{$nomeEscC}'");
                if ($qMC) $multasCurso = floatval($qMC->fetch_assoc()['total']);
            }

            // Soma inscrições e multas de alunos ARQUIVADOS (excluídos) deste curso
            $arqInscCurso  = 0;
            $arqMultCurso  = 0;
            $chkArqTbl = db_val($conexao, "SHOW TABLES LIKE 'pagamentos_arquivo'");
            if ($chkArqTbl !== null) {
                $arqI = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo WHERE tipo='inscricao' AND curso=? AND status_original='validado'",
                    's', [$nomeC]
                );
                $arqInscCurso = floatval($arqI ?? 0);

                $arqM = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo WHERE tipo='multa' AND curso=? AND status_original='paga'",
                    's', [$nomeC]
                );
                $arqMultCurso = floatval($arqM ?? 0);
            }

            $cursoStats[] = [
                'id'         => $c['id'],
                'nome'       => $nomeC,
                'status'     => $c['status'],
                'alistados'  => $alistados,
                'pagantes'   => $pagantes,
                'valor_taxa' => $valorTaxa,
                'arrecadado' => ($pagantes * $valorTaxa) + $multasCurso + $arqInscCurso + $arqMultCurso,
                'multas'     => $multasCurso + $arqMultCurso,
            ];
        }

        $totalGeral = array_sum(array_column($cursoStats, 'arrecadado'));

        // Soma apenas multas de alunos SEM curso (meus_cursos vazio) — os com curso já estão no arrecadado
        $chkMult = $conexao->query("SHOW TABLES LIKE 'multas'");
        if ($chkMult && $chkMult->num_rows > 0) {
            $qMult = $conexao->query("SELECT IFNULL(SUM(m.valor),0) as total
                FROM multas m
                INNER JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.status='paga' AND (TRIM(u.meus_cursos)='' OR u.meus_cursos IS NULL)");
            if ($qMult) $totalGeral += floatval($qMult->fetch_assoc()['total']);
        }

        // ── Soma arquivados cujo curso não existe mais no sistema ──────────
        // (arquivados de curso existente já estão somados dentro de cursoStats acima)
        $chkArq = db_val($conexao, "SHOW TABLES LIKE 'pagamentos_arquivo'");
        if ($chkArq !== null) {
            $cursosNomes = array_column($cursoStats, 'nome');
            if (empty($cursosNomes)) {
                // Nenhum curso no sistema — soma tudo do arquivo
                $totalArqInsc = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo WHERE tipo = 'inscricao' AND status_original = 'validado'"
                );
                $totalGeral += floatval($totalArqInsc ?? 0);
                $totalArqMult = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo WHERE tipo = 'multa' AND status_original = 'paga'"
                );
                $totalGeral += floatval($totalArqMult ?? 0);
            } else {
                // Só soma arquivados cujo curso não está mais na lista ativa
                $placeholders = implode(',', array_fill(0, count($cursosNomes), '?'));
                $tipos = str_repeat('s', count($cursosNomes));

                $totalArqInsc = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo
                     WHERE tipo = 'inscricao' AND status_original = 'validado'
                     AND (curso IS NULL OR curso = '' OR curso NOT IN ($placeholders))",
                    $tipos, $cursosNomes
                );
                $totalGeral += floatval($totalArqInsc ?? 0);

                $totalArqMult = db_val($conexao,
                    "SELECT IFNULL(SUM(valor),0) FROM pagamentos_arquivo
                     WHERE tipo = 'multa' AND status_original = 'paga'
                     AND (curso IS NULL OR curso = '' OR curso NOT IN ($placeholders))",
                    $tipos, $cursosNomes
                );
                $totalGeral += floatval($totalArqMult ?? 0);
            }
        }

        $qPend    = $conexao->query("SELECT COUNT(*) as t FROM pagamentos_pendentes WHERE (status='pendente' OR status='') AND IFNULL(tipo_pagamento,'taxa')='taxa'");
        $pendentes = $qPend ? intval($qPend->fetch_assoc()['t']) : 0;

        $qAprov   = $conexao->query("SELECT COUNT(*) as t FROM usuarios u INNER JOIN cursos c ON TRIM(c.nome)=TRIM(u.meus_cursos) WHERE u.status='Aprovado' AND c.status='Aberto'");
        $aprovados = $qAprov ? intval($qAprov->fetch_assoc()['t']) : 0;

        $qAL    = $conexao->query("SELECT u.nome,u.rgpm,u.discord,u.meus_cursos FROM usuarios u INNER JOIN cursos c ON TRIM(c.nome)=TRIM(u.meus_cursos) WHERE u.status='Aprovado' AND c.status='Aberto' ORDER BY u.meus_cursos ASC,u.nome ASC");
        $listaAL = [];
        if ($qAL) {
            while ($r = $qAL->fetch_assoc()) $listaAL[] = $r;
        }

        $qM    = $conexao->query("SELECT u.nome, u.rgpm, u.discord, u.meus_cursos, u.status, u.nivel, u.tags,
            CASE WHEN pp.id IS NOT NULL THEN 1 ELSE 0 END as taxa_paga
            FROM usuarios u
            LEFT JOIN pagamentos_pendentes pp ON pp.usuario_id = u.id AND pp.status = 'validado'
            ORDER BY u.nivel ASC, u.nome ASC");
        $listaM = [];
        if ($qM) {
            while ($r = $qM->fetch_assoc()) $listaM[] = $r;
        }

        $qAtas    = $conexao->query("SELECT id,nome_referencia,data_registro FROM registros_presenca ORDER BY id DESC");
        $listaAtas = [];
        if ($qAtas) {
            while ($r = $qAtas->fetch_assoc()) $listaAtas[] = $r;
        }

        $qCron    = $conexao->query("SELECT id,titulo,data_envio FROM cronogramas ORDER BY id DESC");
        $listaCron = [];
        if ($qCron) {
            while ($r = $qCron->fetch_assoc()) $listaCron[] = $r;
        }

        echo json_encode([
            "sucesso"        => true,
            "cursos"         => $cursoStats,
            "total_geral"    => $totalGeral,
            "pendentes"      => $pendentes,
            "aprovados"      => $aprovados,
            "lista_aprovados" => $listaAL,
            "membros"        => $listaM,
            "atas"           => $listaAtas,
            "cronogramas"    => $listaCron,
        ]);
        exit;
    }

    // ── LISTAR ATAS ────────────────────────────────────────────────────────
    if ($acao === 'listar_atas') {
        $q    = $conexao->query("SELECT id,nome_referencia,data_registro FROM registros_presenca ORDER BY id DESC");
        $lista = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) $lista[] = $r;
        }
        echo json_encode(["sucesso" => true, "atas" => $lista]);
        exit;
    }

    // ── DELETAR ATA ────────────────────────────────────────────────────────
    if ($acao === 'deletar_ata') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("DELETE FROM registros_presenca WHERE id=?");
        $stmt->bind_param("i", $id);
        echo $stmt->execute()
            ? json_encode(["sucesso" => true])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── LISTAR CRONOGRAMAS ─────────────────────────────────────────────────
    if ($acao === 'listar_cronogramas') {
        $q    = $conexao->query("SELECT id,titulo,data_envio FROM cronogramas ORDER BY id DESC");
        $lista = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) $lista[] = $r;
        }
        echo json_encode(["sucesso" => true, "cronogramas" => $lista]);
        exit;
    }

    // ── DELETAR CRONOGRAMA ─────────────────────────────────────────────────
    if ($acao === 'deletar_cronograma') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("DELETE FROM cronogramas WHERE id=?");
        $stmt->bind_param("i", $id);
        echo $stmt->execute()
            ? json_encode(["sucesso" => true])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── LISTAR CURSOS ABERTOS ─────────────────────────────────────────────
    if ($acao === 'listar_cursos_abertos') {
        $q    = $conexao->query("SELECT nome FROM cursos WHERE status='Aberto' ORDER BY nome ASC");
        $lista = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) $lista[] = $r['nome'];
        }
        echo json_encode(["sucesso" => true, "cursos" => $lista]);
        exit;
    }

    // ── CRIAR CURSO ───────────────────────────────────────────────────────
    if ($acao === 'criar_curso') {
        exigirNivel(NIVEL_ADM);

        $nome      = trim($_POST['nome']      ?? '');
        $desc      = trim($_POST['descricao'] ?? '');
        $tipo      = trim($_POST['tipo']      ?? 'Formação');
        $status    = trim($_POST['status']    ?? 'Aberto');
        $valorTaxa = floatval($_POST['valor_taxa'] ?? 0);
        $tagsCurso = trim($_POST['tags_curso'] ?? '');

        if (!$nome || !$desc) {
            echo json_encode(["sucesso" => false, "erro" => "Campos obrigatórios em falta"]);
            exit;
        }

        $colCheck2 = $conexao->query("SHOW COLUMNS FROM cursos LIKE 'tags_curso'");
        $temTagsCol2 = $colCheck2 && $colCheck2->num_rows > 0;

        if ($temTagsCol2) {
            $stmt = $conexao->prepare("INSERT INTO cursos (nome,descricao,alunos_matriculados,status,tipo_curso,valor_taxa,tags_curso) VALUES (?,?,0,?,?,?,?)");
            $stmt->bind_param("ssssds", $nome, $desc, $status, $tipo, $valorTaxa, $tagsCurso);
        } else {
            $stmt = $conexao->prepare("INSERT INTO cursos (nome,descricao,alunos_matriculados,status,tipo_curso,valor_taxa) VALUES (?,?,0,?,?,?)");
            $stmt->bind_param("ssssd", $nome, $desc, $status, $tipo, $valorTaxa);
        }
        echo $stmt->execute()
            ? json_encode(["sucesso" => true, "id" => $conexao->insert_id])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── LISTAR CURSOS ─────────────────────────────────────────────────────
    if ($acao === 'listar_cursos') {
        exigirNivel(NIVEL_INSTRUTOR);

        $todos = $conexao->query("SELECT id,nome,descricao,status,tipo_curso,alunos_matriculados,valor_taxa FROM cursos ORDER BY nome ASC");
        $lista = [];
        if ($todos) {
            while ($r = $todos->fetch_assoc()) $lista[] = $r;
        }
        echo json_encode(["sucesso" => true, "cursos" => $lista]);
        exit;
    }

    // ── EDITAR CURSO ──────────────────────────────────────────────────────
    if ($acao === 'editar_curso') {
        exigirNivel(NIVEL_ADM);

        $id        = intval($_POST['id']        ?? 0);
        $nome      = trim($_POST['nome']        ?? '');
        $desc      = trim($_POST['descricao']   ?? '');
        $tipo      = trim($_POST['tipo']        ?? 'Formação');
        $status    = trim($_POST['status']      ?? 'Aberto');
        $valorTaxa = floatval($_POST['valor_taxa'] ?? 0);

        if (!$id || !$nome || !$desc) {
            echo json_encode(["sucesso" => false, "erro" => "Campos obrigatórios em falta"]);
            exit;
        }

        $tagsCurso = trim($_POST['tags_curso'] ?? '');

        // Só atualiza tags_curso se o campo foi enviado explicitamente no POST
        $editarTags = array_key_exists('tags_curso', $_POST);

        $colChkEc = $conexao->query("SHOW COLUMNS FROM cursos LIKE 'tags_curso'");
        if ($colChkEc && $colChkEc->num_rows > 0 && $editarTags) {
            $stmt = $conexao->prepare("UPDATE cursos SET nome=?,descricao=?,tipo_curso=?,status=?,valor_taxa=?,tags_curso=?,alunos_matriculados=IF(?='Fechado',0,alunos_matriculados) WHERE id=?");
            $stmt->bind_param("ssssdssi", $nome, $desc, $tipo, $status, $valorTaxa, $tagsCurso, $status, $id);
        } else {
            $stmt = $conexao->prepare("UPDATE cursos SET nome=?,descricao=?,tipo_curso=?,status=?,valor_taxa=?,alunos_matriculados=IF(?='Fechado',0,alunos_matriculados) WHERE id=?");
            $stmt->bind_param("ssssdsi", $nome, $desc, $tipo, $status, $valorTaxa, $status, $id);
        }
        echo $stmt->execute()
            ? json_encode(["sucesso" => true])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── DELETAR CURSO ─────────────────────────────────────────────────────
    if ($acao === 'deletar_curso') {
        exigirNivel(NIVEL_ADM);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("DELETE FROM cursos WHERE id=?");
        $stmt->bind_param("i", $id);
        echo $stmt->execute()
            ? json_encode(["sucesso" => true])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── ELIMINAR CURSO ────────────────────────────────────────────────────
    if ($acao === 'eliminar_curso') {
        exigirNivel(NIVEL_ADM);

        $id   = intval($_POST['id']   ?? 0);
        $nome = trim($_POST['nome']   ?? '');

        if (!$id || !$nome) {
            echo json_encode(["sucesso" => false, "erro" => "ID ou nome inválido"]);
            exit;
        }

        $qNome = $conexao->prepare("SELECT nome FROM cursos WHERE id=? LIMIT 1");
        $qNome->bind_param("i", $id);
        $qNome->execute();
        $nomeExato = trim($qNome->get_result()->fetch_assoc()['nome'] ?? $nome);

        $contStmt = $conexao->prepare("SELECT COUNT(DISTINCT u.id) as total FROM usuarios u LEFT JOIN pagamentos_pendentes pp ON pp.usuario_id=u.id WHERE u.nivel=3 AND (TRIM(u.meus_cursos)=? OR pp.curso=?)");
        $contStmt->bind_param("ss", $nomeExato, $nomeExato);
        $contStmt->execute();
        $total = intval($contStmt->get_result()->fetch_assoc()['total'] ?? 0);

        $selStmt = $conexao->prepare("SELECT DISTINCT u.id FROM usuarios u LEFT JOIN pagamentos_pendentes pp ON pp.usuario_id=u.id WHERE u.nivel=3 AND (TRIM(u.meus_cursos)=? OR pp.curso=?)");
        $selStmt->bind_param("ss", $nomeExato, $nomeExato);
        $selStmt->execute();
        $rows = $selStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $removidos = 0;

        foreach ($rows as $u) {
            $uid = intval($u['id']);
            $tagsLimpo = json_encode([], JSON_UNESCAPED_UNICODE);
            $updU = $conexao->prepare("UPDATE usuarios SET meus_cursos='', status='Pendente', tags=? WHERE id=? AND nivel=3");
            $updU->bind_param("si", $tagsLimpo, $uid);
            if ($updU->execute()) $removidos++;

            $delPag = $conexao->prepare("DELETE FROM pagamentos_pendentes WHERE usuario_id=? AND curso=?");
            $delPag->bind_param("is", $uid, $nomeExato);
            $delPag->execute();
        }

        $fechaStmt = $conexao->prepare("UPDATE cursos SET status='Fechado', alunos_matriculados=0 WHERE id=?");
        $fechaStmt->bind_param("i", $id);
        if (!$fechaStmt->execute()) {
            echo json_encode(["sucesso" => false, "erro" => "Erro ao fechar curso"]);
            exit;
        }

        // Limpa logs de multas do curso eliminado
        $nomeEsc = $conexao->real_escape_string($nomeExato);
        $conexao->query("DELETE FROM multas WHERE curso_aluno='{$nomeEsc}'");

        echo json_encode([
            "sucesso"        => true,
            "removidos"      => $removidos,
            "total_esperado" => $total,
        ]);
        exit;
    }

    // ── VERIFICAR NOTIFICAÇÃO PAGAMENTO ───────────────────────────────────
    if ($acao === 'verificar_notificacao_pagamento') {
        $usuId = intval($_POST['usuario_id'] ?? 0);
        if (!$usuId) {
            echo json_encode(["sucesso" => false, "notificacao" => false]);
            exit;
        }

        $q = $conexao->prepare("SELECT id,curso,status FROM pagamentos_pendentes WHERE usuario_id=? AND status IN ('validado','rejeitado') AND (notificado IS NULL OR notificado=0) ORDER BY id DESC LIMIT 1");
        if (!$q) {
            echo json_encode(["sucesso" => true, "notificacao" => false]);
            exit;
        }

        $q->bind_param("i", $usuId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row) {
            echo json_encode(["sucesso" => true, "notificacao" => false]);
            exit;
        }

        $upd = $conexao->prepare("UPDATE pagamentos_pendentes SET notificado=1 WHERE id=?");
        if ($upd) {
            $upd->bind_param("i", intval($row['id']));
            $upd->execute();
        }

        echo json_encode(["sucesso" => true, "notificacao" => true, "status" => $row['status'], "curso" => $row['curso']]);
        exit;
    }

    // ── LISTAR PAGAMENTOS ─────────────────────────────────────────────────
    if ($acao === 'listar_pagamentos') {
        exigirNivel(NIVEL_INSTRUTOR);
        checkRateLimit('listar_pagamentos', 20);

        $st    = trim($_POST['status'] ?? 'pendente');
        $busca = trim($_POST['busca'] ?? '');

        if ($busca !== '') {
            $like = "%{$busca}%";
            $stmt = $conexao->prepare("SELECT id,usuario_id,nome,rgpm,discord,curso,mime_type,data_envio,status FROM pagamentos_pendentes WHERE (status=? OR (status='' AND ?='pendente')) AND IFNULL(tipo_pagamento,'taxa')='taxa' AND (nome LIKE ? OR rgpm LIKE ? OR discord LIKE ? OR curso LIKE ?) ORDER BY data_envio ASC");
            $stmt->bind_param("ssssss", $st, $st, $like, $like, $like, $like);
        } else {
            $stmt = $conexao->prepare("SELECT id,usuario_id,nome,rgpm,discord,curso,mime_type,data_envio,status FROM pagamentos_pendentes WHERE (status=? OR (status='' AND ?='pendente')) AND IFNULL(tipo_pagamento,'taxa')='taxa' ORDER BY data_envio ASC");
            $stmt->bind_param("ss", $st, $st);
        }
        $stmt->execute();
        $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(["sucesso" => true, "pagamentos" => $lista, "total" => count($lista)]);
        exit;
    }

    // ── VER COMPROVANTE ───────────────────────────────────────────────────
    if ($acao === 'ver_comprovante') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("SELECT comprovante,mime_type,LENGTH(comprovante) as tamanho FROM pagamentos_pendentes WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            echo json_encode(["sucesso" => false, "erro" => "Não encontrado"]);
            exit;
        }

        if ($row['tamanho'] > 8 * 1024 * 1024) {
            echo json_encode(["sucesso" => false, "erro" => "Arquivo muito grande para exibição"]);
            exit;
        }

        echo json_encode(["sucesso" => true, "imagem" => base64_encode($row['comprovante']), "mime" => $row['mime_type']]);
        exit;
    }

    // ── RELATÓRIO PAGAMENTOS ──────────────────────────────────────────────
    if ($acao === 'relatorio_pagamentos') {
        exigirNivel(NIVEL_INSTRUTOR);

        $qCursosR = $conexao->query("SELECT id,nome,valor_taxa FROM cursos ORDER BY nome ASC");
        $cursosR  = [];
        if ($qCursosR) {
            while ($r = $qCursosR->fetch_assoc()) $cursosR[] = $r;
        }

        $resultado = [];
        foreach ($cursosR as $c) {
            $chave = null;
            foreach (['CFSD', 'CFC', 'CFO', 'CFS'] as $k) {
                if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $c['nome'])) {
                    $chave = $k;
                    break;
                }
            }
            if (!$chave) continue;

            $tagPag = 'Pagou ' . $chave;
            $stmtP  = $conexao->prepare("SELECT nome,rgpm,discord FROM usuarios WHERE JSON_CONTAINS(tags,?)");
            $jv     = json_encode($tagPag);
            $stmtP->bind_param("s", $jv);
            $stmtP->execute();
            $pagantes = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

            // Busca multas pagas de alunos que têm este curso ATUALMENTE (independente de quando pagou a multa)
            $nomeEsc = $conexao->real_escape_string($c['nome']);
            $chkTb   = $conexao->query("SHOW TABLES LIKE 'multas'");
            $multasPagas = [];
            if ($chkTb && $chkTb->num_rows > 0) {
                $qMul = $conexao->query("SELECT m.nome_aluno as nome, m.rgpm_aluno as rgpm, m.discord_aluno as discord, m.valor
                    FROM multas m
                    INNER JOIN usuarios u ON u.id = m.usuario_id
                    WHERE m.status='paga' AND TRIM(u.meus_cursos)='{$nomeEsc}'
                    ORDER BY m.validada_em DESC");
                if ($qMul) while ($rm = $qMul->fetch_assoc()) $multasPagas[] = $rm;
            }
            $totalMultas = array_sum(array_column($multasPagas, 'valor'));

            $resultado[] = [
                'curso'         => $c['nome'],
                'chave'         => $chave,
                'valor_taxa'    => floatval($c['valor_taxa'] ?? 0),
                'pagantes'      => $pagantes,
                'total'         => count($pagantes) * floatval($c['valor_taxa'] ?? 0),
                'multas_pagas'  => $multasPagas,
                'total_multas'  => $totalMultas,
            ];
        }

        // Multas pagas de alunos que ATUALMENTE não têm curso (não pagaram a taxa ainda)
        $multasSemCurso = [];
        $chkTb2 = $conexao->query("SHOW TABLES LIKE 'multas'");
        if ($chkTb2 && $chkTb2->num_rows > 0) {
            $qSC = $conexao->query("SELECT m.id, m.nome_aluno as nome, m.rgpm_aluno as rgpm, m.discord_aluno as discord, m.valor, m.curso_aluno
                FROM multas m
                INNER JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.status='paga' AND (TRIM(u.meus_cursos)='' OR u.meus_cursos IS NULL)
                ORDER BY m.validada_em DESC");
            if ($qSC) while ($rm = $qSC->fetch_assoc()) $multasSemCurso[] = $rm;
        }

        echo json_encode([
            "sucesso"           => true,
            "dados"             => $resultado,
            "multas_sem_curso"  => $multasSemCurso,
            "total_sem_curso"   => array_sum(array_column($multasSemCurso, 'valor')),
        ]);
        exit;
    }

    // ── VALIDAR PAGAMENTO ─────────────────────────────────────────────────
    if ($acao === 'validar_pagamento') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("SELECT usuario_id,nome,curso FROM pagamentos_pendentes WHERE id=? AND (status='pendente' OR status='')");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $pag = $stmt->get_result()->fetch_assoc();
        if (!$pag) {
            echo json_encode(["sucesso" => false, "erro" => "Pagamento não encontrado ou já processado"]);
            exit;
        }

        $usuarioId = intval($pag['usuario_id']);
        $curso     = trim($pag['curso']);

        $cursoRow = null;

        $csExact = $conexao->prepare("SELECT id, nome, tags_curso FROM cursos WHERE TRIM(nome)=TRIM(?) LIMIT 1");
        if ($csExact) {
            $csExact->bind_param("s", $curso);
            $csExact->execute();
            $cursoRow = $csExact->get_result()->fetch_assoc();
        }
        if (!$cursoRow) {
            $cursoLike = '%' . $curso . '%';
            $csLike = $conexao->prepare("SELECT id, nome, tags_curso FROM cursos WHERE nome LIKE ? LIMIT 1");
            if ($csLike) {
                $csLike->bind_param("s", $cursoLike);
                $csLike->execute();
                $cursoRow = $csLike->get_result()->fetch_assoc();
            }
        }

        if (!$cursoRow) {
            echo json_encode(["sucesso" => false, "erro" => "Curso \"" . $curso . "\" nao encontrado no banco."]);
            exit;
        }

        $cursoNomeExato = trim($cursoRow['nome']);
        $cursoIdDB      = intval($cursoRow['id']);

        $tagPagamento = 'Pagou ' . $cursoNomeExato;

        $uStmt = $conexao->prepare("SELECT tags, meus_cursos FROM usuarios WHERE id=?");
        $uStmt->bind_param("i", $usuarioId);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        if (!$uRow) {
            echo json_encode(["sucesso" => false, "erro" => "Usuário não encontrado"]);
            exit;
        }

        $tagsAtuais = [];
        if (!empty($uRow['tags'])) {
            $decoded    = json_decode($uRow['tags'], true);
            $tagsAtuais = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(',', $uRow['tags']))));
        }

        $tagsAtuais = array_values(array_filter($tagsAtuais, function($t) use ($tagPagamento) { return $t !== $tagPagamento; }));

        $tagsNovas = [$tagPagamento];
        foreach ($tagsAtuais as $t) {
            if (!in_array($t, $tagsNovas)) $tagsNovas[] = $t;
        }
        $novasTags = json_encode(array_values($tagsNovas), JSON_UNESCAPED_UNICODE);

        $cursoAtual    = trim($uRow['meus_cursos'] ?? '');
        $matriculaNova = false;

        if ($cursoAtual !== $cursoNomeExato) {
            $updCurso = $conexao->prepare("UPDATE usuarios SET tags=?, meus_cursos=? WHERE id=?");
            $updCurso->bind_param("ssi", $novasTags, $cursoNomeExato, $usuarioId);
            if (!$updCurso->execute()) {
                echo json_encode(["sucesso" => false, "erro" => "Erro ao matricular no curso"]);
                exit;
            }
            $incStmt = $conexao->prepare("UPDATE cursos SET alunos_matriculados=alunos_matriculados+1 WHERE id=?");
            $incStmt->bind_param("i", $cursoIdDB);
            $incStmt->execute();
            $matriculaNova = true;
        } else {
            $updTag = $conexao->prepare("UPDATE usuarios SET tags=? WHERE id=?");
            $updTag->bind_param("si", $novasTags, $usuarioId);
            if (!$updTag->execute()) {
                echo json_encode(["sucesso" => false, "erro" => "Erro ao atualizar tags"]);
                exit;
            }
        }

        // Garante colunas de rastreio de validação
        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS validado_por VARCHAR(255) DEFAULT NULL");
        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS validado_por_rgpm VARCHAR(50) DEFAULT NULL");
        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS validado_em DATETIME DEFAULT NULL");
        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS tipo_pagamento VARCHAR(20) DEFAULT 'taxa'");
        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS valor_multa DECIMAL(15,2) DEFAULT NULL");

        // Busca nome e RGPM de quem está validando
        $validadorNome = $_SESSION['usuario'] ?? 'Desconhecido';
        $validadorRgpm = $_SESSION['rgpm']    ?? '';
        $validadoEm    = date('Y-m-d H:i:s');

        // Marca como validado — mantém comprovante para logs
        $delStmt = $conexao->prepare("UPDATE pagamentos_pendentes SET status='validado', validado_por=?, validado_por_rgpm=?, validado_em=? WHERE id=?");
        $delStmt->bind_param("sssi", $validadorNome, $validadorRgpm, $validadoEm, $id);
        if (!$delStmt->execute()) {
            echo json_encode(["sucesso" => false, "erro" => "Erro ao validar"]);
            exit;
        }

        echo json_encode(["sucesso" => true, "tag_setada" => $tagPagamento, "matriculado" => $matriculaNova, "curso_nome" => $curso]);
        exit;
    }

    // ── REJEITAR PAGAMENTO ────────────────────────────────────────────────
    if ($acao === 'rejeitar_pagamento') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id     = intval($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if (!$id) { echo json_encode(["sucesso" => false, "erro" => "ID inválido"]); exit; }

        $conexao->query("ALTER TABLE pagamentos_pendentes ADD COLUMN IF NOT EXISTS motivo_rejeicao TEXT DEFAULT NULL");

        $stmt = $conexao->prepare("UPDATE pagamentos_pendentes SET status='rejeitado', comprovante='', motivo_rejeicao=? WHERE id=? AND (status='pendente' OR status='')");
        $stmt->bind_param("si", $motivo, $id);
        echo $stmt->execute()
            ? json_encode(["sucesso" => true])
            : json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        exit;
    }

    // ── LOGS DE PAGAMENTOS VALIDADOS ──────────────────────────────────────
    if ($acao === 'logs_pagamentos') {
        exigirNivel(NIVEL_INSTRUTOR);
        checkRateLimit('logs_pagamentos', 20);

        $busca = trim($_POST['busca'] ?? '');

        if ($busca !== '') {
            $like = "%{$busca}%";
            $stmt = $conexao->prepare("SELECT id, usuario_id, nome, rgpm, discord, curso, mime_type, data_envio, validado_por, validado_por_rgpm, validado_em FROM pagamentos_pendentes WHERE status='validado' AND (nome LIKE ? OR rgpm LIKE ? OR discord LIKE ? OR curso LIKE ?) ORDER BY validado_em DESC, data_envio DESC");
            $stmt->bind_param("ssss", $like, $like, $like, $like);
        } else {
            $stmt = $conexao->prepare("SELECT id, usuario_id, nome, rgpm, discord, curso, mime_type, data_envio, validado_por, validado_por_rgpm, validado_em FROM pagamentos_pendentes WHERE status='validado' ORDER BY validado_em DESC, data_envio DESC");
        }
        $stmt->execute();
        $lista = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(["sucesso" => true, "logs" => $lista, "total" => count($lista)]);
        exit;
    }

    // ── VER COMPROVANTE DO LOG ────────────────────────────────────────────
    if ($acao === 'ver_comprovante_log') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $stmt = $conexao->prepare("SELECT comprovante, mime_type, nome, rgpm, LENGTH(comprovante) as tamanho FROM pagamentos_pendentes WHERE id=? AND status='validado'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !$row['tamanho']) {
            echo json_encode(["sucesso" => false, "erro" => "Comprovante não encontrado"]);
            exit;
        }
        if ($row['tamanho'] > 8 * 1024 * 1024) {
            echo json_encode(["sucesso" => false, "erro" => "Arquivo muito grande para exibição"]);
            exit;
        }
        echo json_encode([
            "sucesso" => true,
            "imagem"  => base64_encode($row['comprovante']),
            "mime"    => $row['mime_type'],
            "nome"    => $row['nome'],
            "rgpm"    => $row['rgpm'],
        ]);
        exit;
    }

    // ── FUNÇÃO AUXILIAR: busca tags completas de um curso ──
    if (!function_exists('buscarTagsCursoPorNome')) {
        function buscarTagsCursoPorNome($conexao, $nomeCursoAluno) {
            $colCheck = $conexao->query("SHOW COLUMNS FROM cursos LIKE 'tags_curso'");
            $temTagsCol = $colCheck && $colCheck->num_rows > 0;
            $sel = $temTagsCol
                ? "SELECT nome, tags_curso FROM cursos WHERE status='Aberto'"
                : "SELECT nome FROM cursos WHERE status='Aberto'";
            $res = $conexao->query($sel);
            if (!$res) return [];
            while ($c = $res->fetch_assoc()) {
                if (stripos($nomeCursoAluno, $c['nome']) !== false || stripos($c['nome'], $nomeCursoAluno) !== false
                    || trim(strtolower($nomeCursoAluno)) === trim(strtolower($c['nome']))) {
                    $tagPag = null;
                    foreach (['CFSD','CFC','CFS','CFO'] as $k) {
                        if (preg_match('/\b'.preg_quote($k,'/').'\b/i', $c['nome'])) {
                            $tagPag = 'Pagou ' . strtoupper($k); break;
                        }
                    }
                    $extras = [];
                    if ($temTagsCol && !empty($c['tags_curso'])) {
                        $extras = array_values(array_filter(array_map('trim', explode(',', $c['tags_curso']))));
                    }
                    $tags = [];
                    if ($tagPag) $tags[] = $tagPag;
                    foreach ($extras as $t) $tags[] = $t;
                    return $tags;
                }
            }
            foreach (['CFSD','CFC','CFS','CFO'] as $k) {
                if (preg_match('/\b'.preg_quote($k,'/').'\b/i', $nomeCursoAluno)) {
                    return ['Pagou ' . $k];
                }
            }
            return [];
        }
    }

    // ── VERIFICAR APROVAÇÃO ───────────────────────────────────────────────
    if ($acao === 'verificar_aprovacao') {
        exigirNivel(NIVEL_INSTRUTOR);

        $aprovados  = 0;
        $reprovados = 0;
        $todos = $conexao->query("SELECT id,tags,meus_cursos,status FROM usuarios WHERE meus_cursos IS NOT NULL AND meus_cursos!=''");
        while ($u = $todos->fetch_assoc()) {
            $userId    = intval($u['id']);
            $cursoUser = trim($u['meus_cursos']);

            $tagsCurso = buscarTagsCursoPorNome($conexao, $cursoUser);
            if (empty($tagsCurso)) continue;

            $tagsUser = [];
            if (!empty($u['tags'])) {
                $decoded  = json_decode($u['tags'], true);
                $tagsUser = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(',', $u['tags']))));
            }

            $temTodas = count(array_intersect($tagsCurso, $tagsUser)) === count($tagsCurso);
            if ($temTodas && $u['status'] !== 'Aprovado') {
                $upd = $conexao->prepare("UPDATE usuarios SET status='Aprovado' WHERE id=?");
                $upd->bind_param("i", $userId);
                if ($upd->execute()) $aprovados++;
            } elseif (!$temTodas && $u['status'] === 'Aprovado') {
                $upd = $conexao->prepare("UPDATE usuarios SET status='Pendente' WHERE id=?");
                $upd->bind_param("i", $userId);
                if ($upd->execute()) $reprovados++;
            }
        }
        echo json_encode(["sucesso" => true, "aprovados" => $aprovados, "revertidos" => $reprovados]);
        exit;
    }

    // ── BUSCAR USUÁRIO ────────────────────────────────────────────────────
    if ($acao === 'buscar_usuario') {
        exigirNivel(NIVEL_INSTRUTOR);
        checkRateLimit('buscar_usuario', 15);

        $termo = trim($_POST['termo'] ?? '');
        if (!$termo) {
            echo json_encode(["sucesso" => false, "erro" => "Informe o RGPM ou Discord"]);
            exit;
        }

        $like = "%{$termo}%";
        $stmt = $conexao->prepare("SELECT id,nome,rgpm,discord,meus_cursos,status,nivel,tags FROM usuarios WHERE rgpm LIKE ? OR discord LIKE ? OR nome LIKE ? LIMIT 10");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(["sucesso" => true, "usuarios" => $rows]);
        exit;
    }

    // ── BUSCAR ALUNO NÍVEL 3 (para setar/remover tags e ata de presença) ─
    if ($acao === 'buscar_aluno_nivel3') {
        exigirNivel(NIVEL_INSTRUTOR);
        checkRateLimit('buscar_aluno_nivel3', 20);
        $termo = trim($_POST['termo'] ?? '');
        if (!$termo) { echo json_encode(["sucesso" => false, "erro" => "Informe o RGPM ou Discord"]); exit; }
        $like = "%{$termo}%";
        $stmt = $conexao->prepare("SELECT id,nome,rgpm,discord,meus_cursos,status,tags FROM usuarios WHERE nivel=3 AND (rgpm LIKE ? OR discord LIKE ? OR nome LIKE ?) ORDER BY nome ASC LIMIT 10");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(["sucesso" => true, "usuarios" => $rows]);
        exit;
    }

    // ── BUSCAR AUXILIAR/INSTRUTOR NÍVEL 2 (para ata de presença) ─────────
    if ($acao === 'buscar_auxiliar_nivel2') {
        exigirNivel(NIVEL_INSTRUTOR);
        checkRateLimit('buscar_auxiliar_nivel2', 20);
        $termo = trim($_POST['termo'] ?? '');
        if (!$termo) { echo json_encode(["sucesso" => false, "erro" => "Informe o RGPM ou Discord"]); exit; }
        $like = "%{$termo}%";
        $stmt = $conexao->prepare("SELECT id,nome,rgpm,discord FROM usuarios WHERE nivel<=2 AND (rgpm LIKE ? OR discord LIKE ? OR nome LIKE ?) ORDER BY nome ASC LIMIT 10");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(["sucesso" => true, "usuarios" => $rows]);
        exit;
    }

    // ── EDITAR USUÁRIO ────────────────────────────────────────────────────
    if ($acao === 'editar_usuario') {
        exigirNivel(NIVEL_INSTRUTOR);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        if ($nivelSessao === NIVEL_INSTRUTOR) {
            $chkStmt = $conexao->prepare("SELECT nivel FROM usuarios WHERE id=?");
            $chkStmt->bind_param("i", $id);
            $chkStmt->execute();
            $chkRow = $chkStmt->get_result()->fetch_assoc();
            if ($chkRow && intval($chkRow['nivel']) < NIVEL_INSTRUTOR) {
                echo json_encode(["sucesso" => false, "erro" => "Instrutores não podem editar administradores"]);
                exit;
            }
            unset($_POST['nivel']);
        }

        $selfId = null;
        if (!empty($_SESSION['rgpm'])) {
            $selfStmt2 = $conexao->prepare("SELECT id FROM usuarios WHERE rgpm=? LIMIT 1");
            $selfStmt2->bind_param("s", $_SESSION['rgpm']);
            $selfStmt2->execute();
            $selfRow2 = $selfStmt2->get_result()->fetch_assoc();
            $selfId   = $selfRow2 ? intval($selfRow2['id']) : null;
        }
        if ($selfId && $selfId === $id && isset($_POST['nivel']) && intval($_POST['nivel']) > $nivelSessao) {
            echo json_encode(["sucesso" => false, "erro" => "Você não pode rebaixar seu próprio nível"]);
            exit;
        }

        $campos = [];
        $tipos = '';
        $vals = [];

        // ── Captura nível atual ANTES do UPDATE para comparar depois ─────────
        $nivelAntigoRow = null;
        if (isset($_POST['nivel'])) {
            $naStmt = $conexao->prepare("SELECT nivel FROM usuarios WHERE id=? LIMIT 1");
            $naStmt->bind_param("i", $id);
            $naStmt->execute();
            $nivelAntigoRow = $naStmt->get_result()->fetch_assoc();
        }

        foreach (['nome' => 's', 'rgpm' => 's', 'discord' => 's', 'nivel' => 's', 'status' => 's', 'meus_cursos' => 's'] as $campo => $tipo) {
            if (isset($_POST[$campo]) && $_POST[$campo] !== '') {
                $campos[] = "{$campo}=?";
                $tipos   .= $tipo;
                $vals[]   = trim($_POST[$campo]);
            }
        }
        if (array_key_exists('meus_cursos', $_POST) && $_POST['meus_cursos'] === '') {
            $campos[] = "meus_cursos=?";
            $tipos .= 's';
            $vals[] = '';
        }

        if (empty($campos)) {
            echo json_encode(["sucesso" => false, "erro" => "Nenhum campo para atualizar"]);
            exit;
        }

        $tipos .= 'i';
        $vals[] = $id;
        $stmt   = $conexao->prepare("UPDATE usuarios SET " . implode(',', $campos) . " WHERE id=?");
        $stmt->bind_param($tipos, ...$vals);
        if (!$stmt->execute()) {
            echo json_encode(["sucesso" => false, "erro" => "Erro interno"]);
            exit;
        }

        $selfStmt = $conexao->prepare("SELECT nome,rgpm,nivel FROM usuarios WHERE id=?");
        $selfStmt->bind_param("i", $id);
        $selfStmt->execute();
        $selfRow = $selfStmt->get_result()->fetch_assoc();

        // ── Se nível foi alterado, invalida a sessão do usuário editado ──────
        if (isset($_POST['nivel']) && $nivelAntigoRow && intval($nivelAntigoRow['nivel']) !== intval($_POST['nivel'])) {
            $conexao->query("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS session_invalidada TINYINT(1) DEFAULT 0");
            $invStmt = $conexao->prepare("UPDATE usuarios SET session_invalidada=1 WHERE id=?");
            $invStmt->bind_param("i", $id);
            $invStmt->execute();
        }
        $sesRgpm = $_SESSION['rgpm'] ?? null;
        $sessionAtualizada = false;
        $novoNome         = $_SESSION["usuario"];
        $novoRgpm         = $sesRgpm;
        $novoNivel        = $_SESSION["nivel"] ?? null;

        if ($selfRow && $sesRgpm && ($selfRow['rgpm'] === $sesRgpm || (isset($_POST['rgpm']) && trim($_POST['rgpm']) === $sesRgpm))) {
            if (!empty($selfRow['nome']))  $_SESSION["usuario"] = $selfRow['nome'];
            if (!empty($selfRow['rgpm']))  $_SESSION["rgpm"]    = $selfRow['rgpm'];
            if (!empty($selfRow['nivel'])) $_SESSION["nivel"]   = $selfRow['nivel'];
            $novoNome         = $_SESSION["usuario"];
            $novoRgpm         = $_SESSION["rgpm"];
            $novoNivel        = $_SESSION["nivel"];
            $sessionAtualizada = true;
        }
        echo json_encode(["sucesso" => true, "session_atualizada" => $sessionAtualizada, "novo_nome" => $novoNome, "novo_rgpm" => $novoRgpm, "novo_nivel" => $novoNivel]);
        exit;
    }

    // ── DELETAR USUÁRIO ───────────────────────────────────────────────────
    // ── ARQUIVAR PAGAMENTOS (chamado ANTES de deletar, quando admin escolhe salvar) ──
    if ($acao === 'arquivar_pagamentos') {
        exigirNivel(NIVEL_ADM);

        $uid            = (int) ($_POST['id']             ?? 0);
        $motivoExclusao = trim($_POST['motivo_exclusao']  ?? '');
        $admRgpm        = $_SESSION['rgpm'] ?? 'sistema';

        if (!$uid) {
            echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']);
            exit;
        }

        // Cria a tabela se ainda não existir (auto-setup)
        $conexao->query("CREATE TABLE IF NOT EXISTS `pagamentos_arquivo` (
            `id`              INT(11)       NOT NULL AUTO_INCREMENT,
            `tipo`            ENUM('inscricao','multa') NOT NULL DEFAULT 'inscricao',
            `usuario_id_orig` INT(11)       DEFAULT NULL,
            `nome`            VARCHAR(255)  NOT NULL DEFAULT '',
            `rgpm`            VARCHAR(50)   NOT NULL DEFAULT '',
            `discord`         VARCHAR(100)  DEFAULT NULL,
            `curso`           VARCHAR(255)  DEFAULT NULL,
            `valor`           DECIMAL(10,2) DEFAULT 0.00,
            `comprovante`     LONGBLOB      DEFAULT NULL,
            `mime_type`       VARCHAR(60)   DEFAULT 'image/jpeg',
            `status_original` VARCHAR(50)   DEFAULT NULL,
            `motivo_exclusao` TEXT          DEFAULT NULL,
            `arquivado_por`   VARCHAR(255)  DEFAULT NULL,
            `data_pagamento`  DATETIME      DEFAULT NULL,
            `data_arquivado`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $arquivados = 0;

        // ── 1. Arquiva pagamentos de inscrição (tabela pagamentos_pendentes) ──
        $pags = db_query($conexao,
            "SELECT id, nome, rgpm, discord, curso, comprovante, mime_type, status, data_envio
             FROM pagamentos_pendentes WHERE usuario_id = ?",
            'i', [$uid]
        ) ?: [];

        foreach ($pags as $p) {
            // Busca o valor real da taxa do curso
            $valorCurso = 0.00;
            if (!empty($p['curso'])) {
                $vc = db_val($conexao, "SELECT IFNULL(valor_taxa,0) FROM cursos WHERE nome = ? LIMIT 1", 's', [$p['curso']]);
                $valorCurso = floatval($vc ?? 0);
            }
            $ok = db_query($conexao,
                "INSERT INTO pagamentos_arquivo
                    (tipo, usuario_id_orig, nome, rgpm, discord, curso, valor, comprovante, mime_type, status_original, motivo_exclusao, arquivado_por, data_pagamento)
                 VALUES ('inscricao', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                'issssdssssss',
                [
                    $uid,
                    $p['nome']        ?? '',
                    $p['rgpm']        ?? '',
                    $p['discord']     ?? '',
                    $p['curso']       ?? '',
                    $valorCurso,
                    $p['comprovante'] ?? null,
                    $p['mime_type']   ?? 'image/jpeg',
                    $p['status']      ?? '',
                    $motivoExclusao,
                    $admRgpm,
                    $p['data_envio']  ?? null,
                ]
            );
            if ($ok) $arquivados++;
        }

        // ── 2. Arquiva pagamentos de multa (tabela multas com comprovante) ──
        $tblMultas = db_val($conexao, "SHOW TABLES LIKE 'multas'");
        if ($tblMultas !== null) {
            $multas = db_query($conexao,
                "SELECT id, nome_aluno, rgpm_aluno, discord_aluno, curso_aluno,
                        valor, comprovante_multa, mime_comprovante, status, aplicada_em
                 FROM multas WHERE usuario_id = ?",
                'i', [$uid]
            ) ?: [];

            foreach ($multas as $m) {
                $ok = db_query($conexao,
                    "INSERT INTO pagamentos_arquivo
                        (tipo, usuario_id_orig, nome, rgpm, discord, curso, valor, comprovante, mime_type, status_original, motivo_exclusao, arquivado_por, data_pagamento)
                     VALUES ('multa', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    'issssdssss',
                    [
                        $uid,
                        $m['nome_aluno']            ?? '',
                        $m['rgpm_aluno']            ?? '',
                        $m['discord_aluno']         ?? '',
                        $m['curso_aluno']           ?? '',
                        (float)($m['valor']         ?? 0),
                        $m['comprovante_multa'] ?? null,
                        $m['mime_comprovante']      ?? 'image/jpeg',
                        $m['status']                ?? '',
                        $motivoExclusao,
                        $admRgpm,
                        $m['aplicada_em']           ?? null,
                    ]
                );
                if ($ok) $arquivados++;
            }
        }

        echo json_encode(['sucesso' => true, 'arquivados' => $arquivados]);
        exit;
    }

    // ── LISTAR PAGAMENTOS ARQUIVADOS ──────────────────────────────────────────
    if ($acao === 'listar_arquivo') {
        exigirNivel(NIVEL_ADM);

        $busca = trim($_POST['busca'] ?? '');
        $tipo  = trim($_POST['tipo']  ?? '');

        $tblExiste = db_val($conexao, "SHOW TABLES LIKE 'pagamentos_arquivo'");
        if ($tblExiste === null) {
            echo json_encode(['sucesso' => true, 'registros' => [], 'total' => 0]);
            exit;
        }

        $where  = [];
        $tipos  = '';
        $params = [];

        if ($busca !== '') {
            $where[]  = '(nome LIKE ? OR rgpm LIKE ? OR discord LIKE ? OR curso LIKE ?)';
            $tipos   .= 'ssss';
            $like     = "%{$busca}%";
            $params   = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($tipo !== '') {
            $where[]  = 'tipo = ?';
            $tipos   .= 's';
            $params[] = $tipo;
        }

        $sql = 'SELECT id, tipo, nome, rgpm, discord, curso, valor, mime_type, status_original, motivo_exclusao, arquivado_por, data_pagamento, data_arquivado FROM pagamentos_arquivo';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY data_arquivado DESC LIMIT 200';

        $rows = db_query($conexao, $sql, $tipos, $params) ?: [];
        echo json_encode(['sucesso' => true, 'registros' => $rows, 'total' => count($rows)]);
        exit;
    }

    // ── VER COMPROVANTE ARQUIVADO ─────────────────────────────────────────────
    if ($acao === 'ver_comprovante_arquivo') {
        exigirNivel(NIVEL_ADM);
        $aid = (int) ($_POST['id'] ?? 0);
        if (!$aid) { echo json_encode(['sucesso' => false, 'erro' => 'ID inválido']); exit; }
        $row = db_row($conexao, 'SELECT comprovante, mime_type FROM pagamentos_arquivo WHERE id = ? LIMIT 1', 'i', [$aid]);
        if (!$row || empty($row['comprovante'])) {
            echo json_encode(['sucesso' => false, 'erro' => 'Comprovante não encontrado']);
            exit;
        }
        $b64 = base64_encode($row['comprovante']);
        echo json_encode(['sucesso' => true, 'imagem' => 'data:' . ($row['mime_type'] ?: 'image/jpeg') . ';base64,' . $b64]);
        exit;
    }

    if ($acao === 'deletar_usuario') {
        exigirNivel(NIVEL_ADM);

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["sucesso" => false, "erro" => "ID inválido"]);
            exit;
        }

        $selfCheck = $conexao->prepare("SELECT rgpm FROM usuarios WHERE id=?");
        $selfCheck->bind_param("i", $id);
        $selfCheck->execute();
        $selfCheckRow = $selfCheck->get_result()->fetch_assoc();
        if ($selfCheckRow && isset($_SESSION['rgpm']) && $selfCheckRow['rgpm'] === $_SESSION['rgpm']) {
            echo json_encode(["sucesso" => false, "erro" => "Você não pode excluir sua própria conta"]);
            exit;
        }

        // Busca dados completos para o frontend oferecer opção de blacklist
        $dadosUsuario = null;
        $dadosStmt = $conexao->prepare("SELECT nome, rgpm, discord, ip_publico,
            fp_user_agent, fp_idioma, fp_timezone, fp_resolucao, fp_plataforma,
            fp_canvas_hash, fp_webgl_hash, fp_audio_hash, fp_fonts
            FROM usuarios WHERE id=? LIMIT 1");
        if ($dadosStmt) {
            $dadosStmt->bind_param("i", $id);
            if ($dadosStmt->execute()) {
                $dadosUsuario = $dadosStmt->get_result()->fetch_assoc();
            }
        }
        if (!$dadosUsuario) {
            $dadosStmt2 = $conexao->prepare("SELECT nome, rgpm, discord, ip_publico FROM usuarios WHERE id=? LIMIT 1");
            if ($dadosStmt2) {
                $dadosStmt2->bind_param("i", $id);
                $dadosStmt2->execute();
                $dadosUsuario = $dadosStmt2->get_result()->fetch_assoc();
            }
        }

        $stmt = $conexao->prepare("DELETE FROM usuarios WHERE id=?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode([
                "sucesso"  => true,
                "usuario"  => $dadosUsuario
            ]);
        } else {
            echo json_encode(["sucesso" => false, "erro" => "Erro interno"]);
        }
        exit;
    }

    // ── BLACKLIST ACTIONS ─────────────────────────────────────────────────────
    if (in_array($acao, ['adicionar_blacklist', 'listar_blacklist', 'remover_blacklist', 'verificar_blacklist_cadastro'], true)) {
        require __DIR__ . '/adm_script/blacklist_actions.php';
        exit;
    }

    // ── MULTAS ACTIONS ────────────────────────────────────────────────────────
    if (in_array($acao, ['multa_buscar_aluno','aplicar_multa','multa_listar_pendentes_adm','multa_ver_comprovante','multa_validar','multa_negar','multa_logs','multa_deletar','multa_log_deletar','multa_logs_apagar_todos','multa_editar','multa_limpar_nao_pagas'], true)) {
        require __DIR__ . '/multas_actions.php';
        exit;
    }

    // ── SETAR / REMOVER TAGS ──────────────────────────────────────────────
    exigirNivel(NIVEL_INSTRUTOR);

    if (!$curso || !$tag || !$raw) {
        echo json_encode(["sucesso" => false, "erro" => "Parametros incompletos"]);
        exit;
    }

    $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), function($v) { return $v !== ''; }));
    if (empty($ids)) {
        echo json_encode(["sucesso" => false, "erro" => "Nenhum ID fornecido"]);
        exit;
    }

    $atualizados = 0;
    $nao_encontrados = [];

    $usarId = !empty($_POST['usar_id']);

    foreach ($ids as $id) {
        if ($usarId) {
            // Busca pelo ID interno do banco — mais preciso e sem risco de RGPM errado
            $intId = intval($id);
            $stmt  = $conexao->prepare("SELECT id,tags,meus_cursos,status FROM usuarios WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $intId);
        } else {
            // Fallback: busca por RGPM ou Discord
            $stmt = $conexao->prepare("SELECT id,tags,meus_cursos,status FROM usuarios WHERE (rgpm=? OR discord=?) LIMIT 1");
            $stmt->bind_param("ss", $id, $id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $nao_encontrados[] = $id;
            continue;
        }

        $user   = $result->fetch_assoc();
        $userId = intval($user['id']);

        $tagsAtuais = [];
        if (!empty($user['tags'])) {
            $decoded    = json_decode($user['tags'], true);
            $tagsAtuais = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(',', $user['tags']))));
        }
        if ($acao === 'setar_tags') {
            if (!in_array($tag, $tagsAtuais)) $tagsAtuais[] = $tag;
        } else {
            $tagsAtuais = array_values(array_filter($tagsAtuais, function($t) use ($tag) { return $t !== $tag; }));
        }

        $novasTags = json_encode(array_values($tagsAtuais), JSON_UNESCAPED_UNICODE);
        $upd       = $conexao->prepare("UPDATE usuarios SET tags=? WHERE id=?");
        $upd->bind_param("si", $novasTags, $userId);
        if (!$upd->execute()) {
            $nao_encontrados[] = $id;
            continue;
        }
        $atualizados++;

        $cursoUser = trim($user['meus_cursos'] ?? '');
        $tagsCurso = buscarTagsCursoPorNome($conexao, $cursoUser);
        if (!empty($tagsCurso)) {
            $temTodas = count(array_intersect($tagsCurso, $tagsAtuais)) === count($tagsCurso);
            if ($temTodas && $user['status'] !== 'Aprovado') {
                $aprovar = $conexao->prepare("UPDATE usuarios SET status='Aprovado' WHERE id=?");
                $aprovar->bind_param("i", $userId);
                $aprovar->execute();
            } elseif (!$temTodas && $user['status'] === 'Aprovado') {
                $reverter = $conexao->prepare("UPDATE usuarios SET status='Pendente' WHERE id=?");
                $reverter->bind_param("i", $userId);
                $reverter->execute();
            }
        }
    }
    // Debug: verifica se o ID existe sem filtro de nivel
    $debugInfo = [];
    foreach ($ids as $id) {
        $intId = intval($id);
        $chk = $conexao->prepare("SELECT id, rgpm, nivel FROM usuarios WHERE id=? LIMIT 1");
        $chk->bind_param("i", $intId);
        $chk->execute();
        $chkRow = $chk->get_result()->fetch_assoc();
        $debugInfo[] = ["id_buscado" => $intId, "encontrado" => $chkRow ? true : false, "nivel" => $chkRow['nivel'] ?? null, "rgpm" => $chkRow['rgpm'] ?? null];
    }
    echo json_encode([
        "sucesso"               => true,
        "atualizados"           => $atualizados,
        "nao_encontrados"       => count($nao_encontrados),
        "lista_nao_encontrados" => $nao_encontrados,
        "usar_id"               => $usarId,
        "debug"                 => $debugInfo,
    ]);
    exit;
}

// ═══════════════════════ PÁGINA NORMAL ════════════════════════════════════
require "adm_script/alistados.php";

if (!isset($cfc)) {
    $stmtCfc = $conexao->query("SELECT COUNT(*) as total FROM usuarios WHERE meus_cursos REGEXP '[[:<:]]CFC[[:>:]]'");
    $cfc      = $stmtCfc ? intval($stmtCfc->fetch_assoc()['total']) : 0;
}
if (!isset($cfsd) || !isset($cfs) || !isset($cfo)) {
    $stmtCfsd = $conexao->query("SELECT COUNT(*) as total FROM usuarios WHERE meus_cursos REGEXP '[[:<:]]CFSD[[:>:]]'");
    $cfsd     = $stmtCfsd ? intval($stmtCfsd->fetch_assoc()['total']) : ($cfsd ?? 0);
    $stmtCfs  = $conexao->query("SELECT COUNT(*) as total FROM usuarios WHERE meus_cursos REGEXP '[[:<:]]CFS[[:>:]]' AND meus_cursos NOT REGEXP '[[:<:]]CFSD[[:>:]]'");
    $cfs      = $stmtCfs  ? intval($stmtCfs->fetch_assoc()['total'])  : ($cfs  ?? 0);
    $stmtCfo  = $conexao->query("SELECT COUNT(*) as total FROM usuarios WHERE meus_cursos REGEXP '[[:<:]]CFO[[:>:]]'");
    $cfo      = $stmtCfo  ? intval($stmtCfo->fetch_assoc()['total'])  : ($cfo  ?? 0);
}

$qPend         = $conexao->query("SELECT COUNT(*) as total FROM pagamentos_pendentes WHERE (status='pendente' OR status='') AND IFNULL(tipo_pagamento,'taxa')='taxa'");
$totalPendentes = $qPend ? intval($qPend->fetch_assoc()['total']) : 0;

$colTagsCheck = $conexao->query("SHOW COLUMNS FROM cursos LIKE 'tags_curso'");
$temColTags   = $colTagsCheck && $colTagsCheck->num_rows > 0;

$tagsPorCursoDinamico = [];
$cursosAbertosNomes   = [];

$selCursos = $temColTags
    ? "SELECT nome, tags_curso FROM cursos WHERE status='Aberto' ORDER BY nome ASC"
    : "SELECT nome FROM cursos WHERE status='Aberto' ORDER BY nome ASC";

$qCursosAbertos = $conexao->query($selCursos);
if ($qCursosAbertos) {
    while ($r = $qCursosAbertos->fetch_assoc()) {
        $nomeCurso = $r['nome'];
        $cursosAbertosNomes[] = $nomeCurso;

        $tagPagCurso = null;
        foreach (['CFSD','CFC','CFS','CFO'] as $k) {
            if (preg_match('/\b'.preg_quote($k,'/').'\b/i', $nomeCurso)) {
                $tagPagCurso = 'Pagou ' . strtoupper($k);
                break;
            }
        }

        $tagsExtras = [];
        if ($temColTags && !empty($r['tags_curso'])) {
            $tagsExtras = array_values(array_filter(array_map('trim', explode(',', $r['tags_curso']))));
        }

        $tagsFinal = [];
        if ($tagPagCurso) $tagsFinal[] = $tagPagCurso;
        foreach ($tagsExtras as $t) $tagsFinal[] = $t;

        if (!empty($tagsFinal)) {
            $tagsPorCursoDinamico[$nomeCurso] = $tagsFinal;
        }
    }
}

$cursosTagsDisponiveisJson = json_encode(array_keys($tagsPorCursoDinamico), JSON_UNESCAPED_UNICODE);
$tagsPorCursoDinamicoJson  = json_encode($tagsPorCursoDinamico, JSON_UNESCAPED_UNICODE);

$queryAprovados = "SELECT u.nome,u.rgpm,u.discord,u.meus_cursos FROM usuarios u INNER JOIN cursos c ON TRIM(c.nome)=TRIM(u.meus_cursos) WHERE u.status='Aprovado' AND c.status='Aberto' ORDER BY u.meus_cursos ASC,u.nome ASC";
$resAprovados   = $conexao->query($queryAprovados);
$listaAprovados = [];
if ($resAprovados && $resAprovados->num_rows > 0) {
    while ($row = $resAprovados->fetch_assoc()) $listaAprovados[] = $row;
}
$totalAprovados = count($listaAprovados);

$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
    <title>Painel DEC · PMESP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy: #0d1b2e;
            --navy2: #112240;
            --blue: #1a56db;
            --blue2: #1e40af;
            --blue-lt: #3b82f6;
            --bg: #f1f5f9;
            --white: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
            --light: #f8fafc;
            --red: #dc2626;
            --red-pale: #fef2f2;
            --green: #15803d;
            --green-pale: #dcfce7;
            --yellow-pale: #fef3c7;
            --yellow: #92400e;
            --sidebar-w: 255px
        }
        * { box-sizing: border-box; margin: 0; padding: 0 }
        body { background: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); height: 100vh; display: flex; overflow: hidden }
        .sidebar { width: var(--sidebar-w); background: var(--navy); display: flex; flex-direction: column; flex-shrink: 0; box-shadow: 4px 0 20px rgba(0,0,0,0.25); z-index: 100; transition: transform .3s ease }
        .sidebar-logo { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px }
        .sidebar-logo-icon { width: 40px; height: 40px; background: var(--blue); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0 }
        .brand { font-size: 16px; font-weight: 800; color: #fff; letter-spacing: .02em }
        .brand span { color: #60a5fa }
        .sub { font-size: 10px; color: rgba(255,255,255,0.35); font-weight: 500; letter-spacing: .03em; margin-top: 2px }
        nav { flex: 1; overflow-y: auto; padding: 12px 0 }
        nav::-webkit-scrollbar { width: 3px }
        nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px }
        .nav-section { padding: 14px 20px 5px; font-size: 9.5px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(255,255,255,0.25) }
        .nav-link { display: flex; align-items: center; gap: 11px; padding: 10px 20px; margin: 1px 10px; border-radius: 8px; cursor: pointer; color: rgba(255,255,255,0.5); font-size: 13px; font-weight: 500; transition: all .18s; border: 1px solid transparent }
        .nav-icon { width: 30px; height: 30px; border-radius: 7px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.06); font-size: 12px; flex-shrink: 0; transition: all .18s }
        .nav-num { font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.2); margin-left: auto }
        .nav-link:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.85) }
        .nav-link:hover .nav-icon { background: rgba(26,86,219,0.3); color: #93c5fd }
        .nav-link.active { background: var(--blue); color: #fff; border-color: rgba(255,255,255,0.1); box-shadow: 0 4px 14px rgba(26,86,219,0.4) }
        .nav-link.active .nav-icon { background: rgba(255,255,255,0.2); color: #fff }
        .nav-link.active .nav-num { color: rgba(255,255,255,0.5) }
        .sidebar-footer { padding: 14px 20px; border-top: 1px solid rgba(255,255,255,0.07); font-size: 10px; color: rgba(255,255,255,0.2) }
        main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0 }
        header { height: 60px; background: var(--white); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; flex-shrink: 0; box-shadow: 0 1px 6px rgba(0,0,0,0.06) }
        .header-left { display: flex; align-items: center; gap: 10px }
        .header-breadcrumb { font-size: 11px; color: var(--muted); font-weight: 500 }
        .header-title { font-size: 15px; font-weight: 700; color: var(--text) }
        .header-sep { width: 1px; height: 16px; background: var(--border) }
        .header-right { display: flex; align-items: center; gap: 8px }
        .user-chip { display: flex; align-items: center; gap: 8px; background: var(--light); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; color: var(--text) }
        .avatar { width: 26px; height: 26px; background: var(--blue); border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 11px; font-weight: 700 }
        .btn-logout { display: flex; align-items: center; gap: 6px; background: var(--red-pale); color: var(--red); border: 1px solid #fecaca; border-radius: 8px; padding: 7px 14px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .18s; font-family: 'Inter', sans-serif }
        .btn-logout:hover { background: #fee2e2 }
        .hamburger { display: none; background: none; border: none; cursor: pointer; padding: 6px; border-radius: 8px; color: var(--text) }
        #viewport { flex: 1; overflow-y: auto; background: var(--bg); padding: 20px; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent }
        #viewport::-webkit-scrollbar { width: 5px }
        #viewport::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px }
        .section-view { display: none }
        .section-view.active { display: block; animation: fadeUp .25s ease }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(6px) } to { opacity: 1; transform: translateY(0) } }
        .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,0.05); margin-bottom: 20px }
        .card:last-child { margin-bottom: 0 }
        .card-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 20px; border-bottom: 1px solid var(--border); background: var(--light) }
        .ch-left { display: flex; align-items: center; gap: 12px }
        .ch-icon { width: 34px; height: 34px; background: var(--blue); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 13px; flex-shrink: 0 }
        .ch-icon.green { background: #16a34a }
        .ch-icon.indigo { background: #4338ca }
        .ch-icon.amber { background: #d97706 }
        .ch-title { font-size: 14px; font-weight: 700; color: var(--text) }
        .ch-sub { font-size: 11px; color: var(--muted); margin-top: 1px }
        .card-body { padding: 20px }
        .info-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1px; background: var(--border); border-radius: 10px; overflow: hidden }
        .info-cell { background: var(--white); padding: 16px 20px }
        .ic-label { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 5px }
        .ic-value { font-size: 15px; font-weight: 700; color: var(--text) }
        .course-stats-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(240px,1fr)); gap: 14px; margin-bottom: 18px }
        .csg-card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04) }
        .csg-header { background: var(--navy2); padding: 10px 16px; display: flex; align-items: center; gap: 8px }
        .csg-header span { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,0.5) }
        .csg-body { padding: 14px 16px }
        .csg-value { font-size: 1.3rem; font-weight: 800; color: var(--text) }
        .csg-sub { font-size: 11px; color: var(--muted); margin-top: 3px }
        .csg-row { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); font-size: 11px }
        .total-card { background: var(--navy); border-radius: 12px; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 12px }
        .total-label { font-size: 10px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: rgba(255,255,255,0.4); margin-bottom: 4px }
        .total-value { font-size: 1.8rem; font-weight: 800; color: #fff }
        .total-right { text-align: right; font-size: 11px; color: rgba(255,255,255,0.45); line-height: 1.9 }
        .total-right span { color: rgba(255,255,255,0.85) }
        .tbl-wrap { background: var(--white); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,0.05); margin-bottom: 20px }
        .tbl-header { padding: 12px 18px; border-bottom: 1px solid var(--border); background: var(--light); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px }
        .dec-table { width: 100%; border-collapse: collapse; font-size: 13px }
        .dec-table thead tr { background: var(--light) }
        .dec-table th { padding: 10px 14px; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap }
        .dec-table td { padding: 11px 14px; border-bottom: 1px solid #f1f5f9; color: var(--muted) }
        .dec-table td.strong { font-weight: 600; color: var(--text) }
        .dec-table tr:last-child td { border-bottom: none }
        .dec-table tbody tr:hover td { background: #f8fafc }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 700 }
        .badge-green { background: var(--green-pale); color: var(--green); border: 1px solid #bbf7d0 }
        .badge-blue { background: #dbeafe; color: var(--blue2); border: 1px solid #bfdbfe }
        .badge-indigo { background: #ede9fe; color: #4338ca; border: 1px solid #ddd6fe }
        .badge-red { background: var(--red-pale); color: var(--red); border: 1px solid #fecaca }
        .badge-yellow { background: var(--yellow-pale); color: var(--yellow); border: 1px solid #fde68a }
        .badge-gray { background: var(--light); color: var(--muted); border: 1px solid var(--border) }
        .dec-input { width: 100%; padding: 9px 13px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; color: var(--text); background: var(--white); outline: none; transition: border-color .18s, box-shadow .18s }
        .dec-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,86,219,0.1) }
        .dec-input::placeholder { color: var(--muted) }
        .dec-select { width: 100%; padding: 9px 32px 9px 13px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; color: var(--text); background: var(--white); outline: none; transition: border-color .18s, box-shadow .18s; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 11px center }
        .dec-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,86,219,0.1) }
        .dec-textarea { width: 100%; padding: 9px 13px; border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; color: var(--text); background: var(--white); outline: none; resize: none; transition: border-color .18s, box-shadow .18s }
        .dec-textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,86,219,0.1) }
        .field-label { display: block; font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px }
        .btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 18px; background: var(--blue); color: white; border: none; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .18s; width: 100% }
        .btn-primary:hover { background: var(--blue2); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(26,86,219,0.3) }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; transform: none; box-shadow: none }
        .btn-danger { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 18px; background: var(--red); color: white; border: none; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .18s; width: 100% }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px) }
        .btn-danger:disabled { opacity: .55; cursor: not-allowed; transform: none }
        .btn-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 18px; background: var(--light); color: var(--muted); border: 1px solid var(--border); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .18s }
        .btn-secondary:hover { background: var(--border); color: var(--text) }
        .btn-dl { display: inline-flex; align-items: center; gap: 5px; background: var(--green-pale); color: var(--green); border: 1px solid #bbf7d0; border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700; text-decoration: none; transition: all .18s }
        .btn-dl:hover { background: #bbf7d0 }
        .btn-del-sm { display: inline-flex; align-items: center; gap: 4px; background: var(--red-pale); color: var(--red); border: 1px solid #fecaca; border-radius: 8px; padding: 6px 10px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all .18s; font-family: 'Inter', sans-serif }
        .btn-del-sm:hover { background: #fee2e2 }
        .fab-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 50; display: flex; flex-direction: column; align-items: flex-end; gap: 8px }
        .fab-main { width: 50px; height: 50px; background: var(--blue); border: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; cursor: pointer; box-shadow: 0 4px 16px rgba(26,86,219,0.45); transition: all .2s }
        .fab-main:hover { background: var(--blue2); transform: scale(1.06) }
        .fab-menu { display: flex; flex-direction: column; align-items: flex-end; gap: 7px; opacity: 0; pointer-events: none; transform: translateY(12px); transition: all .25s }
        .fab-menu.open { opacity: 1; pointer-events: all; transform: translateY(0) }
        .fab-option { display: flex; align-items: center; gap: 9px; background: var(--white); border: 1px solid var(--border); color: var(--text); font-size: 12.5px; font-weight: 600; padding: 9px 14px; border-radius: 30px; cursor: pointer; white-space: nowrap; box-shadow: 0 4px 14px rgba(0,0,0,0.1); transition: all .18s }
        .fab-option:hover { background: var(--navy); color: white; border-color: var(--navy2) }

        /* ── MODAL BASE ── */
        .dec-modal-bd { position: fixed; inset: 0; background: rgba(13,27,46,0.55); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px }
        /* REMOVIDO backdrop-filter do dec-modal-bd para não criar stacking context que bloqueia modais filhos */
        .dec-modal { background: var(--white); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 560px; max-height: 92vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.2) }
        .dec-modal-header { background: var(--light); border-bottom: 1px solid var(--border); padding: 16px 20px; display: flex; align-items: center; justify-content: space-between }
        .dec-modal-title { font-size: 15px; font-weight: 800; color: var(--text) }
        .dec-modal-sub { font-size: 11px; color: var(--muted); margin-top: 2px }
        .dec-modal-body { padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px }
        .dec-modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); background: var(--light) }
        .modal-close { width: 30px; height: 30px; border: 1px solid var(--border); background: var(--white); border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--muted); transition: all .18s }
        .modal-close:hover { background: var(--red-pale); color: var(--red); border-color: #fecaca }
        .tag-pill { display: flex; align-items: center; gap: 7px; padding: 9px 13px; border-radius: 8px; border: 1px solid var(--border); background: var(--light); color: var(--muted); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: all .15s; text-align: left }
        .tag-pill:hover { border-color: var(--blue); color: var(--text); background: #dbeafe }
        .busca-result-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 13px; transition: background .14s; }
        .busca-result-item:hover { background: #f0f7ff; }
        .busca-result-item:last-child { border-bottom: none; }
        .tag-pill.selected { border-color: var(--blue); background: #dbeafe; color: var(--blue2); font-weight: 700 }
        .preview-box { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 13px }
        .preview-label { font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 7px }
        .sec-title { font-size: 18px; font-weight: 800; color: var(--text); margin-bottom: 18px; display: flex; align-items: center; gap: 10px }
        .sec-title::before { content: ''; width: 4px; height: 22px; background: var(--blue); border-radius: 3px }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--muted) }
        .empty-state i { font-size: 2rem; display: block; margin-bottom: 10px; color: #cbd5e1 }
        .empty-state p { font-size: 13px }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99 }
        #toast { position: fixed; bottom: 80px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none }
        .toast-item { background: var(--navy); color: white; padding: 11px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 20px rgba(0,0,0,0.3); opacity: 0; transform: translateX(30px); transition: all .3s; pointer-events: none }
        .toast-item.show { opacity: 1; transform: translateX(0) }
        .toast-item.success { border-left: 4px solid #22c55e }
        .toast-item.error { border-left: 4px solid var(--red) }
        .toast-item.info { border-left: 4px solid var(--blue-lt) }

        /* ── MODAL DE CONFIRMAÇÃO — z-index altíssimo, sem backdrop-filter ── */
        #vp-confirm-modal {
            position: fixed;
            inset: 0;
            background: rgba(13,27,46,0.7);
            z-index: 99999;  /* bem acima de tudo */
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        #vp-sucesso-modal {
            position: fixed;
            inset: 0;
            background: rgba(13,27,46,0.7);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        @media(max-width:768px) {
            body { overflow: auto; height: auto; min-height: 100vh; flex-direction: column }
            .sidebar { position: fixed; top: 0; left: 0; height: 100%; transform: translateX(-100%); z-index: 200 }
            .sidebar.open { transform: translateX(0) }
            .sidebar-overlay.active { display: block }
            .hamburger { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px }
            main { width: 100% }
            #viewport { overflow-y: auto; padding: 14px }
            .course-stats-grid { grid-template-columns: 1fr }
            .info-grid { grid-template-columns: 1fr }
            .total-card { flex-direction: column; align-items: flex-start }
            .total-right { text-align: left }
            .dec-modal { margin: 8px; max-width: calc(100vw - 16px); max-height: 95vh }
            .dec-table { font-size: 12px }
            .dec-table th, .dec-table td { padding: 9px 10px }
            .tbl-header { flex-direction: column; align-items: flex-start }
            .fab-wrap { bottom: 16px; right: 16px }
            .user-chip .chip-name { display: none }
            header { padding: 0 14px }
            .header-title { font-size: 13px }
        }
        @media print { .no-print { display: none !important } }
    </style>
</head>
<body>
    <div id="toast"></div>
    <div class="sidebar-overlay no-print" id="sidebar-overlay" onclick="closeSidebar()"></div>

    <aside class="sidebar no-print" id="sidebar">
        <div class="sidebar-logo">
            <div class="sidebar-logo-icon"><i class="fas fa-shield-alt" style="color:white;font-size:16px;"></i></div>
            <div>
                <div class="brand">DEC <span>PMESP</span></div>
                <div class="sub">Diretoria de Ensino e Cultura</div>
            </div>
        </div>
        <nav>
            <div onclick="router('dashboard')" class="nav-link active" id="nav-dashboard">
                <div class="nav-icon"><i class="fas fa-home"></i></div>Dashboard
            </div>
            <div class="nav-section">Operacional</div>
            <div onclick="router('administracao')" class="nav-link" id="nav-administracao">
                <div class="nav-icon"><i class="fas fa-cogs"></i></div>Administração<span class="nav-num">01</span>
            </div>
            <div onclick="router('membros')" class="nav-link" id="nav-membros">
                <div class="nav-icon"><i class="fas fa-users"></i></div>Membros<span class="nav-num">02</span>
            </div>
            <div onclick="router('presenca')" class="nav-link" id="nav-presenca">
                <div class="nav-icon"><i class="fas fa-check-double"></i></div>Presença<span class="nav-num">03</span>
            </div>
            <div onclick="router('escala')" class="nav-link" id="nav-escala">
                <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>Cronogramas<span class="nav-num">04</span>
            </div>
            <?php if ($nivelSessao <= NIVEL_ADM): ?>
            <div onclick="router('blacklist')" class="nav-link" id="nav-blacklist">
                <div class="nav-icon" style="background:rgba(220,38,38,0.2);"><i class="fas fa-ban" style="color:#fca5a5;"></i></div>Blacklist<span class="nav-num">05</span>
            </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">© 2026 DEC — Diretoria de Ensino e Cultura</div>
    </aside>

    <main>
        <header class="no-print">
            <div class="header-left">
                <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars" style="font-size:16px;"></i></button>
                <span class="header-breadcrumb">DEC PMESP</span>
                <div class="header-sep" id="hdr-sep-d"></div>
                <span class="header-title" id="view-title">Dashboard</span>
            </div>
            <div class="header-right">
                <div class="user-chip">
                    <div class="avatar"><?php echo strtoupper(substr($_SESSION["usuario"] ?? 'A', 0, 1)); ?></div>
                    <span class="chip-name"><?php echo htmlspecialchars($_SESSION["usuario"] ?? ''); ?></span>
                </div>
                <button class="btn-logout" onclick="window.location.href='logout.php'">
                    <i class="fas fa-sign-out-alt"></i><span class="chip-name" style="margin-left:4px;">Sair</span>
                </button>
            </div>
        </header>

        <div id="viewport">

            <!-- ═══ DASHBOARD ═══════════════════════════════════════════════════ -->
            <section id="dashboard" class="section-view active">
                <div style="margin-bottom:18px;">
                    <div style="font-size:20px;font-weight:800;color:var(--text);">Bem-vindo, <?php echo htmlspecialchars($_SESSION["usuario"] ?? ''); ?></div>
                    <div style="font-size:13px;color:var(--muted);margin-top:3px;">Painel de controle · DEC PMESP · <?php echo date('d/m/Y'); ?></div>
                </div>
                <div class="card" style="margin-bottom:16px;">
                    <div class="card-header">
                        <div class="ch-left">
                            <div class="ch-icon"><i class="fas fa-id-badge"></i></div>
                            <div>
                                <div class="ch-title">Informações do Usuário</div>
                                <div class="ch-sub">Dados da sua conta</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="info-grid">
                            <div class="info-cell">
                                <div class="ic-label">Nome</div>
                                <div class="ic-value"><?php echo htmlspecialchars($_SESSION["usuario"] ?? ''); ?></div>
                            </div>
                            <div class="info-cell">
                                <div class="ic-label">RGPM</div>
                                <div class="ic-value"><?php echo htmlspecialchars($_SESSION["rgpm"] ?? "N/A"); ?></div>
                            </div>
                            <div class="info-cell">
                                <div class="ic-label">Nível</div>
                                <div class="ic-value" style="color:var(--blue);"><?php echo htmlspecialchars($_SESSION["nivel"] ?? "N/A"); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ ADMINISTRAÇÃO ════════════════════════════════════════════════ -->
            <section id="administracao" class="section-view">
                <div class="sec-title">Administração</div>
                <div id="course-stats-grid" class="course-stats-grid"></div>
                <div class="total-card" id="total-card-geral">
                    <div>
                        <div class="total-label"><i class="fas fa-coins" style="margin-right:5px;color:#fbbf24;"></i>Arrecadação Total Geral</div>
                        <div class="total-value" id="total-geral-valor">R$ 0</div>
                    </div>
                    <div class="total-right" id="total-right-detalhes"></div>
                </div>
                <div class="tbl-wrap">
                    <div class="tbl-header">
                        <div class="ch-left">
                            <div class="ch-icon green" style="width:32px;height:32px;border-radius:8px;font-size:13px;"><i class="fas fa-user-check"></i></div>
                            <div>
                                <div style="font-size:14px;font-weight:700;color:var(--text);">Alunos Aprovados</div>
                                <div style="font-size:11px;color:var(--muted);">Aprovados em cursos <span style="color:#16a34a;font-weight:700;">Abertos</span></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="badge badge-green" id="badge-total-aprovados"><?php echo $totalAprovados; ?> aprovado(s)</span>
                            <button onclick="baixarAprovadosPDF()" style="display:flex;align-items:center;gap:5px;background:var(--yellow-pale);border:1px solid #fde68a;color:var(--yellow);font-size:11px;font-weight:700;padding:6px 12px;border-radius:8px;cursor:pointer;font-family:'Inter',sans-serif;"><i class="fas fa-file-pdf"></i> Exportar PDF</button>
                        </div>
                    </div>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;background:var(--light);flex-wrap:wrap;">
                        <select id="filtro_aprovados" onchange="filtrarAprovados()" class="dec-select" style="width:180px;min-width:140px;">
                            <option value="">Todos os cursos</option>
                            <?php $qFiltro = $conexao->query("SELECT DISTINCT nome FROM cursos WHERE status='Aberto' ORDER BY nome ASC");
                            if ($qFiltro && $qFiltro->num_rows > 0) {
                                while ($rf = $qFiltro->fetch_assoc()) {
                                    $n = htmlspecialchars($rf['nome']);
                                    echo "<option value=\"{$n}\">{$n}</option>";
                                }
                            } ?>
                        </select>
                        <input type="text" id="busca_aprovados" onkeyup="filtrarAprovados()" placeholder="Buscar por nome, RGPM ou Discord..." class="dec-input" style="flex:1;min-width:180px;">
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="dec-table">
                            <thead>
                                <tr>
                                    <th>#</th><th>Nome</th><th>RGPM</th><th>Discord</th><th>Curso</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-aprovados">
                                <?php if ($totalAprovados > 0): foreach ($listaAprovados as $i => $a): ?>
                                    <tr class="linha-aprovado" data-curso="<?php echo htmlspecialchars($a['meus_cursos'] ?? ''); ?>" data-texto="<?php echo strtolower(htmlspecialchars($a['nome'] . ' ' . $a['rgpm'] . ' ' . ($a['discord'] ?? ''))); ?>">
                                        <td style="color:var(--muted);font-size:12px;"><?php echo $i + 1; ?></td>
                                        <td class="strong"><?php echo htmlspecialchars($a['nome']); ?></td>
                                        <td style="font-family:monospace;"><?php echo htmlspecialchars($a['rgpm']); ?></td>
                                        <td><?php echo htmlspecialchars($a['discord'] ?? '—'); ?></td>
                                        <td><?php $cu = trim($a['meus_cursos'] ?? '');
                                            $cl = (strpos($cu, 'CFSD') !== false) ? 'badge-green' : ((strpos($cu, 'CFO') !== false) ? 'badge-indigo' : ((strpos($cu, 'CFS') !== false) ? 'badge-blue' : 'badge-gray')); ?>
                                            <span class="badge <?php echo $cl; ?>"><?php echo htmlspecialchars($cu ?: '—'); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="5"><div class="empty-state"><i class="fas fa-user-slash"></i><p>Nenhum aluno aprovado.</p></div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="padding:9px 16px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--muted);background:var(--light);flex-wrap:wrap;gap:4px;">
                        <span id="contador_aprovados">Exibindo <?php echo $totalAprovados; ?> registro(s)</span>
                        <span>DEC · <?php echo date('d/m/Y H:i'); ?></span>
                    </div>
                </div>

                <!-- FAB ADM -->
                <div id="fab-adm" style="display:none;" class="fab-wrap no-print">
                    <div id="fab-adm-menu" class="fab-menu">
                        <button onclick="adm_opcao1()" class="fab-option"><span>Setar Tags</span><i class="fas fa-tags" style="color:var(--blue);"></i></button>
                        <button onclick="adm_opcao2()" class="fab-option"><span>Remover Tags</span><i class="fas fa-tag" style="color:var(--red);"></i></button>
                        <button onclick="adm_opcao3()" class="fab-option"><span>Editar Curso</span><i class="fas fa-edit" style="color:#d97706;"></i></button>
                        <button onclick="adm_opcao5()" class="fab-option"><span>Eliminar do Curso</span><i class="fas fa-user-minus" style="color:#dc2626;"></i></button>
                        <button onclick="adm_opcao6()" class="fab-option" id="fab-btn-pagamentos" style="<?php echo $totalPendentes > 0 ? 'border-color:#fde68a;background:#fffbeb;' : ''; ?>">
                            <span>Validar Pagamentos</span>
                            <span id="fab-pend-badge" style="background:#dc2626;color:white;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:800;<?php echo $totalPendentes > 0 ? '' : 'display:none'; ?>"><?php echo $totalPendentes; ?></span>
                            <i class="fas fa-check-circle" style="color:#16a34a;"></i>
                        </button>
                        <button onclick="adm_opcao7()" class="fab-option"><span>Editar Usuário</span><i class="fas fa-user-edit" style="color:#7c3aed;"></i></button>
                        <button onclick="adm_opcao8()" class="fab-option"><span>Relatório de Pagamentos</span><i class="fas fa-file-pdf" style="color:#dc2626;"></i></button>
                        <button onclick="adm_opcao9()" class="fab-option"><span>Logs de Pagamentos</span><i class="fas fa-history" style="color:#0891b2;"></i></button>
                        <button onclick="adm_opcao4()" class="fab-option"><span>Novo Curso</span><i class="fas fa-plus-circle" style="color:#16a34a;"></i></button>
                        <button onclick="adm_opcaoMulta()" class="fab-option"><span>Aplicar Multa</span><i class="fas fa-gavel" style="color:#dc2626;"></i></button>
                        <button onclick="adm_opcaoValidarMulta()" class="fab-option"><span>Validar Multa</span><i class="fas fa-check-double" style="color:#d97706;"></i></button>
                        <button onclick="adm_opcaoLogsMultas()" class="fab-option"><span>Logs de Multas</span><i class="fas fa-scroll" style="color:#7c3aed;"></i></button>
                    </div>
                    <button id="fab-adm-btn" onclick="toggleFab()" class="fab-main"><i id="fab-adm-icon" class="fas fa-cog" style="transition:transform .4s;"></i></button>
                </div>
            </section>

            <!-- ═══ MEMBROS ═══════════════════════════════════════════════════════ -->
            <section id="membros" class="section-view">
                <div class="sec-title">Membros</div>
                <div class="tbl-wrap">
                    <div class="tbl-header">
                        <div class="ch-left">
                            <div class="ch-icon indigo" style="width:32px;height:32px;border-radius:8px;font-size:13px;"><i class="fas fa-users"></i></div>
                            <div style="font-size:14px;font-weight:700;color:var(--text);">Painel de Membros</div>
                        </div>
                        <span id="badge-total-membros" class="badge badge-blue" style="display:none;"></span>
                    </div>
                    <!-- Filtros -->
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--light);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="pesquisaMembro" placeholder="Buscar nome, RGPM ou Discord..." class="dec-input" style="flex:1;min-width:160px;">
                        <select id="filtroNivelMembro" class="dec-select" style="width:180px;min-width:140px;" onchange="filtrarMembros()">
                            <option value="">Todos os níveis</option>
                            <option value="3">Alunos (Nível 3)</option>
                            <option value="2">Instrutor / Auxiliar (Nível 2)</option>
                            <option value="1">Administrador (Nível 1)</option>
                        </select>
                        <select id="filtroTaxaMembro" class="dec-select" style="width:170px;min-width:130px;" onchange="filtrarMembros()">
                            <option value="">Taxa — todos</option>
                            <option value="pago">Taxa paga ✅</option>
                            <option value="nao_pago">Taxa não paga ❌</option>
                        </select>
                        <button onclick="filtrarMembros()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;"><i class="fas fa-filter"></i> Filtrar</button>
                        <button onclick="limparFiltrosMembros()" style="padding:9px 14px;background:var(--light);color:var(--muted);border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:12px;cursor:pointer;" title="Limpar filtros"><i class="fas fa-times"></i></button>
                        <button onclick="abrirModalVerArquivo()" style="padding:9px 14px;background:#1e40af;color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;" title="Ver pagamentos de alunos excluidos"><i class="fas fa-archive" style="margin-right:5px;"></i>Historico Arquivado</button>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="dec-table">
                            <thead>
                                <tr>
                                    <th>Nome</th><th>RGPM</th><th>Discord</th><th>Curso</th><th>Status</th>
                                    <th>Nível</th><th style="text-align:center;">Taxa</th>
                                </tr>
                            </thead>
                            <tbody id="tabelaMembros">
                                <?php $qm = $conexao->query("SELECT u.nome,u.rgpm,u.discord,u.meus_cursos,u.status,u.nivel,u.tags,
                                    CASE WHEN pp.id IS NOT NULL AND pp.status='validado' THEN 1 ELSE 0 END as taxa_paga
                                    FROM usuarios u
                                    LEFT JOIN pagamentos_pendentes pp ON pp.usuario_id=u.id AND pp.status='validado'
                                    ORDER BY u.nivel ASC, u.nome ASC");
                                if ($qm && $qm->num_rows > 0) {
                                    while ($row = $qm->fetch_assoc()) {
                                        $cur = !empty($row['meus_cursos']) ? htmlspecialchars($row['meus_cursos']) : '<span style="color:var(--muted)">—</span>';
                                        $st  = $row['status'];
                                        $sc  = ($st==='Aprovado')?'badge-green':(($st==='Reprovado'||$st==='Inativo')?'badge-red':(($st==='Pendente'||$st==='Afastado')?'badge-yellow':'badge-gray'));
                                        $nivel = intval($row['nivel']);
                                        $nivelLabel = $nivel===1?'<span class="badge badge-red">ADM</span>':($nivel===2?'<span class="badge badge-indigo">Instrutor</span>':'<span class="badge badge-blue">Aluno</span>');
                                        $taxaPaga = intval($row['taxa_paga']);
                                        // Também verifica via tags (compatibilidade)
                                        if (!$taxaPaga && !empty($row['tags'])) {
                                            $tags = json_decode($row['tags'], true) ?: [];
                                            foreach ($tags as $t) { if (stripos($t,'pagou')!==false) { $taxaPaga=1; break; } }
                                        }
                                        $taxaHtml = $nivel===3 ? ($taxaPaga?'<span class="badge badge-green" title="Taxa paga"><i class="fas fa-check-circle"></i> Pago</span>':'<span class="badge badge-red" title="Taxa não paga"><i class="fas fa-times-circle"></i> Não pago</span>') : '<span style="color:var(--muted);font-size:11px;">—</span>';
                                        echo '<tr data-nivel="'.$nivel.'" data-taxa="'.($taxaPaga?'pago':'nao_pago').'" data-texto="'.strtolower(htmlspecialchars($row['nome'].' '.$row['rgpm'].' '.($row['discord']??''))).'">';
                                        echo '<td class="strong">'.htmlspecialchars($row['nome']).'</td>';
                                        echo '<td style="font-family:monospace;">'.htmlspecialchars($row['rgpm']).'</td>';
                                        echo '<td>'.htmlspecialchars($row['discord']??'—').'</td>';
                                        echo '<td>'.$cur.'</td>';
                                        echo '<td><span class="badge '.$sc.'">'.htmlspecialchars($st).'</span></td>';
                                        echo '<td>'.$nivelLabel.'</td>';
                                        echo '<td style="text-align:center;">'.$taxaHtml.'</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-users"></i><p>Nenhum membro.</p></div></td></tr>';
                                } ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="padding:9px 16px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:11px;color:var(--muted);background:var(--light);flex-wrap:wrap;gap:4px;">
                        <span id="contador_membros">Carregando...</span>
                        <span>DEC · <?php echo date('d/m/Y H:i'); ?></span>
                    </div>
                </div>
                <button onclick="abrirModalNivel()" class="fab-pill no-print" style="position:fixed;bottom:24px;right:24px;z-index:50;display:flex;align-items:center;gap:7px;background:var(--blue);color:white;border:none;border-radius:30px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(26,86,219,0.4);font-family:'Inter',sans-serif;transition:all .18s;"><i class="fas fa-user-shield"></i> Atualizar Nível</button>
            </section>

            <!-- ═══ PRESENÇA ══════════════════════════════════════════════════════ -->
            <section id="presenca" class="section-view">
                <div class="sec-title">Histórico de Atas</div>
                <div class="tbl-wrap">
                    <div class="tbl-header">
                        <div class="ch-left">
                            <div class="ch-icon green" style="width:32px;height:32px;border-radius:8px;font-size:13px;"><i class="fas fa-clipboard-list"></i></div>
                            <div style="font-size:14px;font-weight:700;color:var(--text);">Atas Geradas</div>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="dec-table">
                            <thead>
                                <tr>
                                    <th>Nome da Aula</th><th>Data</th>
                                    <th style="text-align:center;">Download</th>
                                    <th style="text-align:center;">Ações</th>
                                    <?php if ($nivelSessao <= NIVEL_ADM): ?><th style="text-align:center;">Editar</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="tbody-presenca">
                                <?php $ra = $conexao->query("SELECT id,nome_referencia,data_registro FROM registros_presenca ORDER BY id DESC");
                                if ($ra && $ra->num_rows > 0) {
                                    while ($row = $ra->fetch_assoc()) {
                                        $nomeEsc = htmlspecialchars($row['nome_referencia']);
                                        $nomeJs  = addslashes(htmlspecialchars($row['nome_referencia']));
                                        echo "<tr><td class='strong'>{$nomeEsc}</td><td>" . date('d/m/Y · H:i', strtotime($row['data_registro'])) . "</td><td style='text-align:center;'><a href='baixar_ata.php?id=" . intval($row['id']) . "' class='btn-dl'><i class='fas fa-file-pdf'></i> PDF</a></td><td style='text-align:center;'><button onclick='confirmarDeletarAta(" . intval($row['id']) . ",\"{$nomeJs}\")' class='btn-del-sm'><i class='fas fa-trash-alt'></i> Excluir</button></td>";
                                        if ($nivelSessao <= NIVEL_ADM) {
                                            echo "<td style='text-align:center;'><button onclick='abrirModalEditarAta(" . intval($row['id']) . ",\"{$nomeJs}\")' class='btn-dl' style='background:#0891b2;color:white;border:none;border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;'><i class='fas fa-user-plus'></i> Editar</button></td>";
                                        }
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='4'><div class='empty-state'><i class='fas fa-inbox'></i><p>Nenhuma ata.</p></div></td></tr>";
                                } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="position:fixed;bottom:24px;right:24px;z-index:50;display:flex;gap:10px;" class="no-print">
                    <button onclick="abrirModal()" style="display:flex;align-items:center;gap:7px;background:#4338ca;color:white;border:none;border-radius:30px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(67,56,202,0.4);font-family:'Inter',sans-serif;transition:all .18s;"><i class="fas fa-plus"></i> Gerar Ata</button>
                </div>
            </section>

            <!-- ═══ CRONOGRAMAS ═══════════════════════════════════════════════════ -->
            <section id="escala" class="section-view">
                <div class="sec-title">Cronogramas</div>
                <div class="tbl-wrap">
                    <div class="tbl-header">
                        <div class="ch-left">
                            <div class="ch-icon amber" style="width:32px;height:32px;border-radius:8px;font-size:13px;"><i class="fas fa-calendar-alt"></i></div>
                            <div style="font-size:14px;font-weight:700;color:var(--text);">Registro de Cronogramas</div>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="dec-table">
                            <thead>
                                <tr><th>Nome</th><th>Data</th><th style="text-align:center;">Download</th><th style="text-align:center;">Ações</th></tr>
                            </thead>
                            <tbody id="tbody-cronogramas">
                                <?php $rc = $conexao->query("SELECT id,titulo,data_envio FROM cronogramas ORDER BY id DESC");
                                if ($rc && $rc->num_rows > 0) {
                                    while ($row = $rc->fetch_assoc()) {
                                        $tEsc = htmlspecialchars($row['titulo']);
                                        $tJs  = addslashes(htmlspecialchars($row['titulo']));
                                        echo "<tr><td class='strong'>{$tEsc}</td><td>" . date('d/m/Y · H:i', strtotime($row['data_envio'])) . "</td><td style='text-align:center;'><a href='baixar_cronograma.php?id=" . intval($row['id']) . "' class='btn-dl'><i class='fas fa-download'></i> Baixar</a></td><td style='text-align:center;'><button onclick='confirmarDeletarCronograma(" . intval($row['id']) . ",\"{$tJs}\")' class='btn-del-sm'><i class='fas fa-trash-alt'></i> Excluir</button></td></tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='4'><div class='empty-state'><i class='fas fa-inbox'></i><p>Nenhum cronograma.</p></div></td></tr>";
                                } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <button onclick="abrirModalCronograma()" style="position:fixed;bottom:24px;right:24px;z-index:50;display:flex;align-items:center;gap:7px;background:#16a34a;color:white;border:none;border-radius:30px;padding:11px 18px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(22,163,74,0.4);font-family:'Inter',sans-serif;" class="no-print"><i class="fas fa-upload"></i> Importar</button>
            </section>

            <?php if ($nivelSessao <= NIVEL_ADM): ?>
            <section id="blacklist" class="section-view">
                <div class="sec-title">Blacklist</div>
        <div class="tbl-wrap">
            <div class="tbl-header">
                <div class="ch-left">
                    <div class="ch-icon" style="background:#dc2626;width:32px;height:32px;border-radius:8px;font-size:13px;"><i class="fas fa-ban"></i></div>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:var(--text);">Usuários Banidos</div>
                        <div style="font-size:11px;color:var(--muted);">Clique em <strong>Remover</strong> para desbloquear antes do prazo</div>
                    </div>
                </div>
                <button onclick="abrirModalRemoverBL()" style="display:flex;align-items:center;gap:6px;background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
                    <i class="fas fa-unlock-alt"></i> Remover
                </button>
            </div>
            <div style="overflow-x:auto;">
                <table class="dec-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>RGPM</th>
                            <th>ID Discord</th>
                            <th>Motivo</th>
                            <th>Duração</th>
                            <th>Adicionado por</th>
                            <th>Aplicação</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-blacklist">
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
            </section>
            <?php endif; ?>

        </div><!-- end viewport -->
    </main>

    <!-- ══ MODAL REMOVER DA BLACKLIST ═══════════════════════════════════════════ -->
    <?php if ($nivelSessao <= NIVEL_ADM): ?>
    <div id="modalRemoverBL" style="position:fixed;inset:0;z-index:99999;background:rgba(13,27,46,0.7);display:none;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:14px;max-width:440px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
            <div style="background:linear-gradient(135deg,#14532d,#16a34a);padding:18px 22px;">
                <div style="font-size:15px;font-weight:800;color:white;"><i class="fas fa-unlock-alt" style="margin-right:8px;"></i>Remover da Blacklist</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.6);margin-top:2px;">Busque e selecione o usuário para remover o bloqueio</div>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;gap:12px;">
                <div style="display:flex;gap:8px;">
                    <input id="rem-bl-busca" type="text" placeholder="Nome ou RGPM..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter')buscarParaRemoverBL()">
                    <button onclick="buscarParaRemoverBL()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;"><i class="fas fa-search"></i></button>
                </div>
                <div id="rem-bl-resultados" style="display:none;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto;"></div>
            </div>
            <div style="padding:0 20px 18px;">
                <button onclick="fecharModalRemoverBL()" class="btn-secondary" style="width:100%;">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL ARQUIVO: Deseja salvar pagamentos antes de excluir? -->
    <div id="modalArquivoPag" style="position:fixed;inset:0;z-index:99998;background:rgba(13,27,46,0.8);display:none;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:16px;max-width:520px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.35);overflow:hidden;">
            <div style="background:linear-gradient(135deg,#1a3a6b,#1a56db);padding:22px 26px;">
                <div style="font-size:15px;font-weight:800;color:white;display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,0.15);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-archive" style="font-size:15px;color:white;"></i>
                    </div>
                    <div>
                        <div>Salvar historico de pagamentos?</div>
                        <div style="font-size:11px;color:rgba(255,255,255,0.5);font-weight:400;margin-top:2px;" id="arq-nome-sub">Antes de excluir a conta</div>
                    </div>
                </div>
            </div>
            <div style="padding:22px 26px;display:flex;flex-direction:column;gap:14px;">
                <div style="background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:16px;display:flex;gap:12px;align-items:flex-start;">
                    <i class="fas fa-info-circle" style="color:#1a56db;font-size:16px;flex-shrink:0;margin-top:1px;"></i>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:#1e3a5f;margin-bottom:4px;">O que sera salvo</div>
                        <div style="font-size:12px;color:#1e40af;line-height:1.7;">
                            Comprovantes de <strong>inscricao</strong> e de <strong>multa</strong> copiados para uma tabela permanente, com nome, RGPM, valor, curso e imagem.
                        </div>
                    </div>
                </div>
                <div id="arq-contagem" style="display:none;background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:13px;font-weight:700;color:#15803d;">
                    <i class="fas fa-check-circle" style="margin-right:6px;"></i><span id="arq-contagem-txt"></span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Motivo da exclusao (opcional)</label>
                    <textarea id="arq-motivo" rows="2" placeholder="Ex: conduta inadequada, banido por..." style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:'Inter',sans-serif;font-size:13px;color:#1e293b;resize:none;outline:none;background:#f8fafc;"></textarea>
                </div>
            </div>
            <div style="padding:14px 26px 22px;display:flex;gap:10px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                <button onclick="arqNao()" style="flex:1;padding:11px;border:1.5px solid #e2e8f0;border-radius:9px;background:#fff;color:#64748b;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;">
                    <i class="fas fa-trash-alt" style="margin-right:6px;color:#dc2626;"></i>Nao, excluir direto
                </button>
                <button onclick="arqSim()" id="arq-btn-sim" style="flex:2;padding:11px;border:none;border-radius:9px;background:linear-gradient(135deg,#1a56db,#1e40af);color:#fff;font-family:'Inter',sans-serif;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fas fa-archive"></i> Sim, salvar e excluir
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL VER PAGAMENTOS ARQUIVADOS -->
    <div id="modalVerArquivo" style="position:fixed;inset:0;z-index:99998;background:rgba(13,27,46,0.8);display:none;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:16px;max-width:700px;width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,0.35);">
            <div style="background:linear-gradient(135deg,#0d1b2e,#1a3a6b);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                <div>
                    <div style="font-size:15px;font-weight:800;color:white;"><i class="fas fa-archive" style="margin-right:8px;color:#60a5fa;"></i>Pagamentos Arquivados</div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.45);margin-top:2px;">Historico de alunos excluidos</div>
                </div>
                <button onclick="fecharModalVerArquivo()" style="background:rgba(255,255,255,0.1);border:none;border-radius:8px;width:32px;height:32px;cursor:pointer;color:white;font-size:16px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-shrink:0;">
                <input id="arq-busca" type="text" placeholder="Nome, RGPM, curso..." style="flex:1;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;outline:none;" onkeydown="if(event.key==='Enter')carregarArquivo()">
                <select id="arq-filtro-tipo" onchange="carregarArquivo()" style="padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;outline:none;background:#fff;">
                    <option value="">Todos</option>
                    <option value="inscricao">Inscricao</option>
                    <option value="multa">Multa</option>
                </select>
                <button onclick="carregarArquivo()" style="padding:9px 16px;background:#1a56db;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;"><i class="fas fa-search"></i></button>
            </div>
            <div id="arq-lista" style="flex:1;overflow-y:auto;padding:16px 20px;">
                <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-archive" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Clique em buscar</div>
            </div>
        </div>
    </div>

    <!-- MODAL COMPROVANTE ARQUIVADO -->
    <div id="modalCompArquivo" style="position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.7);display:none;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:14px;max-width:500px;width:100%;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,0.4);">
            <div style="padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;">
                <div style="font-size:14px;font-weight:700;color:#1e293b;"><i class="fas fa-image" style="margin-right:7px;color:#1a56db;"></i>Comprovante arquivado</div>
                <button onclick="document.getElementById('modalCompArquivo').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:18px;color:#94a3b8;"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:16px;text-align:center;">
                <img id="arq-comp-img" src="" style="max-width:100%;max-height:400px;border-radius:8px;border:1px solid #e2e8f0;" alt="Comprovante">
            </div>
        </div>
    </div>

    <!-- ══ MODAL PÓS-EXCLUSÃO: Adicionar à Blacklist? ═══════════════════════════ -->
    <div id="modalBlPosExclusao" style="position:fixed;inset:0;z-index:99999;background:rgba(13,27,46,0.75);display:none;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:16px;max-width:500px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.3);overflow:hidden;">
            <div style="background:linear-gradient(135deg,#0d1b2e,#1a3a6b);padding:20px 24px;">
                <div style="font-size:15px;font-weight:800;color:white;"><i class="fas fa-user-slash" style="margin-right:8px;color:#fca5a5;"></i>Conta Excluída</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-top:3px;" id="bl-pos-nome-sub">Usuário removido do sistema</div>
            </div>
            <div style="padding:22px;display:flex;flex-direction:column;gap:16px;">
                <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:12px;padding:16px;">
                    <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:5px;"><i class="fas fa-question-circle" style="margin-right:5px;"></i>Deseja adicionar <strong id="bl-pos-nome-destaque" style="color:#dc2626;"></strong> à Blacklist?</div>
                    <div style="font-size:12px;color:#78350f;line-height:1.6;">Isso bloqueará futuros cadastros com o mesmo RGPM, Discord, IP e impressão digital do dispositivo.</div>
                </div>
                <div id="bl-pos-form" style="display:none;flex-direction:column;gap:14px;">
                    <div>
                        <label class="field-label">Motivo do Banimento <span style="color:#dc2626;">*</span></label>
                        <textarea id="bl-pos-motivo" rows="3" class="dec-textarea" placeholder="Descreva o motivo..."></textarea>
                    </div>
                    <div>
                        <label class="field-label">Duração do Banimento <span style="color:#dc2626;">*</span></label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <button type="button" class="bl-dur-btn" data-val="1semana"    onclick="blPosSelecionarDuracao(this)">1 Semana</button>
                            <button type="button" class="bl-dur-btn" data-val="3semanas"   onclick="blPosSelecionarDuracao(this)">3 Semanas</button>
                            <button type="button" class="bl-dur-btn" data-val="1mes"       onclick="blPosSelecionarDuracao(this)">1 Mês</button>
                            <button type="button" class="bl-dur-btn" data-val="permanente" onclick="blPosSelecionarDuracao(this)">Permanente</button>
                        </div>
                    </div>
                </div>
            </div>
            <div style="padding:14px 22px 20px;display:flex;gap:10px;background:var(--light);border-top:1px solid var(--border);">
                <button onclick="blPosNao()" class="btn-secondary" style="flex:1;">Não, apenas excluir</button>
                <button onclick="blPosSim()" id="bl-pos-btn-sim" style="flex:2;padding:10px;background:#dc2626;color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;"><i class="fas fa-ban"></i> Sim, adicionar à Blacklist</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL NÍVEL -->

    <!-- MODAL NÍVEL -->
    <div id="modalNivel" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:420px;">
            <div class="dec-modal-header">
                <div><div class="dec-modal-title"><i class="fas fa-user-shield" style="color:var(--blue);margin-right:7px;"></i>Atualizar Nível</div></div>
                <button onclick="fecharModalNivel()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div><label class="field-label">RGPM</label><input type="text" id="nivel_rgpm" placeholder="Digite o RGPM" class="dec-input"></div>
                <div><label class="field-label">Novo Nível</label>
                    <select id="novo_nivel" class="dec-select">
                        <option value="">Selecione</option>
                        <option value="1">ADM</option><option value="2">INSTRUTOR</option><option value="3">ALUNO</option>
                    </select>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalNivel()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button onclick="atualizarNivel()" class="btn-primary" style="flex:2;"><i class="fas fa-check"></i> Atualizar</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR USUÁRIO -->
    <div id="modalEditarUsuario" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:560px;">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-user-edit" style="color:#7c3aed;margin-right:7px;"></i>Editar Usuário</div>
                    <div class="dec-modal-sub">Busque pelo RGPM, Discord ou Nome</div>
                </div>
                <button onclick="fecharModalEditarUsuario()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div style="display:flex;gap:8px;">
                    <input id="eu-busca" type="text" placeholder="RGPM, Discord ou Nome..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter')buscarUsuarioEditar()">
                    <button onclick="buscarUsuarioEditar()" style="padding:9px 16px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i></button>
                </div>
                <div id="eu-resultados" style="display:none;max-height:200px;overflow-y:auto;flex-direction:column;gap:6px;"></div>
                <div id="eu-form" style="display:none;">
                    <div style="padding:10px 13px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:4px;font-size:13px;font-weight:700;color:var(--blue);" id="eu-nome-badge"></div>
                    <input type="hidden" id="eu-id">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div><label class="field-label">Nome</label><input id="eu-nome" type="text" class="dec-input" placeholder="Nome completo"></div>
                        <div><label class="field-label">RGPM</label><input id="eu-rgpm" type="text" class="dec-input" placeholder="RGPM"></div>
                        <div><label class="field-label">Discord</label><input id="eu-discord" type="text" class="dec-input" placeholder="Discord ID"></div>
                        <div><label class="field-label">Nível</label>
                            <select id="eu-nivel" class="dec-select">
                                <option value="">Manter</option>
                                <option value="1">ADM</option><option value="2">INSTRUTOR</option><option value="3">ALUNO</option>
                            </select>
                        </div>
                        <div><label class="field-label">Status</label>
                            <select id="eu-status" class="dec-select">
                                <option value="">Manter</option>
                                <option value="Ativo">Ativo</option><option value="Inativo">Inativo</option>
                                <option value="Afastado">Afastado</option><option value="Aprovado">Aprovado</option>
                            </select>
                        </div>
                        <div><label class="field-label">Meus Cursos</label><input id="eu-cursos" type="text" class="dec-input" placeholder="Nome do curso"></div>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalEditarUsuario()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button id="eu-btn-deletar" onclick="deletarUsuarioConfirmar()" class="btn-danger" style="flex:1;" disabled><i class="fas fa-trash-alt"></i> Excluir</button>
                <button id="eu-btn-salvar" onclick="salvarEdicaoUsuario()" class="btn-primary" style="flex:2;background:#7c3aed;" disabled><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>

    <!-- MODAL NOVO CURSO -->
    <div id="modal-novo-curso" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:480px;">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-graduation-cap" style="color:var(--blue);margin-right:7px;"></i>Novo Curso</div>
                    <div class="dec-modal-sub">Registrar novo curso no sistema</div>
                </div>
                <button onclick="fecharModalNovoCurso()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div><label class="field-label">Nome do Curso</label><input id="nc-nome" type="text" placeholder="Ex: CFSD 2026..." class="dec-input" autocomplete="off"></div>
                <div><label class="field-label">Descrição</label><textarea id="nc-descricao" rows="2" placeholder="Descreva o curso..." class="dec-textarea"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="field-label">Tipo</label>
                        <select id="nc-tipo" class="dec-select">
                            <option value="Curso de Formação">Curso de Formação</option>
                            <option value="Especialização">Especialização</option>
                        </select>
                    </div>
                    <div><label class="field-label">Status</label>
                        <select id="nc-status" class="dec-select">
                            <option value="Aberto">Aberto</option><option value="Fechado">Fechado</option>
                        </select>
                    </div>
                </div>
                <div><label class="field-label">Valor da Taxa (R$)</label><input id="nc-taxa" type="number" min="0" step="0.01" placeholder="Ex: 150.00" class="dec-input"></div>
                <div>
                    <label class="field-label">Tags do Curso <span style="color:var(--muted);font-weight:400;">(separadas por vírgula)</span></label>
                    <div style="font-size:11px;color:var(--muted);margin-bottom:5px;">Não inclua a tag de pagamento — ela é gerada automaticamente a partir do nome do curso.</div>
                    <input id="nc-tags" type="text" placeholder="Ex: Aula 01 - Abordagem, Aula 02 - Modulação" class="dec-input">
                    <div id="nc-tags-preview" style="display:none;margin-top:6px;padding:8px 11px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:7px;font-size:11px;color:var(--blue2);"></div>
                </div>
                <div id="nc-preview" style="display:none;" class="preview-box">
                    <div class="preview-label">Preview</div>
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                        <div><span id="nc-prev-nome" style="font-weight:800;color:var(--text);"></span> <span id="nc-prev-tipo" style="font-size:11px;color:var(--muted);"></span></div>
                        <span id="nc-prev-status" class="badge"></span>
                    </div>
                    <div id="nc-prev-taxa" style="font-size:12px;color:var(--muted);margin-top:5px;"></div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalNovoCurso()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button id="nc-btn-salvar" onclick="salvarNovoCurso()" class="btn-primary" style="flex:2;"><i class="fas fa-plus"></i> Cadastrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR CURSO -->
    <div id="modalEditarCurso" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:560px;">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-edit" style="color:#d97706;margin-right:7px;"></i>Editar Curso</div>
                    <div class="dec-modal-sub">Selecione um curso para editar</div>
                </div>
                <button onclick="fecharModalEditarCurso()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div id="ec-step1"><label class="field-label">Selecione o curso</label>
                    <div id="ec-lista-cursos" style="display:flex;flex-direction:column;gap:6px;max-height:280px;overflow-y:auto;">
                        <div style="text-align:center;padding:24px;color:var(--muted);"><i class="fas fa-spinner fa-spin"></i></div>
                    </div>
                </div>
                <div id="ec-step2" style="display:none;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:9px 13px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
                        <i class="fas fa-edit" style="color:#d97706;flex-shrink:0;"></i>
                        <div>
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#92400e;">Editando</div>
                            <div id="ec-curso-nome-badge" style="font-size:13px;font-weight:700;color:#78350f;"></div>
                        </div>
                        <button onclick="voltarListaCursos()" style="margin-left:auto;background:none;border:none;color:#d97706;cursor:pointer;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;"><i class="fas fa-arrow-left"></i> Trocar</button>
                    </div>
                    <input type="hidden" id="ec-id">
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div><label class="field-label">Nome</label><input id="ec-nome" type="text" class="dec-input"></div>
                        <div><label class="field-label">Descrição</label><textarea id="ec-descricao" rows="3" class="dec-textarea"></textarea></div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div><label class="field-label">Tipo</label>
                                <select id="ec-tipo" class="dec-select">
                                    <option value="Curso de Formação">Curso de Formação</option>
                                    <option value="Especialização">Especialização</option>
                                </select>
                            </div>
                            <div><label class="field-label">Status</label>
                                <select id="ec-status" class="dec-select">
                                    <option value="Aberto">Aberto</option><option value="Fechado">Fechado</option>
                                </select>
                            </div>
                        </div>
                        <div><label class="field-label">Valor da Taxa (R$)</label><input id="ec-taxa" type="number" min="0" step="0.01" class="dec-input"></div>
                        <div id="ec-tags-bloco-atual" style="display:none;">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:5px;">Tags atuais do curso</div>
                            <div id="ec-tags-preview-atual" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
                        </div>
                        <div>
                            <button type="button" id="ec-btn-editar-tags" onclick="abrirConfirmacaoEditarTags()" style="width:100%;padding:9px 14px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;color:#92400e;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:background .15s;">
                                <i class="fas fa-tags"></i> Editar Tags deste Curso
                            </button>
                        </div>
                        <!-- Bloco de edição de tags — aparece só após confirmação -->
                        <div id="ec-tags-edicao-bloco" style="display:none;">
                            <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:10px 13px;margin-bottom:6px;font-size:12px;color:#92400e;"><i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i>As tags antigas serão substituídas ao salvar.</div>
                            <label class="field-label">Novas Tags <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">(separadas por vírgula)</span></label>
                            <input id="ec-tags" type="text" class="dec-input" placeholder="Ex: tag1, tag2, tag3" oninput="atualizarPreviewTagsEditar()">
                            <div id="ec-tags-preview" style="display:none;margin-top:6px;flex-wrap:wrap;gap:5px;"></div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Não inclua a tag de pagamento — ela é gerada automaticamente.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalEditarCurso()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button id="ec-btn-deletar" onclick="deletarCurso()" class="btn-danger" style="flex:1;" disabled><i class="fas fa-trash-alt"></i> Apagar</button>
                <button id="ec-btn-salvar" onclick="salvarEdicaoCurso()" class="btn-primary" style="flex:2;background:#d97706;" disabled><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>

    <!-- MODAL ELIMINAR CURSO -->
    <div id="modalEliminarCurso" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:520px;">
            <div class="dec-modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca;">
                <div>
                    <div class="dec-modal-title" style="color:#b91c1c;"><i class="fas fa-user-minus" style="margin-right:7px;"></i>Eliminar do Curso</div>
                    <div class="dec-modal-sub" style="color:#ef4444;">Remove todos os alunos e fecha o curso</div>
                </div>
                <button onclick="fecharModalEliminarCurso()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <label class="field-label">Selecione o curso a eliminar</label>
                <div id="elim-lista-cursos" style="display:flex;flex-direction:column;gap:6px;max-height:250px;overflow-y:auto;">
                    <div style="text-align:center;padding:24px;color:var(--muted);"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
                <div id="elim-confirmacao" style="display:none;margin-top:14px;">
                    <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#991b1b;margin-bottom:10px;">⚠️ Atenção — ação irreversível</div>
                        <div style="font-size:13px;color:var(--text);line-height:1.6;margin-bottom:12px;">Todos os <strong>alunos (nível 3)</strong> matriculados em <strong id="elim-preview-nome" style="color:#dc2626;"></strong> serão desmatriculados e seus registros de pagamento removidos. O curso será fechado.</div>
                        <div>
                            <label class="field-label" style="color:#991b1b;">Digite o nome do curso para confirmar:</label>
                            <input id="elim-confirmacao-input" type="text" class="dec-input" placeholder="Digite aqui..." oninput="verificarConfirmacaoEliminar()" style="border-color:#fca5a5;">
                            <div id="elim-confirmacao-hint" style="font-size:11px;margin-top:5px;color:var(--muted);"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalEliminarCurso()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button id="elim-btn-confirmar" onclick="confirmarEliminarCurso()" disabled style="flex:2;background:#dc2626;color:white;border:none;border-radius:8px;padding:10px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;opacity:.5;transition:opacity .2s;"><i class="fas fa-user-minus"></i> Confirmar</button>
            </div>
        </div>
    </div>

    <!-- MODAL VALIDAR PAGAMENTOS -->
    <div id="modalValidarPagamentos" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:700px;position:relative;">
            <div class="dec-modal-header" style="background:#f0fdf4;border-bottom:1px solid #bbf7d0;">
                <div>
                    <div class="dec-modal-title" style="color:#15803d;"><i class="fas fa-check-circle" style="margin-right:7px;"></i>Validar Pagamentos<span id="vp-badge-count" style="display:none;background:#dc2626;color:white;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:800;margin-left:8px;"></span></div>
                    <div class="dec-modal-sub">Comprovantes aguardando validação</div>
                </div>
                <button onclick="fecharModalValidarPagamentos()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <!-- Barra de pesquisa por RGPM, Nome ou ID -->
            <div style="padding:12px 16px;border-bottom:1px solid var(--border);background:var(--light);display:flex;gap:8px;">
                <input id="vp-busca" type="text" placeholder="Buscar por Nome, RGPM ou Discord..." class="dec-input" style="flex:1;font-size:13px;" oninput="vpBuscaDebounce()">
                <button onclick="vpLimparBusca()" title="Limpar busca" style="padding:8px 11px;background:var(--white);border:1px solid var(--border);border-radius:8px;cursor:pointer;color:var(--muted);font-size:13px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body" style="padding:0;">
                <div id="vp-lista" style="display:flex;flex-direction:column;">
                    <div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;justify-content:space-between;align-items:center;">
                <span id="vp-total-label" style="font-size:12px;color:var(--muted);"></span>
                <button onclick="fecharModalValidarPagamentos()" class="btn-secondary" style="width:auto;padding:8px 18px;">Fechar</button>
            </div>
        </div>
    </div>

    <!-- LIGHTBOX COMPROVANTE MELHORADO — zoom, rotação, drag, pinch -->
    <style>
        #vp-lightbox-img { transition: transform 0.15s ease; cursor: grab; display: block; max-width: 90vw; max-height: 75vh; min-width: 120px; object-fit: contain; border-radius: 8px; box-shadow: 0 8px 40px rgba(0,0,0,0.6); user-select: none; }
        #vp-lightbox-img.grabbing { cursor: grabbing; }
        .lb-ctrl { background: rgba(255,255,255,0.13); border: 1px solid rgba(255,255,255,0.18); border-radius: 8px; padding: 8px 13px; color: white; cursor: pointer; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 5px; transition: background .14s; font-family: 'Inter',sans-serif; text-decoration: none; white-space: nowrap; }
        .lb-ctrl:hover { background: rgba(255,255,255,0.26); }
        #vp-lb-zoom-badge { background: rgba(0,0,0,0.55); color: rgba(255,255,255,0.85); font-size: 12px; font-weight: 700; padding: 5px 11px; border-radius: 20px; min-width: 54px; text-align: center; }
    </style>
    <div id="vp-lightbox" style="display:none;position:fixed;inset:0;background:rgba(5,10,20,0.97);z-index:999999;flex-direction:column;align-items:stretch;justify-content:space-between;">
        <!-- Barra topo -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 16px;background:rgba(0,0,0,0.45);border-bottom:1px solid rgba(255,255,255,0.08);flex-wrap:wrap;gap:8px;">
            <div style="display:flex;align-items:center;gap:9px;color:rgba(255,255,255,0.85);font-size:13px;font-weight:700;">
                <i class="fas fa-file-image" style="color:#60a5fa;"></i>
                <span id="vp-lb-nome">Comprovante</span>
            </div>
            <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                <a id="vp-lightbox-dl" href="#" download="comprovante.jpg" class="lb-ctrl" style="background:rgba(22,163,74,0.28);border-color:rgba(34,197,94,0.28);"><i class="fas fa-download"></i> Baixar</a>
                <button onclick="fecharLightbox()" class="lb-ctrl" style="background:rgba(220,38,38,0.28);border-color:rgba(252,165,165,0.25);"><i class="fas fa-times"></i> Fechar</button>
            </div>
        </div>
        <!-- Área imagem -->
        <div id="vp-lb-area" style="flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;">
            <div id="vp-lb-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:rgba(255,255,255,0.6);font-size:14px;font-weight:600;">
                <i class="fas fa-spinner fa-spin" style="font-size:2.5rem;color:rgba(255,255,255,0.4);"></i>
                Carregando comprovante...
            </div>
            <img id="vp-lightbox-img" src="" alt="Comprovante" style="display:none;">
        </div>
        <!-- Barra controles -->
        <div style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px 16px;background:rgba(0,0,0,0.45);border-top:1px solid rgba(255,255,255,0.08);flex-wrap:wrap;">
            <button onclick="lbZoom(-0.3)" class="lb-ctrl" title="Reduzir"><i class="fas fa-search-minus"></i></button>
            <span id="vp-lb-zoom-badge">100%</span>
            <button onclick="lbZoom(+0.3)" class="lb-ctrl" title="Ampliar"><i class="fas fa-search-plus"></i></button>
            <button onclick="lbReset()" class="lb-ctrl" title="Ajustar à tela"><i class="fas fa-expand-arrows-alt"></i> Ajustar</button>
            <button onclick="lbZoom100()" class="lb-ctrl" title="Tamanho real"><i class="fas fa-eye"></i> 100%</button>
            <div style="width:1px;height:22px;background:rgba(255,255,255,0.15);margin:0 3px;"></div>
            <button onclick="lbRotar(-90)" class="lb-ctrl" title="Girar ←"><i class="fas fa-undo"></i></button>
            <button onclick="lbRotar(+90)" class="lb-ctrl" title="Girar →"><i class="fas fa-redo"></i></button>
        </div>
    </div>
    <script>
    // ── LIGHTBOX MELHORADO ──────────────────────────────────────────────────────
    let _lbZ=1, _lbRot=0, _lbTX=0, _lbTY=0, _lbDrag=false, _lbDX=0, _lbDY=0;
    function _lbApply(){
        const img=document.getElementById('vp-lightbox-img');
        img.style.transform=`translate(${_lbTX}px,${_lbTY}px) scale(${_lbZ}) rotate(${_lbRot}deg)`;
        document.getElementById('vp-lb-zoom-badge').textContent=Math.round(_lbZ*100)+'%';
    }
    function lbZoom(d){ _lbZ=Math.min(6,Math.max(0.15,_lbZ+d)); _lbApply(); }
    function lbZoom100(){ _lbZ=1; _lbTX=0; _lbTY=0; _lbApply(); }
    function lbReset(){ _lbZ=1; _lbRot=0; _lbTX=0; _lbTY=0; _lbApply(); }
    function lbRotar(d){ _lbRot=(_lbRot+d+360)%360; _lbApply(); }
    (function(){
        const lb=document.getElementById('vp-lightbox');
        const gi=()=>document.getElementById('vp-lightbox-img');
        lb.addEventListener('mousedown',e=>{ if(e.target!==gi()) return; _lbDrag=true; _lbDX=e.clientX-_lbTX; _lbDY=e.clientY-_lbTY; gi().classList.add('grabbing'); e.preventDefault(); });
        lb.addEventListener('mousemove',e=>{ if(!_lbDrag) return; _lbTX=e.clientX-_lbDX; _lbTY=e.clientY-_lbDY; _lbApply(); });
        lb.addEventListener('mouseup',()=>{ _lbDrag=false; gi().classList.remove('grabbing'); });
        lb.addEventListener('mouseleave',()=>{ _lbDrag=false; gi().classList.remove('grabbing'); });
        lb.addEventListener('wheel',e=>{ e.preventDefault(); lbZoom(e.deltaY<0?0.15:-0.15); },{passive:false});
        let _ptd=null;
        lb.addEventListener('touchstart',e=>{ if(e.touches.length===2) _ptd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY); });
        lb.addEventListener('touchmove',e=>{ if(e.touches.length===2&&_ptd){ const nd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY); _lbZ=Math.min(6,Math.max(0.15,_lbZ*(nd/_ptd))); _ptd=nd; _lbApply(); e.preventDefault(); }},{passive:false});
    })();
    async function verComprovante(id,nome,rgpm){
        const lb=document.getElementById('vp-lightbox');
        const img=document.getElementById('vp-lightbox-img');
        const ld=document.getElementById('vp-lb-loading');
        const dl=document.getElementById('vp-lightbox-dl');
        _lbZ=1;_lbRot=0;_lbTX=0;_lbTY=0;
        img.style.transform=''; img.style.display='none'; ld.style.display='flex';
        lb.style.display='flex';
        document.getElementById('vp-lb-nome').textContent=nome?(nome+(rgpm?' — RGPM: '+rgpm:'')):'Comprovante';
        _vpLightboxAberto=true;
        const d=await api(`ajax_action=ver_comprovante&id=${id}`);
        ld.style.display='none';
        if(d.sucesso){
            const src=`data:${d.mime};base64,${d.imagem}`;
            img.src=src; img.style.display='block'; _lbApply();
            if(dl){dl.href=src; dl.download=`comprovante_${rgpm||id}.${d.mime.split('/')[1]||'jpg'}`;}
        } else { toast('Erro: '+(d.erro||'Falha ao carregar'),'error'); fecharLightbox(); }
    }
    function fecharLightbox(){
        document.getElementById('vp-lightbox').style.display='none';
        _vpLightboxAberto=false; _lbZ=1;_lbRot=0;_lbTX=0;_lbTY=0;
    }
    </script>

    <!-- ═══ MODAL DE CONFIRMAÇÃO GENÉRICO — z-index 99999, SEM backdrop-filter ═══ -->
    <div id="vp-confirm-modal">
        <div style="background:#fff;border-radius:14px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.35);overflow:hidden;">
            <div style="padding:20px 22px 0;">
                <div style="font-size:15px;font-weight:800;color:var(--text);margin-bottom:8px;" id="vp-confirm-titulo"></div>
                <div style="font-size:13px;color:var(--muted);line-height:1.6;" id="vp-confirm-msg"></div>
            </div>
            <div style="display:flex;gap:10px;padding:16px 22px;">
                <button onclick="fecharVpConfirm(false)" style="flex:1;padding:10px;background:var(--light);border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;">Cancelar</button>
                <button id="vp-confirm-ok" onclick="fecharVpConfirm(true)" style="flex:2;padding:10px;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;"></button>
            </div>
        </div>
    </div>

    <!-- ═══ MODAL SUCESSO VALIDAÇÃO — z-index 99999 ═══ -->
    <div id="vp-sucesso-modal">
        <div style="background:#fff;border-radius:16px;max-width:380px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.25);overflow:hidden;text-align:center;">
            <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:28px 24px 22px;">
                <div style="width:60px;height:60px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;"><i class="fas fa-check" style="color:white;font-size:26px;"></i></div>
                <div style="font-size:17px;font-weight:800;color:white;margin-bottom:5px;">Comprovante Aceito!</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.75);">Pagamento validado com sucesso</div>
            </div>
            <div style="padding:20px 22px;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:13px;margin-bottom:16px;">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#15803d;margin-bottom:5px;">Aluno Validado</div>
                    <div style="font-size:15px;font-weight:800;color:var(--text);" id="vp-sucesso-nome"></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;">Curso: <span id="vp-sucesso-curso" style="font-weight:700;color:#15803d;"></span></div>
                </div>
                <button onclick="fecharSucessoValidacao()" style="width:100%;padding:11px;background:#16a34a;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;color:white;cursor:pointer;"><i class="fas fa-check-double"></i> Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL SETAR TAGS -->
    <div id="modalSetarTags" class="dec-modal-bd hidden">
        <div class="dec-modal">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-tags" style="color:var(--blue);margin-right:7px;"></i>Setar Tags</div>
                    <div class="dec-modal-sub">Atribuição de tags por curso</div>
                </div>
                <button onclick="fecharModalSetarTags()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div><label class="field-label">1. Selecione o Curso</label>
                    <select id="tag_curso" onchange="carregarTagsCurso()" class="dec-select">
                        <option value="">— Selecione —</option>
                    </select>
                </div>
                <div id="bloco_tags" style="display:none;"><label class="field-label">2. Selecione as Tags</label>
                    <div id="lista_tags" style="display:flex;flex-direction:column;gap:5px;"></div>
                </div>
                <div id="bloco_rgpms" style="display:none;">
                    <label class="field-label">3. Pesquisar Alunos (Nível 3)</label>
                    <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Busque pelo RGPM ou ID Discord. Somente alunos (nível 3) serão exibidos.</div>
                    <div style="display:flex;gap:6px;margin-bottom:8px;">
                        <input type="text" id="tag_busca_aluno" placeholder="RGPM ou ID Discord..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter'){buscarAlunoTag();}" oninput="debounceTagBusca()">
                        <button type="button" onclick="buscarAlunoTag()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i></button>
                    </div>
                    <div id="tag_resultados_busca" style="display:none;background:#f8fafc;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;max-height:180px;overflow-y:auto;"></div>
                    <div id="tag_alunos_selecionados" style="display:flex;flex-wrap:wrap;gap:6px;min-height:20px;"></div>
                </div>
                <div id="bloco_preview" style="display:none;" class="preview-box">
                    <div class="preview-label">Resumo</div>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;">
                        <span id="prev_curso" class="badge badge-blue">—</span>
                        <i class="fas fa-arrow-right" style="color:var(--muted);font-size:10px;"></i>
                        <span id="prev_tags_wrap" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                        <i class="fas fa-arrow-right" style="color:var(--muted);font-size:10px;"></i>
                        <span id="prev_total" class="badge badge-green">0 usuários</span>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot"><button onclick="executarSetarTags()" class="btn-primary"><i class="fas fa-bolt"></i> Executar Setagem</button></div>
        </div>
    </div>

    <!-- MODAL REMOVER TAGS -->
    <div id="modalRemoverTags" class="dec-modal-bd hidden">
        <div class="dec-modal">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-tag" style="color:var(--red);margin-right:7px;"></i>Remover Tags</div>
                    <div class="dec-modal-sub">Remoção de tags por curso</div>
                </div>
                <button onclick="fecharModalRemoverTags()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div><label class="field-label">1. Selecione o Curso</label>
                    <select id="rem_curso" onchange="carregarTagsRemocao()" class="dec-select">
                        <option value="">— Selecione —</option>
                    </select>
                </div>
                <div id="rem_bloco_tags" style="display:none;"><label class="field-label">2. Tags a Remover</label>
                    <div id="rem_lista_tags" style="display:flex;flex-direction:column;gap:5px;"></div>
                </div>
                <div id="rem_bloco_rgpms" style="display:none;">
                    <label class="field-label">3. Pesquisar Alunos (Nível 3)</label>
                    <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Busque pelo RGPM ou ID Discord. Somente alunos (nível 3) serão exibidos.</div>
                    <div style="display:flex;gap:6px;margin-bottom:8px;">
                        <input type="text" id="rem_busca_aluno" placeholder="RGPM ou ID Discord..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter'){buscarAlunoRem();}" oninput="debounceRemBusca()">
                        <button type="button" onclick="buscarAlunoRem()" style="padding:9px 14px;background:var(--red);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i></button>
                    </div>
                    <div id="rem_resultados_busca" style="display:none;background:#f8fafc;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;max-height:180px;overflow-y:auto;"></div>
                    <div id="rem_alunos_selecionados" style="display:flex;flex-wrap:wrap;gap:6px;min-height:20px;"></div>
                </div>
                <div id="rem_bloco_preview" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:13px;">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--red);margin-bottom:7px;">⚠️ Remoção</div>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;">
                        <span id="rem_prev_curso" class="badge badge-blue">—</span>
                        <i class="fas fa-arrow-right" style="color:var(--muted);font-size:10px;"></i>
                        <span id="rem_prev_tags_wrap" style="display:flex;flex-wrap:wrap;gap:4px;"></span>
                        <i class="fas fa-arrow-right" style="color:var(--muted);font-size:10px;"></i>
                        <span id="rem_prev_total" class="badge badge-yellow">0 usuários</span>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot"><button onclick="executarRemoverTags()" class="btn-danger"><i class="fas fa-trash-alt"></i> Executar Remoção</button></div>
        </div>
    </div>

    <!-- MODAL ATA -->
    <div id="modalAta" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:650px;">
            <div class="dec-modal-header">
                <div><div class="dec-modal-title"><i class="fas fa-file-signature" style="color:#4338ca;margin-right:7px;"></i>Registro de Ata</div></div>
                <button onclick="fecharModal()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label class="field-label">Curso</label>
                        <select id="ata_curso" class="dec-select">
                            <option value="">Selecione...</option>
                            <?php $cm = $conexao->query("SELECT nome FROM cursos WHERE status='Aberto'");
                            if ($cm) { while ($r = $cm->fetch_assoc()) { echo "<option value='" . htmlspecialchars($r['nome'], ENT_QUOTES) . "'>" . htmlspecialchars($r['nome']) . "</option>"; } } ?>
                        </select>
                    </div>
                    <div><label class="field-label">Nome da Aula</label><input type="text" id="ata_aula" placeholder="Ex: Módulo I" class="dec-input"></div>
                    <div><label class="field-label">Instrutor</label><input type="text" id="ata_instrutor" placeholder="Responsável" class="dec-input"></div>
                    <div style="display:flex;align-items:flex-end;"><span class="badge badge-blue" style="padding:9px 13px;font-size:12px;"><i class="fas fa-calendar" style="margin-right:5px;"></i><?php echo date('d/m/Y'); ?></span></div>
                    <div><label class="field-label">Duração da Aula</label><input type="text" id="ata_duracao" placeholder="Ex: 2 horas" class="dec-input"></div>
                    <div><label class="field-label">Turno da Aula</label><input type="text" id="ata_turno" placeholder="Ex: Manhã / Tarde / Noite" class="dec-input"></div>
                </div>
                <div><label class="field-label">Observações</label><textarea id="ata_obs" rows="2" placeholder="Pontos principais..." class="dec-textarea"></textarea></div>

                <!-- Auxiliares -->
                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:13px;margin-top:4px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <label class="field-label" style="margin-bottom:0;">Teve Auxiliar?</label>
                        <div style="display:flex;gap:8px;">
                            <button type="button" id="btn_aux_sim" onclick="toggleAuxiliar(true)" style="padding:5px 14px;border-radius:7px;border:1px solid var(--border);font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;background:var(--white);color:var(--muted);">Sim</button>
                            <button type="button" id="btn_aux_nao" onclick="toggleAuxiliar(false)" style="padding:5px 14px;border-radius:7px;border:1px solid var(--blue);font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;background:#dbeafe;color:var(--blue);">Não</button>
                        </div>
                    </div>
                    <div id="bloco_auxiliares" style="display:none;">
                        <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Busque por RGPM ou ID Discord. Somente instrutores/auxiliares (nível 2) serão exibidos.</div>
                        <div style="display:flex;gap:6px;margin-bottom:8px;">
                            <input type="text" id="ata_busca_aux" placeholder="RGPM ou ID Discord..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter'){buscarAuxiliarAta();}" oninput="debounceAuxBusca()">
                            <button type="button" onclick="buscarAuxiliarAta()" style="padding:9px 14px;background:#7c3aed;color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i></button>
                        </div>
                        <div id="ata_resultados_aux" style="display:none;background:#fff;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;max-height:150px;overflow-y:auto;"></div>
                        <div id="ata_auxiliares_selecionados" style="display:flex;flex-wrap:wrap;gap:6px;min-height:20px;"></div>
                    </div>
                </div>

                <!-- Pesquisa de Alunos -->
                <div>
                    <label class="field-label">Alunos Presentes <span style="color:var(--muted);font-weight:400;">(Nível 3 — RGPM ou ID Discord)</span></label>
                    <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Busque e adicione alunos à lista de presença.</div>
                    <div style="display:flex;gap:6px;margin-bottom:8px;">
                        <input type="text" id="ata_busca_aluno" placeholder="RGPM ou ID Discord..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter'){buscarAlunoAta();}" oninput="debounceAtaBusca()">
                        <button type="button" onclick="buscarAlunoAta()" style="padding:9px 14px;background:#4338ca;color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i></button>
                    </div>
                    <div id="ata_resultados_busca" style="display:none;background:#f8fafc;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;max-height:180px;overflow-y:auto;"></div>
                    <div id="ata_alunos_chips" style="display:flex;flex-wrap:wrap;gap:6px;min-height:20px;margin-bottom:6px;"></div>
                    <div id="ata-preview-alunos" style="display:none;margin-top:4px;padding:9px 12px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--blue);margin-bottom:5px;">Preview — <span id="ata-preview-count">0</span> aluno(s)</div>
                        <div id="ata-preview-lista" style="font-size:12px;color:var(--text);line-height:1.8;font-family:monospace;max-height:120px;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot"><button onclick="gerarPDFFinal()" class="btn-primary" style="background:#4338ca;"><i class="fas fa-file-pdf"></i> GERAR PDF</button></div>
        </div>
    </div>

    <!-- MODAL EDITAR ATA (apenas nível 1 ADM) -->
    <?php if ($nivelSessao <= NIVEL_ADM): ?>
    <div id="modalEditarAta" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:600px;">
            <div class="dec-modal-header">
                <div>
                    <div class="dec-modal-title"><i class="fas fa-file-signature" style="color:#0891b2;margin-right:7px;"></i>Editar Ata</div>
                    <div class="dec-modal-sub" id="ea-sub">Carregando...</div>
                </div>
                <button onclick="fecharModalEditarAta()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <input type="hidden" id="ea-id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:4px;">
                    <div><label class="field-label">Nome da Aula</label><input type="text" id="ea-nome" placeholder="Ex: Módulo I" class="dec-input"></div>
                    <div><label class="field-label">Instrutor</label><input type="text" id="ea-instrutor" placeholder="Responsável" class="dec-input"></div>
                </div>
                <div style="margin-bottom:4px;"><label class="field-label">Observações</label><textarea id="ea-obs" rows="2" placeholder="Pontos principais..." class="dec-textarea"></textarea></div>
                <div>
                    <label class="field-label">Lista de Alunos Presentes</label>
                    <div style="font-size:11px;color:var(--muted);margin-bottom:5px;">Edite livremente — um aluno por linha.</div>
                    <textarea id="ea-lista" rows="10" placeholder="123456&#10;789012&#10;456789" class="dec-textarea"></textarea>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalEditarAta()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button onclick="salvarEdicaoAta()" id="ea-btn-salvar" class="btn-primary" style="flex:2;background:#0891b2;"><i class="fas fa-save"></i> Salvar Alterações</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL LOGS DE PAGAMENTOS -->
    <div id="modalLogsPagamentos" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:780px;">
            <div class="dec-modal-header" style="background:#f0f9ff;border-bottom:1px solid #bae6fd;">
                <div>
                    <div class="dec-modal-title" style="color:#0369a1;"><i class="fas fa-history" style="margin-right:7px;"></i>Logs de Pagamentos Validados</div>
                    <div class="dec-modal-sub">Histórico de todos os pagamentos aceitos</div>
                </div>
                <button onclick="fecharModalLogs()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:14px 20px;border-bottom:1px solid var(--border);background:var(--light);display:flex;gap:8px;">
                <input id="logs-busca" type="text" placeholder="Buscar por nome, RGPM, Discord ou Curso..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter')buscarLogs()">
                <button onclick="buscarLogs()" style="padding:9px 16px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;"><i class="fas fa-search"></i> Buscar</button>
                <button onclick="buscarLogs(true)" style="padding:9px 12px;background:var(--light);color:var(--muted);border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;" title="Limpar busca"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body" style="padding:0;max-height:55vh;overflow-y:auto;">
                <div id="logs-lista">
                    <div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
                </div>
            </div>
            <!-- Lightbox comprovante de logs — melhorado com zoom, rotação e drag -->
            <div id="logs-lightbox" style="display:none;position:fixed;inset:0;background:rgba(5,10,20,0.97);z-index:999999;flex-direction:column;align-items:stretch;justify-content:space-between;">
                <!-- Barra topo -->
                <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 16px;background:rgba(0,0,0,0.45);border-bottom:1px solid rgba(255,255,255,0.08);flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:9px;color:rgba(255,255,255,0.85);font-size:13px;font-weight:700;">
                        <i class="fas fa-history" style="color:#60a5fa;"></i>
                        <span id="logs-lb-nome">Comprovante do Log</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                        <a id="logs-lb-download" href="#" download class="lb-ctrl" style="background:rgba(22,163,74,0.28);border:1px solid rgba(34,197,94,0.28);"><i class="fas fa-download"></i> Baixar</a>
                        <button onclick="fecharLogsLightbox()" class="lb-ctrl" style="background:rgba(220,38,38,0.28);border:1px solid rgba(252,165,165,0.25);"><i class="fas fa-times"></i> Fechar</button>
                    </div>
                </div>
                <!-- Área imagem -->
                <div id="logs-lb-area" style="flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;">
                    <div id="logs-lb-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:rgba(255,255,255,0.6);font-size:14px;font-weight:600;">
                        <i class="fas fa-spinner fa-spin" style="font-size:2.5rem;color:rgba(255,255,255,0.4);"></i>
                        Carregando comprovante...
                    </div>
                    <img id="logs-lb-img" src="" alt="Comprovante" style="display:none;transition:transform 0.15s ease;cursor:grab;max-width:90vw;max-height:75vh;object-fit:contain;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,0.6);user-select:none;">
                </div>
                <!-- Barra controles -->
                <div style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px 16px;background:rgba(0,0,0,0.45);border-top:1px solid rgba(255,255,255,0.08);flex-wrap:wrap;">
                    <button onclick="llbZoom(-0.3)" class="lb-ctrl" title="Reduzir"><i class="fas fa-search-minus"></i></button>
                    <span id="logs-lb-zoom-badge" style="background:rgba(0,0,0,0.55);color:rgba(255,255,255,0.85);font-size:12px;font-weight:700;padding:5px 11px;border-radius:20px;min-width:54px;text-align:center;">100%</span>
                    <button onclick="llbZoom(+0.3)" class="lb-ctrl" title="Ampliar"><i class="fas fa-search-plus"></i></button>
                    <button onclick="llbReset()" class="lb-ctrl"><i class="fas fa-expand-arrows-alt"></i> Ajustar</button>
                    <button onclick="llbZoom100()" class="lb-ctrl"><i class="fas fa-eye"></i> 100%</button>
                    <div style="width:1px;height:22px;background:rgba(255,255,255,0.15);margin:0 3px;"></div>
                    <button onclick="llbRotar(-90)" class="lb-ctrl"><i class="fas fa-undo"></i></button>
                    <button onclick="llbRotar(+90)" class="lb-ctrl"><i class="fas fa-redo"></i></button>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;justify-content:space-between;align-items:center;">
                <span id="logs-total-label" style="font-size:12px;color:var(--muted);"></span>
                <button onclick="fecharModalLogs()" class="btn-secondary" style="width:auto;padding:8px 18px;">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL CRONOGRAMA -->
    <div id="modalCronograma" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:420px;">
            <div class="dec-modal-header">
                <div><div class="dec-modal-title"><i class="fas fa-calendar-plus" style="color:#d97706;margin-right:7px;"></i>Novo Cronograma</div></div>
                <button onclick="fecharModalCronograma()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <div><label class="field-label">Título</label><input type="text" id="cron_titulo" placeholder="Título..." class="dec-input"></div>
                <div><label class="field-label">Arquivo</label><input type="file" id="cron_arquivo" style="font-size:13px;color:var(--muted);width:100%;"></div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalCronograma()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button onclick="salvarCronograma()" class="btn-primary" style="flex:2;background:#16a34a;"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>

    <script>
        // ── TOKEN CSRF GLOBAL ─────────────────────────────────────────────────────
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

        // ── TAGS POR CURSO (dinâmico do banco) ────────────────────────────────────
        const tagsPorCurso = <?php echo $tagsPorCursoDinamicoJson; ?>;

        // ── TOAST ─────────────────────────────────────────────────────────────────
        function toast(msg, tipo = 'info') {
            const c = document.getElementById('toast');
            const el = document.createElement('div');
            el.className = 'toast-item ' + tipo;
            el.innerHTML = '<i class="fas ' + (tipo === 'success' ? 'fa-check-circle' : tipo === 'error' ? 'fa-times-circle' : 'fa-info-circle') + '" style="margin-right:7px;"></i>' + msg;
            c.appendChild(el);
            requestAnimationFrame(() => el.classList.add('show'));
            setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 3200);
        }

        // ── UTILS ─────────────────────────────────────────────────────────────────
        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        function fmtR(v) {
            return 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // ── API — envia CSRF em toda requisição ───────────────────────────────────
        function api(body) {
            return fetch('painel_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
            }).then(r => {
                if (r.redirected && r.url.includes('login.php')) {
                    toast('⏱️ Sessão expirada. Redirecionando...', 'error');
                    setTimeout(() => window.location.href = 'login.php', 1500);
                    return { sucesso: false, erro: 'Sessão expirada' };
                }
                return r.json();
            }).then(d => {
                if (d && d.force_logout) { window.location.href = 'logout.php'; }
                return d;
            });
        }

        // ── SIDEBAR MOBILE ────────────────────────────────────────────────────────
        function toggleSidebar() {
            const s = document.getElementById('sidebar'), o = document.getElementById('sidebar-overlay');
            s.classList.toggle('open'); o.classList.toggle('active');
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebar-overlay').classList.remove('active');
        }

        // ── ROUTER ────────────────────────────────────────────────────────────────
        function router(id) {
            document.querySelectorAll('.section-view').forEach(s => s.classList.remove('active'));
            const t = document.getElementById(id);
            if (t) t.classList.add('active');
            ['dashboard','administracao','membros','presenca','escala','blacklist'].forEach(k => {
                const el = document.getElementById('nav-' + k);
                if (el) el.classList.toggle('active', k === id);
            });
            const ts = { dashboard:'Dashboard', administracao:'Administração', membros:'Membros', presenca:'Presença', escala:'Cronogramas', blacklist:'Blacklist' };
            document.getElementById('view-title').textContent = ts[id] || id;
            if (typeof syncFabAdm === 'function') syncFabAdm(id);
            if (id === 'blacklist' && typeof carregarBlacklist === 'function') carregarBlacklist();
            closeSidebar();
        }

        // ── POLLING ───────────────────────────────────────────────────────────────
        let _lastAprovados = '', _lastMembros = '', _lastAtas = '', _lastCronogramas = '';

        function iniciarPolling() { atualizarStats(); setInterval(atualizarStats, 30000); }

        function atualizarStats() {
            api('ajax_action=dashboard_stats').then(d => {
                if (!d.sucesso) return;
                renderCourseCards(d.cursos, d.total_geral);
                const badge = document.getElementById('fab-pend-badge');
                if (badge) { badge.textContent = d.pendentes > 0 ? d.pendentes : ''; badge.style.display = d.pendentes > 0 ? 'inline' : 'none'; }
                const apJSON = JSON.stringify(d.lista_aprovados);
                if (apJSON !== _lastAprovados) { _lastAprovados = apJSON; renderAprovados(d.lista_aprovados); }
                const mJSON = JSON.stringify(d.membros);
                if (mJSON !== _lastMembros) { _lastMembros = mJSON; renderMembros(d.membros); }
                if (d.atas) { const aJSON = JSON.stringify(d.atas); if (aJSON !== _lastAtas) { _lastAtas = aJSON; renderAtas(d.atas); } }
                if (d.cronogramas) { const cJSON = JSON.stringify(d.cronogramas); if (cJSON !== _lastCronogramas) { _lastCronogramas = cJSON; renderCronogramas(d.cronogramas); } }
            }).catch(() => {});
        }

        // ── RENDER COURSE CARDS ───────────────────────────────────────────────────
        function renderCourseCards(cursos, totalGeral) {
            const grid = document.getElementById('course-stats-grid');
            if (!grid) return;
            const abertos = cursos.filter(c => c.status === 'Aberto');
            if (!abertos.length) { grid.innerHTML = '<div style="color:var(--muted);font-size:13px;text-align:center;padding:20px;grid-column:1/-1;">Nenhum curso aberto.</div>'; return; }
            grid.innerHTML = abertos.map(c => {
                const cor = c.nome.includes('CFSD') ? '#16a34a' : c.nome.includes('CFO') ? '#4338ca' : c.nome.includes('CFC') ? '#d97706' : '#1a56db';
                return `<div class="csg-card"><div class="csg-header"><i class="fas fa-graduation-cap" style="color:${cor};font-size:11px;"></i><span>${escHtml(c.nome)}</span></div><div class="csg-body"><div class="csg-value">${fmtR(c.arrecadado)}</div><div class="csg-sub">${c.pagantes} pag. × ${fmtR(c.valor_taxa)}/taxa</div><div class="csg-row"><span style="color:var(--muted);">Alistados: <strong style="color:var(--text);">${c.alistados}</strong></span><span class="badge badge-green" style="font-size:10px;">Aberto</span></div></div></div>`;
            }).join('');
            const tv = document.getElementById('total-geral-valor'); if (tv) tv.textContent = fmtR(totalGeral);
            const tr = document.getElementById('total-right-detalhes'); if (tr) tr.innerHTML = abertos.map(c => `<div>${escHtml(c.nome)}: <span>${fmtR(c.arrecadado)}</span> (${c.pagantes} pag.)</div>`).join('');
        }

        // ── RENDER APROVADOS ──────────────────────────────────────────────────────
        function renderAprovados(lista) {
            const tbody = document.getElementById('tbody-aprovados');
            if (!tbody) return;
            const badge = document.getElementById('badge-total-aprovados');
            if (badge) badge.textContent = lista.length + ' aprovado(s)';
            if (!lista.length) { tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-user-slash"></i><p>Nenhum aluno aprovado.</p></div></td></tr>'; document.getElementById('contador_aprovados').textContent = 'Exibindo 0 registro(s)'; return; }
            tbody.innerHTML = lista.map((a, i) => {
                const cu = (a.meus_cursos || '').trim();
                const cl = cu.includes('CFSD') ? 'badge-green' : cu.includes('CFO') ? 'badge-indigo' : cu.includes('CFS') ? 'badge-blue' : 'badge-gray';
                return `<tr class="linha-aprovado" data-curso="${escHtml(cu)}" data-texto="${escHtml((a.nome+' '+a.rgpm+' '+(a.discord||'')).toLowerCase())}"><td style="color:var(--muted);font-size:12px;">${i+1}</td><td class="strong">${escHtml(a.nome)}</td><td style="font-family:monospace;">${escHtml(a.rgpm)}</td><td>${escHtml(a.discord||'—')}</td><td><span class="badge ${cl}">${escHtml(cu||'—')}</span></td></tr>`;
            }).join('');
            filtrarAprovados();
        }

        // ── RENDER MEMBROS ────────────────────────────────────────────────────────
        function renderMembros(lista) {
            const tbody = document.getElementById('tabelaMembros');
            if (!tbody) return;
            if (!lista.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-users"></i><p>Nenhum membro.</p></div></td></tr>'; return; }
            const scMap = { Pendente:'badge-yellow', Aprovado:'badge-green', Reprovado:'badge-red', Ativo:'badge-green', Inativo:'badge-red', Afastado:'badge-yellow' };
            tbody.innerHTML = lista.map(r => {
                const cur = r.meus_cursos ? escHtml(r.meus_cursos) : '<span style="color:var(--muted)">—</span>';
                const sc = scMap[r.status] || 'badge-gray';
                const nivel = parseInt(r.nivel);
                const nivelLabel = nivel===1 ? '<span class="badge badge-red">ADM</span>' : nivel===2 ? '<span class="badge badge-indigo">Instrutor</span>' : '<span class="badge badge-blue">Aluno</span>';
                // Verifica taxa paga: usa campo do banco (pagamentos_pendentes validado) OU tags
                let taxaPaga = !!(parseInt(r.taxa_paga));
                if (!taxaPaga && r.tags) { try { const tg=JSON.parse(r.tags); if(Array.isArray(tg)) taxaPaga=tg.some(t=>t.toLowerCase().includes('pagou')); } catch(e){} }
                const taxaHtml = nivel===3 ? (taxaPaga ? '<span class="badge badge-green"><i class="fas fa-check-circle"></i> Pago</span>' : '<span class="badge badge-red"><i class="fas fa-times-circle"></i> Não pago</span>') : '<span style="color:var(--muted);font-size:11px;">—</span>';
                const taxaData = taxaPaga ? 'pago' : 'nao_pago';
                return `<tr data-nivel="${nivel}" data-taxa="${taxaData}" data-texto="${escHtml((r.nome+' '+r.rgpm+' '+(r.discord||'')).toLowerCase())}"><td class="strong">${escHtml(r.nome)}</td><td style="font-family:monospace;">${escHtml(r.rgpm)}</td><td>${escHtml(r.discord||'—')}</td><td>${cur}</td><td><span class="badge ${sc}">${escHtml(r.status)}</span></td><td>${nivelLabel}</td><td style="text-align:center;">${taxaHtml}</td></tr>`;
            }).join('');
            filtrarMembros();
        }

        function filtrarMembros() {
            const f = (document.getElementById('pesquisaMembro')?.value||'').toLowerCase();
            const nivelFiltro = document.getElementById('filtroNivelMembro')?.value||'';
            const taxaFiltro = document.getElementById('filtroTaxaMembro')?.value||'';
            let visiveis = 0;
            document.querySelectorAll('#tabelaMembros tr').forEach(tr => {
                const texto = tr.dataset.texto||'';
                const nivel = tr.dataset.nivel||'';
                const taxa = tr.dataset.taxa||'';
                const ok = (!f || texto.includes(f)) && (!nivelFiltro || nivel===nivelFiltro) && (!taxaFiltro || taxa===taxaFiltro);
                tr.style.display = ok ? '' : 'none';
                if (ok) visiveis++;
            });
            const cont = document.getElementById('contador_membros');
            if (cont) cont.textContent = `Exibindo ${visiveis} membro(s)`;
            const badge = document.getElementById('badge-total-membros');
            if (badge) { badge.textContent = visiveis + ' membro(s)'; badge.style.display = 'inline-flex'; }
        }

        function limparFiltrosMembros() {
            document.getElementById('pesquisaMembro').value='';
            document.getElementById('filtroNivelMembro').value='';
            document.getElementById('filtroTaxaMembro').value='';
            filtrarMembros();
        }

        // ── RENDER ATAS ───────────────────────────────────────────────────────────
        const NIVEL_SESSAO = <?php echo intval($nivelSessao); ?>;

        function renderAtas(lista) {
            const tbody = document.getElementById('tbody-presenca');
            if (!tbody) return;
            if (!lista.length) { tbody.innerHTML = "<tr><td colspan='5'><div class='empty-state'><i class='fas fa-inbox'></i><p>Nenhuma ata.</p></div></td></tr>"; return; }
            tbody.innerHTML = lista.map(row => {
                const dt = new Date(row.data_registro).toLocaleString('pt-BR');
                const nome = escHtml(row.nome_referencia);
                const editarCol = NIVEL_SESSAO <= 1 ?
                    `<td style='text-align:center;'><button class='btn-dl btn-editar-ata' data-id='${row.id}' data-nome='${escHtml(row.nome_referencia)}' style='background:#0891b2;color:white;border:none;border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;'><i class='fas fa-user-plus'></i> Editar</button></td>` : '';
                return `<tr><td class='strong'>${nome}</td><td>${dt}</td><td style='text-align:center;'><a href='baixar_ata.php?id=${encodeURIComponent(row.id)}' class='btn-dl'><i class='fas fa-file-pdf'></i> PDF</a></td><td style='text-align:center;'><button class='btn-del-sm btn-del-ata' data-id='${row.id}' data-nome='${escHtml(row.nome_referencia)}'><i class='fas fa-trash-alt'></i> Excluir</button></td>${editarCol}</tr>`;
            }).join('');
            tbody.querySelectorAll('.btn-del-ata').forEach(btn => { btn.addEventListener('click', () => confirmarDeletarAta(btn.dataset.id, btn.dataset.nome)); });
            tbody.querySelectorAll('.btn-editar-ata').forEach(btn => { btn.addEventListener('click', () => abrirModalEditarAta(btn.dataset.id, btn.dataset.nome)); });
        }

        // ── RENDER CRONOGRAMAS ────────────────────────────────────────────────────
        function renderCronogramas(lista) {
            const tbody = document.getElementById('tbody-cronogramas');
            if (!tbody) return;
            if (!lista.length) { tbody.innerHTML = "<tr><td colspan='4'><div class='empty-state'><i class='fas fa-inbox'></i><p>Nenhum cronograma.</p></div></td></tr>"; return; }
            tbody.innerHTML = lista.map(row => {
                const dt = new Date(row.data_envio).toLocaleString('pt-BR');
                const titulo = escHtml(row.titulo);
                return `<tr><td class='strong'>${titulo}</td><td>${dt}</td><td style='text-align:center;'><a href='baixar_cronograma.php?id=${encodeURIComponent(row.id)}' class='btn-dl'><i class='fas fa-download'></i> Baixar</a></td><td style='text-align:center;'><button class='btn-del-sm btn-del-cron' data-id='${row.id}' data-titulo='${escHtml(row.titulo)}'><i class='fas fa-trash-alt'></i> Excluir</button></td></tr>`;
            }).join('');
            tbody.querySelectorAll('.btn-del-cron').forEach(btn => { btn.addEventListener('click', () => confirmarDeletarCronograma(btn.dataset.id, btn.dataset.titulo)); });
        }

        // ── DELETAR ATA ───────────────────────────────────────────────────────────
        function confirmarDeletarAta(id, nome) {
            vpConfirm('Excluir Ata', `Tem certeza que deseja excluir a ata <strong>${escHtml(nome)}</strong>? Esta ação é irreversível.`, '#dc2626', '<i class="fas fa-trash-alt"></i>', async () => {
                const d = await api(`ajax_action=deletar_ata&id=${encodeURIComponent(id)}`);
                if (d.sucesso) { toast('🗑️ Ata excluída!', 'success'); _lastAtas = ''; atualizarStats(); }
                else toast('❌ ' + (d.erro || 'Falha ao excluir'), 'error');
            });
        }

        // ── DELETAR CRONOGRAMA ────────────────────────────────────────────────────
        function confirmarDeletarCronograma(id, titulo) {
            vpConfirm('Excluir Cronograma', `Tem certeza que deseja excluir o cronograma <strong>${escHtml(titulo)}</strong>? Esta ação é irreversível.`, '#dc2626', '<i class="fas fa-trash-alt"></i>', async () => {
                const d = await api(`ajax_action=deletar_cronograma&id=${encodeURIComponent(id)}`);
                if (d.sucesso) { toast('🗑️ Cronograma excluído!', 'success'); _lastCronogramas = ''; atualizarStats(); }
                else toast('❌ ' + (d.erro || 'Falha ao excluir'), 'error');
            });
        }

        // ── FAB ───────────────────────────────────────────────────────────────────
        let fabOpen = false;
        function toggleFab() {
            fabOpen = !fabOpen;
            const m = document.getElementById('fab-adm-menu'), i = document.getElementById('fab-adm-icon');
            if (fabOpen) { m.classList.add('open'); i.style.transform = 'rotate(120deg)'; }
            else { m.classList.remove('open'); i.style.transform = ''; }
        }
        document.addEventListener('click', e => { if (!document.getElementById('fab-adm')?.contains(e.target) && fabOpen) toggleFab(); });
        function syncFabAdm(s) { const f = document.getElementById('fab-adm'); if (!f) return; f.style.display = (s === 'administracao') ? 'flex' : 'none'; if (fabOpen) toggleFab(); }
        function adm_opcao1() { abrirModalSetarTags(); toggleFab(); }
        function adm_opcao2() { abrirModalRemoverTags(); toggleFab(); }
        function adm_opcao3() { abrirModalEditarCurso(); toggleFab(); }
        function adm_opcao4() { abrirModalNovoCurso(); toggleFab(); }
        function adm_opcao5() { abrirModalEliminarCurso(); toggleFab(); }
        function adm_opcao6() { abrirModalValidarPagamentos(); toggleFab(); }
        function adm_opcao7() { abrirModalEditarUsuario(); toggleFab(); }
        function adm_opcao8() { gerarRelatorioPagamentos(); toggleFab(); }
        function adm_opcao9() { abrirModalLogs(); toggleFab(); }

        // ── LOGS DE PAGAMENTOS ─────────────────────────────────────────────────
        function abrirModalLogs() {
            document.getElementById('logs-busca').value = '';
            document.getElementById('modalLogsPagamentos').classList.remove('hidden');
            buscarLogs(true);
        }
        function fecharModalLogs() {
            document.getElementById('modalLogsPagamentos').classList.add('hidden');
        }
        function buscarLogs(limpar) {
            if (limpar === true) document.getElementById('logs-busca').value = '';
            const busca = (document.getElementById('logs-busca').value || '').trim();
            const lista = document.getElementById('logs-lista');
            lista.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';

            api(`ajax_action=logs_pagamentos&busca=${encodeURIComponent(busca)}`).then(d => {
                document.getElementById('logs-total-label').textContent = d.total > 0 ? `${d.total} registro(s)` : 'Nenhum registro';
                if (!d.sucesso || !d.total) {
                    lista.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-check-double" style="font-size:2rem;color:#16a34a;display:block;margin-bottom:10px;"></i><p style="font-weight:700;color:#15803d;">Nenhum pagamento validado encontrado.</p></div>';
                    return;
                }
                lista.innerHTML = d.logs.map(p => {
                    const bClass = p.curso.includes('CFSD') ? 'badge-green' : p.curso.includes('CFO') ? 'badge-indigo' : p.curso.includes('CFC') ? 'badge-yellow' : 'badge-gray';
                    const dtEnvio = new Date(p.data_envio).toLocaleString('pt-BR');
                    const dtValid = p.validado_em ? new Date(p.validado_em).toLocaleString('pt-BR') : '—';
                    const validNome = escHtml(p.validado_por || '—');
                    const validRgpm = escHtml(p.validado_por_rgpm || '');
                    return `<div style="display:flex;align-items:flex-start;gap:14px;padding:15px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;">
                        <div style="flex:1;min-width:220px;">
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:5px;flex-wrap:wrap;">
                                <span style="font-size:13px;font-weight:800;color:var(--text);">${escHtml(p.nome)}</span>
                                <span class="badge ${bClass}">${escHtml(p.curso)}</span>
                                <span class="badge badge-green" style="font-size:10px;"><i class="fas fa-check" style="margin-right:3px;"></i>Validado</span>
                            </div>
                            <div style="font-size:11px;color:var(--muted);display:flex;flex-direction:column;gap:3px;">
                                <span><i class="fas fa-id-badge" style="margin-right:4px;color:#64748b;"></i>RGPM do Aluno: <strong style="color:var(--text);">${escHtml(p.rgpm || '—')}</strong></span>
                                <span><i class="fab fa-discord" style="margin-right:4px;color:#7c3aed;"></i>${escHtml(p.discord || '—')}</span>
                                <span><i class="fas fa-upload" style="margin-right:4px;color:#64748b;"></i>Enviado em: ${dtEnvio}</span>
                            </div>
                        </div>
                        <div style="min-width:200px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 13px;">
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#15803d;margin-bottom:5px;"><i class="fas fa-user-check" style="margin-right:4px;"></i>Validado por</div>
                            <div style="font-size:13px;font-weight:800;color:var(--text);">${validNome}</div>
                            ${validRgpm ? `<div style="font-size:11px;color:var(--muted);">RGPM: <strong style="color:var(--text);">${validRgpm}</strong></div>` : ''}
                            <div style="font-size:11px;color:var(--muted);margin-top:3px;"><i class="fas fa-clock" style="margin-right:3px;"></i>${dtValid}</div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                            <button onclick="verComprovanteLog(${p.id},'${escHtml(p.nome)}')" style="background:#dbeafe;color:#1a56db;border:1px solid #bfdbfe;border-radius:7px;padding:7px 12px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;"><i class="fas fa-image"></i> Ver</button>
                            <button onclick="baixarComprovanteLog(${p.id},'${escHtml(p.nome)}')" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;border-radius:7px;padding:7px 12px;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;"><i class="fas fa-download"></i> Baixar</button>
                        </div>
                    </div>`;
                }).join('');
            });
        }

        // ── LIGHTBOX LOGS — zoom, rotação, drag ───────────────────────────────────
        let _llbZ=1, _llbRot=0, _llbTX=0, _llbTY=0, _llbDrag=false, _llbDX=0, _llbDY=0;
        function _llbApply(){
            const img=document.getElementById('logs-lb-img');
            img.style.transform=`translate(${_llbTX}px,${_llbTY}px) scale(${_llbZ}) rotate(${_llbRot}deg)`;
            document.getElementById('logs-lb-zoom-badge').textContent=Math.round(_llbZ*100)+'%';
        }
        function llbZoom(d){ _llbZ=Math.min(6,Math.max(0.15,_llbZ+d)); _llbApply(); }
        function llbZoom100(){ _llbZ=1; _llbTX=0; _llbTY=0; _llbApply(); }
        function llbReset(){ _llbZ=1; _llbRot=0; _llbTX=0; _llbTY=0; _llbApply(); }
        function llbRotar(d){ _llbRot=(_llbRot+d+360)%360; _llbApply(); }
        (function(){
            const lb=document.getElementById('logs-lightbox');
            const gi=()=>document.getElementById('logs-lb-img');
            lb.addEventListener('mousedown',e=>{ if(e.target!==gi()) return; _llbDrag=true; _llbDX=e.clientX-_llbTX; _llbDY=e.clientY-_llbTY; gi().style.cursor='grabbing'; e.preventDefault(); });
            lb.addEventListener('mousemove',e=>{ if(!_llbDrag) return; _llbTX=e.clientX-_llbDX; _llbTY=e.clientY-_llbDY; _llbApply(); });
            lb.addEventListener('mouseup',()=>{ _llbDrag=false; gi().style.cursor='grab'; });
            lb.addEventListener('mouseleave',()=>{ _llbDrag=false; gi().style.cursor='grab'; });
            lb.addEventListener('wheel',e=>{ e.preventDefault(); llbZoom(e.deltaY<0?0.15:-0.15); },{passive:false});
            let _ptd=null;
            lb.addEventListener('touchstart',e=>{ if(e.touches.length===2) _ptd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY); });
            lb.addEventListener('touchmove',e=>{ if(e.touches.length===2&&_ptd){ const nd=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY); _llbZ=Math.min(6,Math.max(0.15,_llbZ*(nd/_ptd))); _ptd=nd; _llbApply(); e.preventDefault(); }},{passive:false});
            document.addEventListener('keydown',e=>{ if(e.key==='Escape'&&lb.style.display!=='none') fecharLogsLightbox(); });
        })();

        async function verComprovanteLog(id, nome) {
            const lb = document.getElementById('logs-lightbox');
            const img = document.getElementById('logs-lb-img');
            const ld = document.getElementById('logs-lb-loading');
            const dl = document.getElementById('logs-lb-download');
            _llbZ=1; _llbRot=0; _llbTX=0; _llbTY=0;
            img.style.transform=''; img.style.display='none'; ld.style.display='flex';
            dl.href='#';
            const nomeEl=document.getElementById('logs-lb-nome');
            if(nomeEl) nomeEl.textContent=nome?`Log — ${nome}`:'Comprovante do Log';
            lb.style.display='flex';
            const d = await api(`ajax_action=ver_comprovante_log&id=${id}`);
            ld.style.display='none';
            if (d.sucesso) {
                const src = `data:${d.mime};base64,${d.imagem}`;
                img.src = src; img.style.display='block'; _llbApply();
                dl.href = src;
                dl.download = `comprovante_${id}_${nome.replace(/\s+/g,'_')}.${d.mime.split('/')[1]||'jpg'}`;
            } else {
                toast('❌ ' + (d.erro || 'Comprovante não encontrado'), 'error');
                lb.style.display = 'none';
            }
        }

        async function baixarComprovanteLog(id, nome) {
            const d = await api(`ajax_action=ver_comprovante_log&id=${id}`);
            if (d.sucesso) {
                const a = document.createElement('a');
                a.href = `data:${d.mime};base64,${d.imagem}`;
                a.download = `comprovante_${id}_${nome.replace(/\s+/g,'_')}.${d.mime.split('/')[1]||'jpg'}`;
                a.click();
            } else toast('❌ ' + (d.erro || 'Falha ao baixar'), 'error');
        }

        function fecharLogsLightbox() {
            document.getElementById('logs-lightbox').style.display = 'none';
            _llbZ=1; _llbRot=0; _llbTX=0; _llbTY=0;
        }

        // ── NÍVEL ─────────────────────────────────────────────────────────────────
        function abrirModalNivel() { document.getElementById('modalNivel').classList.remove('hidden'); }
        function fecharModalNivel() { document.getElementById('modalNivel').classList.add('hidden'); }
        function atualizarNivel() {
            const r = document.getElementById('nivel_rgpm').value, n = document.getElementById('novo_nivel').value;
            if (!r || !n) { toast('Preencha todos os campos', 'error'); return; }
            fetch('atualizar_nivel.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `rgpm=${encodeURIComponent(r)}&nivel=${encodeURIComponent(n)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}` })
                .then(res => res.text()).then(d => {
                    d = d.trim();
                    if (d === 'sucesso') { toast('✅ Nível atualizado!', 'success'); fecharModalNivel(); atualizarStats(); }
                    else toast('❌ ' + d, 'error');
                });
        }

        // ── APROVADOS FILTRO ──────────────────────────────────────────────────────
        function filtrarAprovados() {
            const c = document.getElementById('filtro_aprovados').value.toLowerCase(), b = document.getElementById('busca_aprovados').value.toLowerCase();
            let v = 0;
            document.querySelectorAll('.linha-aprovado').forEach(tr => {
                const ok = (!c || tr.dataset.curso.toLowerCase().includes(c)) && (!b || tr.dataset.texto.includes(b));
                tr.style.display = ok ? '' : 'none'; if (ok) v++;
            });
            document.getElementById('contador_aprovados').textContent = `Exibindo ${v} registro(s)`;
        }

        // ── PDF APROVADOS ─────────────────────────────────────────────────────────
        function baixarAprovadosPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const cb = document.getElementById('filtro_aprovados').value || 'TODOS';
            const hoje = new Date().toLocaleDateString('pt-BR');
            doc.setFillColor(13,27,46); doc.rect(0,0,210,28,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(14); doc.setFont(undefined,'bold');
            doc.text('RELATÓRIO DE ALUNOS APROVADOS', 105, 12, { align:'center' });
            doc.setFontSize(8); doc.setFont(undefined,'normal');
            doc.text(`DEC · PMESP | Curso: ${cb} | ${hoje}`, 105, 22, { align:'center' });
            const lv = [...document.querySelectorAll('.linha-aprovado')].filter(t => t.style.display !== 'none');
            const corpo = lv.map((t,i) => { const d = t.querySelectorAll('td'); return [i+1, d[1]?.innerText||'', d[2]?.innerText||'', d[3]?.innerText||'', d[4]?.innerText?.trim()||'']; });
            doc.autoTable({ startY:34, head:[['#','Nome','RGPM','Discord','Curso']], body:corpo, headStyles:{ fillColor:[13,27,46], textColor:255, fontStyle:'bold', fontSize:9 }, bodyStyles:{ fontSize:8 }, alternateRowStyles:{ fillColor:[240,247,255] } });
            const tp = doc.internal.getNumberOfPages();
            for (let p=1;p<=tp;p++) { doc.setPage(p); doc.setFontSize(7); doc.setTextColor(150); doc.text(`Página ${p} de ${tp} · DEC PMESP · ${hoje}`, 105, 292, { align:'center' }); }
            doc.save(`Aprovados_${cb}_${hoje.replace(/\//g,'-')}.pdf`);
        }

        // ── RELATÓRIO PAGAMENTOS PDF ──────────────────────────────────────────────
        function gerarRelatorioPagamentos() {
            api('ajax_action=relatorio_pagamentos').then(d => {
                if (!d.sucesso) { toast('Erro ao gerar relatório', 'error'); return; }
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                const hoje = new Date().toLocaleDateString('pt-BR');
                doc.setFillColor(13,27,46); doc.rect(0,0,210,28,'F');
                doc.setTextColor(255,255,255); doc.setFontSize(14); doc.setFont(undefined,'bold');
                doc.text('RELATÓRIO DE PAGAMENTOS — DEC PMESP', 105, 18, { align:'center' });
                let y=36, totalGeral=0;

                // ── Seções por curso (taxas) ──────────────────────────────────
                d.dados.forEach(curso => {
                    if (y>260) { doc.addPage(); y=20; }
                    const totalCurso = curso.total + (curso.total_multas||0);
                    doc.setFillColor(17,34,64); doc.rect(10,y,190,10,'F');
                    doc.setTextColor(255,255,255); doc.setFontSize(10); doc.setFont(undefined,'bold');
                    doc.text(`${curso.curso}  |  Taxa: ${fmtR(curso.valor_taxa)}  |  Total: ${fmtR(totalCurso)}  |  ${curso.pagantes.length} pag.`, 14, y+7);
                    y+=14;
                    if (curso.pagantes.length>0) {
                        doc.autoTable({ startY:y, head:[['#','Nome','RGPM','Discord']], body:curso.pagantes.map((p,i)=>[i+1,p.nome||'—',p.rgpm||'—',p.discord||'—']), headStyles:{ fillColor:[13,27,46], textColor:255, fontStyle:'bold', fontSize:9 }, bodyStyles:{ fontSize:8 }, alternateRowStyles:{ fillColor:[240,247,255] }, margin:{ left:10, right:10 } });
                        y = doc.lastAutoTable.finalY+12;
                    } else { doc.setTextColor(150); doc.setFontSize(9); doc.text('Nenhum pagante.', 14, y); y+=10; }
                    totalGeral += totalCurso;
                });

                // ── Seção unificada de MULTAS (todos os cursos + sem curso) ──
                // Coleta todas as multas pagas: as de cursos + as sem curso
                let todasMultas = [];
                d.dados.forEach(curso => {
                    (curso.multas_pagas||[]).forEach(m => {
                        todasMultas.push({ nome: m.nome, rgpm: m.rgpm, discord: m.discord, curso: curso.curso, valor: m.valor });
                    });
                });
                (d.multas_sem_curso||[]).forEach(m => {
                    todasMultas.push({ nome: m.nome, rgpm: m.rgpm, discord: m.discord, curso: m.curso_aluno ? m.curso_aluno + ' (sem taxa)' : 'Não fez o pagamento da taxa do curso', valor: m.valor });
                });

                if (todasMultas.length > 0) {
                    const totalTodasMultas = todasMultas.reduce((s,m) => s + parseFloat(m.valor||0), 0);
                    totalGeral += (d.total_sem_curso || 0); // adiciona multas sem curso ao total geral
                    if (y > 240) { doc.addPage(); y = 20; }
                    doc.setFillColor(180,83,9); doc.rect(10,y,190,10,'F');
                    doc.setTextColor(255,255,255); doc.setFontSize(10); doc.setFont(undefined,'bold');
                    doc.text(`MULTAS  |  Total: ${fmtR(totalTodasMultas)}  |  ${todasMultas.length} multa(s)`, 14, y+7);
                    y += 14;
                    doc.autoTable({
                        startY: y,
                        head: [['#','Nome','RGPM','ID Discord','Curso','Valor da Multa']],
                        body: todasMultas.map((m,i) => [
                            i+1,
                            m.nome||'—',
                            m.rgpm||'—',
                            m.discord||'—',
                            m.curso||'—',
                            fmtR(parseFloat(m.valor)||0)
                        ]),
                        headStyles:{ fillColor:[217,119,6], textColor:255, fontStyle:'bold', fontSize:9 },
                        bodyStyles:{ fontSize:8, textColor:[92,45,0] },
                        alternateRowStyles:{ fillColor:[255,243,220] },
                        columnStyles:{
                            4:{ textColor:[120,53,15], fontStyle:'italic' },
                            5:{ fontStyle:'bold', textColor:[180,83,9] }
                        },
                        margin:{ left:10, right:10 }
                    });
                    y = doc.lastAutoTable.finalY + 12;
                }

                if (y>260) { doc.addPage(); y=20; }
                doc.setFillColor(13,27,46); doc.rect(10,y,190,12,'F');
                doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont(undefined,'bold');
                doc.text(`TOTAL GERAL ARRECADADO: ${fmtR(totalGeral)}`, 105, y+8, { align:'center' });
                const tp = doc.internal.getNumberOfPages();
                for (let p=1;p<=tp;p++) { doc.setPage(p); doc.setFontSize(7); doc.setTextColor(150); doc.text(`Página ${p} de ${tp} · DEC PMESP · ${hoje}`, 105, 292, { align:'center' }); }
                doc.save(`Relatorio_Pagamentos_${hoje.replace(/\//g,'-')}.pdf`);
            }).catch(e => toast('Erro: ' + e.message, 'error'));
        }

        // ── NOVO CURSO ────────────────────────────────────────────────────────────
        function abrirModalNovoCurso() {
            ['nc-nome','nc-descricao'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
            document.getElementById('nc-tipo').value = 'Curso de Formação';
            document.getElementById('nc-status').value = 'Aberto';
            document.getElementById('nc-taxa').value = '';
            document.getElementById('nc-tags').value = '';
            document.getElementById('nc-tags-preview').style.display = 'none';
            document.getElementById('nc-preview').style.display = 'none';
            document.getElementById('modal-novo-curso').classList.remove('hidden');
        }
        function fecharModalNovoCurso() { document.getElementById('modal-novo-curso').classList.add('hidden'); }
        function atualizarPreviewCurso() {
            const nome = (document.getElementById('nc-nome').value || '').trim(), prev = document.getElementById('nc-preview');
            if (!nome) { prev.style.display = 'none'; } else {
                prev.style.display = 'block';
                document.getElementById('nc-prev-nome').textContent = nome;
                document.getElementById('nc-prev-tipo').textContent = document.getElementById('nc-tipo').value;
                const status = document.getElementById('nc-status').value, b = document.getElementById('nc-prev-status');
                b.textContent = status; b.className = 'badge ' + (status === 'Aberto' ? 'badge-green' : 'badge-red');
                const taxa = parseFloat(document.getElementById('nc-taxa').value) || 0;
                document.getElementById('nc-prev-taxa').textContent = taxa > 0 ? `Taxa: ${fmtR(taxa)}` : 'Sem taxa';
            }
            const tagsRaw = (document.getElementById('nc-tags').value || '').trim();
            const tagsPrev = document.getElementById('nc-tags-preview');
            if (tagsRaw) {
                const extras = tagsRaw.split(',').map(t => t.trim()).filter(Boolean);
                let tagPag = null;
                for (const k of ['CFSD','CFC','CFS','CFO']) { if (new RegExp('\\b'+k+'\\b','i').test(nome)) { tagPag = 'Pagou ' + k; break; } }
                const todas = tagPag ? [tagPag,...extras] : extras;
                tagsPrev.style.display = 'block';
                tagsPrev.innerHTML = '<strong style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;">Tags que serão cadastradas:</strong><div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px;">' + todas.map(t=>`<span class="badge badge-indigo">${escHtml(t)}</span>`).join('') + '</div>';
            } else { tagsPrev.style.display = 'none'; }
        }
        function salvarNovoCurso() {
            const nome = (document.getElementById('nc-nome').value || '').trim(), desc = (document.getElementById('nc-descricao').value || '').trim();
            const tipo = document.getElementById('nc-tipo').value, status = document.getElementById('nc-status').value, taxa = document.getElementById('nc-taxa').value || '0';
            const tagsExtras = (document.getElementById('nc-tags').value || '').trim();
            if (!nome) { toast('⚠️ Informe o nome!', 'error'); return; }
            if (!desc) { toast('⚠️ Informe a descrição!', 'error'); return; }
            const btn = document.getElementById('nc-btn-salvar');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            api(`ajax_action=criar_curso&nome=${encodeURIComponent(nome)}&descricao=${encodeURIComponent(desc)}&tipo=${encodeURIComponent(tipo)}&status=${encodeURIComponent(status)}&valor_taxa=${encodeURIComponent(taxa)}&tags_curso=${encodeURIComponent(tagsExtras)}`)
                .then(d => {
                    btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Cadastrar';
                    if (d.sucesso) { toast(`✅ Curso "${nome}" cadastrado!`, 'success'); fecharModalNovoCurso(); atualizarStats(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }).catch(e => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Cadastrar'; toast('Erro: ' + e.message, 'error'); });
        }

        // ── EDITAR CURSO ──────────────────────────────────────────────────────────
        function abrirModalEditarCurso() {
            document.getElementById('ec-step1').style.display = 'block';
            document.getElementById('ec-step2').style.display = 'none';
            document.getElementById('ec-btn-salvar').disabled = true;
            document.getElementById('ec-btn-deletar').disabled = true;
            document.getElementById('modalEditarCurso').classList.remove('hidden');
            carregarListaCursos();
        }
        function fecharModalEditarCurso() { document.getElementById('modalEditarCurso').classList.add('hidden'); }
        function carregarListaCursos() {
            const lista = document.getElementById('ec-lista-cursos');
            lista.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);"><i class="fas fa-spinner fa-spin"></i></div>';
            api('ajax_action=listar_cursos').then(d => {
                if (!d.sucesso || !d.cursos.length) { lista.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);">Nenhum curso.</div>'; return; }
                lista.innerHTML = '';
                d.cursos.forEach(c => {
                    const div = document.createElement('div');
                    div.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:11px 13px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:all .15s;background:var(--light);gap:12px;';
                    div.innerHTML = `<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(c.nome)}</div><div style="font-size:11px;color:var(--muted);">${c.tipo_curso||'—'} · ${c.alunos_matriculados||0} aluno(s) · Taxa: ${fmtR(parseFloat(c.valor_taxa)||0)}</div></div><span class="badge ${c.status==='Aberto'?'badge-green':'badge-red'}">${escHtml(c.status)}</span>`;
                    div.addEventListener('mouseenter', () => { div.style.borderColor='#93c5fd'; div.style.background='#f0f7ff'; });
                    div.addEventListener('mouseleave', () => { div.style.borderColor='var(--border)'; div.style.background='var(--light)'; });
                    div.onclick = () => selecionarCursoEditar(c);
                    lista.appendChild(div);
                });
            });
        }
        let _ecTagsEditando = false;
        function selecionarCursoEditar(c) {
            document.getElementById('ec-id').value = c.id;
            document.getElementById('ec-nome').value = c.nome;
            document.getElementById('ec-descricao').value = c.descricao || '';
            document.getElementById('ec-tipo').value = c.tipo_curso || 'Formação';
            document.getElementById('ec-status').value = c.status || 'Aberto';
            document.getElementById('ec-taxa').value = parseFloat(c.valor_taxa) || 0;
            document.getElementById('ec-curso-nome-badge').textContent = c.nome;
            // Reset modo edição de tags
            _ecTagsEditando = false;
            document.getElementById('ec-tags-edicao-bloco').style.display = 'none';
            document.getElementById('ec-btn-editar-tags').style.display = 'flex';
            if (document.getElementById('ec-tags')) document.getElementById('ec-tags').value = '';
            // Mostrar tags atuais
            const tagsAtuais = (c.tags_curso || '').trim();
            const blocoAtual = document.getElementById('ec-tags-bloco-atual');
            const prevAtual = document.getElementById('ec-tags-preview-atual');
            if (tagsAtuais) {
                const arr = tagsAtuais.split(',').map(t=>t.trim()).filter(Boolean);
                prevAtual.innerHTML = arr.map(t=>`<span style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;">${escHtml(t)}</span>`).join('');
                blocoAtual.style.display = 'block';
            } else {
                prevAtual.innerHTML = '<span style="color:var(--muted);font-size:12px;">Nenhuma tag cadastrada.</span>';
                blocoAtual.style.display = 'block';
            }
            document.getElementById('ec-step1').style.display = 'none';
            document.getElementById('ec-step2').style.display = 'block';
            document.getElementById('ec-btn-salvar').disabled = false;
            document.getElementById('ec-btn-deletar').disabled = false;
        }
        function abrirConfirmacaoEditarTags() {
            const nome = document.getElementById('ec-nome').value || 'este curso';
            vpConfirm(
                'Editar Tags do Curso',
                `Tem certeza que deseja editar as tags de <strong>${escHtml(nome)}</strong>?<br><br>Todas as tags atribuídas a esse curso serão excluídas para adicionar as novas.`,
                '#d97706',
                '<i class="fas fa-tags"></i>',
                () => {
                    _ecTagsEditando = true;
                    document.getElementById('ec-tags-edicao-bloco').style.display = 'block';
                    document.getElementById('ec-btn-editar-tags').style.display = 'none';
                    setTimeout(() => document.getElementById('ec-tags').focus(), 100);
                }
            );
        }
        function atualizarPreviewTagsEditar() {
            const val = (document.getElementById('ec-tags').value || '').trim();
            const prev = document.getElementById('ec-tags-preview');
            if (!val) { prev.style.display = 'none'; return; }
            const tags = val.split(',').map(t => t.trim()).filter(Boolean);
            prev.style.display = 'flex';
            prev.innerHTML = tags.map(t=>`<span style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;">${t}</span>`).join('');
        }
        function voltarListaCursos() { _ecTagsEditando=false; document.getElementById('ec-step2').style.display='none'; document.getElementById('ec-step1').style.display='block'; document.getElementById('ec-btn-salvar').disabled=true; document.getElementById('ec-btn-deletar').disabled=true; document.getElementById('ec-tags-edicao-bloco').style.display='none'; document.getElementById('ec-btn-editar-tags').style.display='flex'; }
        function salvarEdicaoCurso() {
            const id=document.getElementById('ec-id').value, nome=(document.getElementById('ec-nome').value||'').trim();
            const desc=(document.getElementById('ec-descricao').value||'').trim(), tipo=document.getElementById('ec-tipo').value;
            const status=document.getElementById('ec-status').value, taxa=document.getElementById('ec-taxa').value||'0';
            if (!nome||!desc) { toast('⚠️ Preencha todos os campos!','error'); return; }
            const btn=document.getElementById('ec-btn-salvar'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Salvando...';
            // Só envia tags se o usuário abriu o modo de edição de tags
            const tagsEc = _ecTagsEditando ? (document.getElementById('ec-tags').value||'').trim() : '__MANTER__';
            let body = `ajax_action=editar_curso&id=${id}&nome=${encodeURIComponent(nome)}&descricao=${encodeURIComponent(desc)}&tipo=${encodeURIComponent(tipo)}&status=${encodeURIComponent(status)}&valor_taxa=${encodeURIComponent(taxa)}`;
            if (tagsEc !== '__MANTER__') body += `&tags_curso=${encodeURIComponent(tagsEc)}`;
            api(body)
                .then(d => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Salvar'; if (d.sucesso) { toast('✅ Curso atualizado!','success'); fecharModalEditarCurso(); atualizarStats(); } else toast('❌ '+(d.erro||'Falha'),'error'); });
        }
        function deletarCurso() {
            const id=document.getElementById('ec-id').value, nome=(document.getElementById('ec-nome').value||'').trim()||'este curso';
            if (!id||!confirm(`⚠️ Apagar "${nome}"? Irreversível.`)) return;
            const btn=document.getElementById('ec-btn-deletar'); btn.disabled=true;
            api(`ajax_action=deletar_curso&id=${id}`).then(d => { btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Apagar'; if (d.sucesso) { toast('🗑️ Curso apagado!','success'); fecharModalEditarCurso(); atualizarStats(); } else toast('❌ '+(d.erro||'Falha'),'error'); });
        }

        // ── ELIMINAR CURSO ────────────────────────────────────────────────────────
        let elimId = null, elimNome = null;
        function abrirModalEliminarCurso() { elimId=null; elimNome=null; document.getElementById('elim-confirmacao').style.display='none'; document.getElementById('elim-btn-confirmar').disabled=true; document.getElementById('modalEliminarCurso').classList.remove('hidden'); carregarCursosEliminar(); }
        function fecharModalEliminarCurso() { document.getElementById('modalEliminarCurso').classList.add('hidden'); }
        function carregarCursosEliminar() {
            const lista=document.getElementById('elim-lista-cursos');
            lista.innerHTML='<div style="text-align:center;padding:24px;color:var(--muted);"><i class="fas fa-spinner fa-spin"></i></div>';
            api('ajax_action=listar_cursos').then(d => {
                const abertos=(d.cursos||[]).filter(c=>c.status==='Aberto');
                if (!abertos.length) { lista.innerHTML='<div style="text-align:center;padding:24px;color:var(--muted);">Nenhum curso aberto.</div>'; return; }
                lista.innerHTML='';
                abertos.forEach(c => {
                    const div=document.createElement('div');
                    div.style.cssText='display:flex;align-items:center;justify-content:space-between;padding:11px 13px;border:1.5px solid #fecaca;border-radius:8px;cursor:pointer;transition:all .15s;background:#fff5f5;gap:12px;';
                    div.innerHTML=`<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:700;color:var(--text);">${escHtml(c.nome)}</div><div style="font-size:11px;color:var(--muted);">${c.alunos_matriculados||0} matriculado(s)</div></div><span class="badge badge-green">Aberto</span>`;
                    div.addEventListener('mouseenter',()=>{div.style.borderColor='#f87171';div.style.background='#fee2e2';});
                    div.addEventListener('mouseleave',()=>{div.style.borderColor='#fecaca';div.style.background='#fff5f5';});
                    div.onclick=()=>selecionarEliminar(c.id,c.nome);
                    lista.appendChild(div);
                });
            });
        }
        function selecionarEliminar(id, nome) { elimId=id; elimNome=nome; document.getElementById('elim-preview-nome').textContent=nome; document.getElementById('elim-confirmacao-input').value=''; document.getElementById('elim-confirmacao-hint').textContent=`Digite: "${nome}"`; document.getElementById('elim-confirmacao').style.display='block'; document.getElementById('elim-btn-confirmar').disabled=true; }
        function verificarConfirmacaoEliminar() { const v=document.getElementById('elim-confirmacao-input').value.trim(), h=document.getElementById('elim-confirmacao-hint'), btn=document.getElementById('elim-btn-confirmar'); if (v===elimNome) { h.innerHTML='<span style="color:#16a34a;font-weight:700;"><i class="fas fa-check"></i> Correto!</span>'; btn.disabled=false; btn.style.opacity='1'; } else { h.innerHTML=`<span style="color:#dc2626;">Digite: "<strong>${escHtml(elimNome)}</strong>"</span>`; btn.disabled=true; btn.style.opacity='.5'; } }
        function confirmarEliminarCurso() {
            if (!elimId||!elimNome) { toast('Selecione um curso','error'); return; }
            const btn=document.getElementById('elim-btn-confirmar'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Eliminando...';
            api(`ajax_action=eliminar_curso&id=${elimId}&nome=${encodeURIComponent(elimNome)}`).then(d => { btn.disabled=false; btn.innerHTML='<i class="fas fa-user-minus"></i> Confirmar'; if (d.sucesso) { toast(`✅ Eliminação concluída! Removidos: ${d.removidos}`,'success'); fecharModalEliminarCurso(); atualizarStats(); } else toast('❌ '+(d.erro||'Falha'),'error'); });
        }

        // ── VALIDAR PAGAMENTOS ─────────────────────────────────────────────────────
        let _pagPollingTimer = null;
        let _vpLightboxAberto = false;
        let _vpBuscaTimer = null;

        function abrirModalValidarPagamentos() {
            document.getElementById('modalValidarPagamentos').classList.remove('hidden');
            carregarPendentes();
            if (_pagPollingTimer) clearInterval(_pagPollingTimer);
            // Polling a cada 15s (era 5s) — e pausa quando lightbox está aberto
            _pagPollingTimer = setInterval(() => { if (!_vpLightboxAberto) carregarPendentes(true); }, 15000);
        }
        function fecharModalValidarPagamentos() {
            document.getElementById('modalValidarPagamentos').classList.add('hidden');
            if (_pagPollingTimer) { clearInterval(_pagPollingTimer); _pagPollingTimer = null; }
            fecharLightbox();
        }

        // Debounce na busca — espera 400ms após digitar
        function vpBuscaDebounce() {
            if (_vpBuscaTimer) clearTimeout(_vpBuscaTimer);
            _vpBuscaTimer = setTimeout(() => carregarPendentes(), 400);
        }
        function vpLimparBusca() {
            const el = document.getElementById('vp-busca');
            if (el) el.value = '';
            carregarPendentes();
        }

        function carregarPendentes(silencioso = false) {
            const lista = document.getElementById('vp-lista');
            const busca = (document.getElementById('vp-busca')?.value || '').trim();
            if (!silencioso) lista.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
            const params = `ajax_action=listar_pagamentos&status=pendente&busca=${encodeURIComponent(busca)}`;
            api(params).then(d => {
                const total = d.pagamentos?.length || 0;
                const badge = document.getElementById('vp-badge-count');
                badge.textContent = total>0 ? total : ''; badge.style.display = total>0 ? 'inline-flex' : 'none';
                document.getElementById('vp-total-label').textContent = total>0 ? `${total} pendente(s)${busca?' (filtrado)':''}` : (busca?'Nenhum resultado':'Nenhum pendente');
                if (!total) { lista.innerHTML='<div style="text-align:center;padding:48px;color:var(--muted);"><i class="fas fa-check-double" style="font-size:2.5rem;color:#16a34a;display:block;margin-bottom:12px;"></i><div style="font-size:14px;font-weight:700;color:#15803d;">'+(busca?'Nenhum resultado para a busca.':'Tudo em dia!')+'</div></div>'; return; }
                lista.innerHTML = '';
                d.pagamentos.forEach(p => {
                    const bClass = p.curso.includes('CFSD')?'badge-green':p.curso.includes('CFO')?'badge-indigo':p.curso.includes('CFC')?'badge-yellow':'badge-gray';
                    const dt = new Date(p.data_envio).toLocaleString('pt-BR');
                    const row = document.createElement('div');
                    row.id = `vp-row-${p.id}`;
                    row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:13px 20px;border-bottom:1px solid var(--border);transition:background .15s;flex-wrap:wrap;';
                    row.innerHTML = `<div style="flex:1;min-width:200px;"><div style="display:flex;align-items:center;gap:7px;margin-bottom:4px;flex-wrap:wrap;"><span style="font-size:13px;font-weight:700;">${escHtml(p.nome)}</span><span class="badge ${bClass}">${escHtml(p.curso)}</span></div><div style="font-size:11px;color:var(--muted);display:flex;gap:12px;flex-wrap:wrap;"><span><i class="fas fa-id-badge" style="margin-right:3px;"></i>${escHtml(p.rgpm||'—')}</span><span><i class="fab fa-discord" style="margin-right:3px;"></i>${escHtml(p.discord||'—')}</span><span><i class="fas fa-clock" style="margin-right:3px;"></i>${dt}</span></div></div><div style="display:flex;gap:7px;flex-shrink:0;flex-wrap:wrap;"><button class="vp-ver" data-id="${p.id}" style="background:#dbeafe;color:#1a56db;border:1px solid #bfdbfe;border-radius:7px;padding:6px 11px;font-size:11px;font-weight:700;cursor:pointer;"><i class="fas fa-image"></i> Ver</button><button class="vp-validar" data-id="${p.id}" data-nome="${escHtml(p.nome)}" data-curso="${escHtml(p.curso)}" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;border-radius:7px;padding:6px 11px;font-size:11px;font-weight:700;cursor:pointer;"><i class="fas fa-check"></i> Validar</button><button class="vp-rejeitar" data-id="${p.id}" data-nome="${escHtml(p.nome)}" data-curso="${escHtml(p.curso)}" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:7px;padding:6px 11px;font-size:11px;font-weight:700;cursor:pointer;"><i class="fas fa-times"></i> Rejeitar</button></div>`;
                    row.addEventListener('mouseenter', () => row.style.background='var(--light)');
                    row.addEventListener('mouseleave', () => row.style.background='');
                    row.querySelector('.vp-ver').addEventListener('click', () => verComprovante(p.id, p.nome, p.rgpm));
                    row.querySelector('.vp-validar').addEventListener('click', () => validarPag(p.id, p.nome, p.curso));
                    row.querySelector('.vp-rejeitar').addEventListener('click', () => rejeitarPag(p.id, p.nome, p.curso));
                    lista.appendChild(row);
                });
            });
        }

        // Fechar lightbox com ESC (função definida no bloco do lightbox melhorado acima)
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && _vpLightboxAberto) fecharLightbox(); });

        // ── vpConfirm — FIX: usa display:flex direto, z-index 99999 ──────────────
        let _vpCb = null;
        function vpConfirm(titulo, msg, cor, icone, cb) {
            document.getElementById('vp-confirm-titulo').textContent = titulo;
            document.getElementById('vp-confirm-msg').innerHTML = msg;
            const btn = document.getElementById('vp-confirm-ok');
            btn.style.background = cor;
            btn.innerHTML = icone + ' Confirmar';
            _vpCb = cb;
            // FIX: força reflow antes de exibir para garantir que o browser renderize
            const modal = document.getElementById('vp-confirm-modal');
            modal.style.display = 'none';
            void modal.offsetHeight; // força reflow
            modal.style.display = 'flex';
        }
        function fecharVpConfirm(exec) {
            document.getElementById('vp-confirm-modal').style.display = 'none';
            if (exec && _vpCb) _vpCb();
            _vpCb = null;
        }

        async function validarPag(id, nome, curso) {
            vpConfirm('Validar Pagamento', `Confirmar pagamento de <strong>${escHtml(nome)}</strong> para <strong>${escHtml(curso)}</strong>?`, '#16a34a', '<i class="fas fa-check"></i>', async () => {
                const d = await api(`ajax_action=validar_pagamento&id=${id}`);
                if (d.sucesso) {
                    const row = document.getElementById(`vp-row-${id}`);
                    if (row) { row.style.opacity='0'; row.style.transition='opacity .3s'; setTimeout(()=>row.remove(),300); }
                    const badge = document.getElementById('vp-badge-count');
                    const novo = Math.max(0,(parseInt(badge.textContent)||0)-1);
                    badge.textContent = novo>0?novo:''; badge.style.display=novo>0?'inline-flex':'none';
                    document.getElementById('vp-total-label').textContent = novo>0?`${novo} pendente(s)`:'Nenhum pendente';
                    document.getElementById('vp-sucesso-nome').textContent = nome;
                    document.getElementById('vp-sucesso-curso').textContent = curso;
                    document.getElementById('vp-sucesso-modal').style.display = 'flex';
                    if (novo===0) setTimeout(()=>carregarPendentes(),350);
                    atualizarStats();
                } else toast('❌ '+(d.erro||'Falha'),'error');
            });
        }

        async function rejeitarPag(id, nome, curso) {
            // Remove modal anterior se existir
            const ant = document.getElementById('modal-rejeitar');
            if (ant) ant.remove();

            const modal = document.createElement('div');
            modal.id = 'modal-rejeitar';
            modal.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);';
            modal.innerHTML = `
              <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.25);margin:16px;animation:fadeUp .2s ease;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                  <div style="width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:17px;flex-shrink:0;">
                    <i class="fas fa-times-circle"></i>
                  </div>
                  <div>
                    <div style="font-size:15px;font-weight:800;color:#1e293b;">Rejeitar Pagamento</div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;">Comprovante de <strong>${escHtml(nome)}</strong> — ${escHtml(curso)}</div>
                  </div>
                </div>
                <label style="display:block;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:6px;">
                  Motivo da Rejeição <span style="color:#dc2626;">*</span>
                </label>
                <textarea id="motivo-rej-input" placeholder="Ex: Comprovante ilegível, valor incorreto, documento inválido..." rows="3"
                  style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;color:#1e293b;resize:vertical;outline:none;line-height:1.5;"
                  onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#e2e8f0'"></textarea>
                <div id="motivo-rej-erro" style="font-size:11px;color:#dc2626;margin-top:4px;display:none;">
                  <i class="fas fa-exclamation-circle"></i> Informe o motivo antes de continuar.
                </div>
                <div style="display:flex;gap:10px;margin-top:20px;">
                  <button id="btn-rej-cancelar"
                    style="flex:1;padding:10px 0;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;">
                    Cancelar
                  </button>
                  <button id="btn-rej-confirmar"
                    style="flex:1;padding:10px 0;background:#dc2626;color:#fff;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;">
                    <i class="fas fa-times-circle"></i> Rejeitar
                  </button>
                </div>
              </div>`;

            document.body.appendChild(modal);
            document.getElementById('motivo-rej-input').focus();

            modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
            document.getElementById('btn-rej-cancelar').addEventListener('click', () => modal.remove());

            document.getElementById('btn-rej-confirmar').addEventListener('click', async function() {
                const motivo = (document.getElementById('motivo-rej-input').value || '').trim();
                const erroEl = document.getElementById('motivo-rej-erro');
                if (!motivo) { erroEl.style.display = 'block'; return; }
                erroEl.style.display = 'none';
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejeitando...';

                const d = await api(`ajax_action=rejeitar_pagamento&id=${id}&motivo=${encodeURIComponent(motivo)}`);
                modal.remove();
                if (d.sucesso) {
                    const row = document.getElementById(`vp-row-${id}`);
                    if (row) { row.style.opacity='0'; row.style.transition='opacity .3s'; setTimeout(()=>row.remove(),300); }
                    const badge = document.getElementById('vp-badge-count');
                    const novo = Math.max(0,(parseInt(badge.textContent)||0)-1);
                    badge.textContent=novo>0?novo:''; badge.style.display=novo>0?'inline-flex':'none';
                    document.getElementById('vp-total-label').textContent=novo>0?`${novo} pendente(s)`:'Nenhum pendente';
                    if (novo===0) setTimeout(()=>carregarPendentes(),350);
                    atualizarStats();
                    toast('✅ Pagamento rejeitado com motivo registrado.', 'success');
                } else toast('❌ '+(d.erro||'Falha'),'error');
            });
        }

        function fecharSucessoValidacao() { document.getElementById('vp-sucesso-modal').style.display='none'; }

        // ── EDITAR USUÁRIO ────────────────────────────────────────────────────────
        function abrirModalEditarUsuario() {
            document.getElementById('eu-busca').value='';
            const res=document.getElementById('eu-resultados'); res.style.display='none'; res.innerHTML='';
            document.getElementById('eu-form').style.display='none';
            document.getElementById('eu-btn-salvar').disabled=true;
            document.getElementById('eu-btn-deletar').disabled=true;
            document.getElementById('modalEditarUsuario').classList.remove('hidden');
        }
        function fecharModalEditarUsuario() { document.getElementById('modalEditarUsuario').classList.add('hidden'); }
        function buscarUsuarioEditar() {
            const termo=(document.getElementById('eu-busca').value||'').trim();
            if (!termo) { toast('Digite RGPM, Discord ou Nome','error'); return; }
            const res=document.getElementById('eu-resultados');
            res.style.display='flex'; res.innerHTML='<div style="color:var(--muted);font-size:13px;padding:8px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            api(`ajax_action=buscar_usuario&termo=${encodeURIComponent(termo)}`).then(d => {
                if (!d.sucesso||!d.usuarios.length) { res.innerHTML='<div style="color:var(--muted);font-size:13px;padding:8px;">Nenhum usuário encontrado.</div>'; return; }
                res.innerHTML='';
                d.usuarios.forEach(u => {
                    const div=document.createElement('div');
                    div.style.cssText='display:flex;align-items:center;justify-content:space-between;padding:10px 13px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:all .15s;background:var(--light);';
                    div.innerHTML=`<div><div style="font-size:13px;font-weight:700;">${escHtml(u.nome)}</div><div style="font-size:11px;color:var(--muted);">RGPM: ${escHtml(u.rgpm||'—')} · Discord: ${escHtml(u.discord||'—')} · Nível: ${escHtml(u.nivel||'—')}</div></div><span class="badge badge-blue">${escHtml(u.status||'—')}</span>`;
                    div.addEventListener('mouseenter',()=>{div.style.borderColor='#93c5fd';div.style.background='#f0f7ff';});
                    div.addEventListener('mouseleave',()=>{div.style.borderColor='var(--border)';div.style.background='var(--light)';});
                    div.onclick=()=>selecionarUsuarioEditar(u);
                    res.appendChild(div);
                });
            });
        }
        function selecionarUsuarioEditar(u) {
            document.getElementById('eu-id').value=u.id;
            document.getElementById('eu-nome-badge').textContent=`Editando: ${u.nome}`;
            document.getElementById('eu-nome').value=u.nome||'';
            document.getElementById('eu-rgpm').value=u.rgpm||'';
            document.getElementById('eu-discord').value=u.discord||'';
            document.getElementById('eu-nivel').value='';
            document.getElementById('eu-status').value='';
            document.getElementById('eu-cursos').value=u.meus_cursos||'';
            document.getElementById('eu-resultados').style.display='none';
            document.getElementById('eu-form').style.display='block';
            document.getElementById('eu-btn-salvar').disabled=false;
            document.getElementById('eu-btn-deletar').disabled=false;
        }
        function atualizarHeaderSessao(d) {
            if (!d.session_atualizada) return;
            const chipName=document.querySelector('.user-chip .chip-name'); if (chipName&&d.novo_nome) chipName.textContent=d.novo_nome;
            const avatar=document.querySelector('.user-chip .avatar'); if (avatar&&d.novo_nome) avatar.textContent=d.novo_nome.charAt(0).toUpperCase();
            document.querySelectorAll('.info-cell').forEach(cell => { const lbl=cell.querySelector('.ic-label')?.textContent?.trim(), val=cell.querySelector('.ic-value'); if (!val) return; if (lbl==='Nome'&&d.novo_nome) val.textContent=d.novo_nome; if (lbl==='RGPM'&&d.novo_rgpm) val.textContent=d.novo_rgpm; if (lbl==='Nível'&&d.novo_nivel) val.textContent=d.novo_nivel; });
            const welcome=document.querySelector('#dashboard > div:first-child > div:first-child'); if (welcome&&d.novo_nome) welcome.textContent='Bem-vindo, '+d.novo_nome;
        }
        function salvarEdicaoUsuario() {
            const id=document.getElementById('eu-id').value; if (!id) return;
            const params=new URLSearchParams({ ajax_action:'editar_usuario', id });
            [{k:'nome',el:'eu-nome'},{k:'rgpm',el:'eu-rgpm'},{k:'discord',el:'eu-discord'},{k:'nivel',el:'eu-nivel'},{k:'status',el:'eu-status'},{k:'meus_cursos',el:'eu-cursos'}]
                .forEach(({k,el}) => { const v=document.getElementById(el).value; if (v!=='') params.append(k,v); });
            params.append('csrf_token',CSRF_TOKEN);
            const btn=document.getElementById('eu-btn-salvar'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Salvando...';
            fetch('painel_admin.php',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:params.toString() })
                .then(r=>r.json()).then(d => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Salvar'; if (d.sucesso) { toast('✅ Usuário atualizado!','success'); fecharModalEditarUsuario(); atualizarHeaderSessao(d); atualizarStats(); } else toast('❌ '+(d.erro||'Falha'),'error'); });
        }
        // ── ARQUIVO: estado temporário do usuário a excluir ────────────────
        let _arqPendente = null; // { id, nome }

        function deletarUsuarioConfirmar() {
            const id   = document.getElementById('eu-id').value;
            const nome = document.getElementById('eu-nome').value || 'este usuário';
            if (!id) return;
            fecharModalEditarUsuario();
            // Abre modal de arquivo PRIMEIRO, antes de qualquer exclusão
            _arqPendente = { id, nome };
            document.getElementById('arq-nome-sub').textContent = 'Conta: ' + nome;
            document.getElementById('arq-motivo').value = '';
            document.getElementById('arq-contagem').style.display = 'none';
            document.getElementById('arq-btn-sim').disabled = false;
            document.getElementById('arq-btn-sim').innerHTML = '<i class="fas fa-archive"></i> Sim, salvar e excluir';
            document.getElementById('modalArquivoPag').style.display = 'flex';
        }

        async function arqSim() {
            if (!_arqPendente) return;
            const btn = document.getElementById('arq-btn-sim');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            const motivo = document.getElementById('arq-motivo').value.trim();
            const d = await api('ajax_action=arquivar_pagamentos&id=' + _arqPendente.id + '&motivo_exclusao=' + encodeURIComponent(motivo));
            if (d.sucesso) {
                const n = d.arquivados || 0;
                document.getElementById('arq-contagem-txt').textContent = n + ' registro(s) arquivado(s) com sucesso!';
                document.getElementById('arq-contagem').style.display = 'block';
                btn.innerHTML = '<i class="fas fa-check"></i> Arquivado!';
                setTimeout(() => { arqProsseguirExclusao(); }, 900);
            } else {
                toast('❌ Erro ao arquivar: ' + (d.erro || 'falha'), 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-archive"></i> Sim, salvar e excluir';
            }
        }

        function arqNao() {
            document.getElementById('modalArquivoPag').style.display = 'none';
            arqProsseguirExclusao();
        }

        async function arqProsseguirExclusao() {
            if (!_arqPendente) return;
            document.getElementById('modalArquivoPag').style.display = 'none';
            const { id, nome } = _arqPendente;
            _arqPendente = null;
            const d = await api('ajax_action=deletar_usuario&id=' + id);
            if (d.sucesso) {
                if (typeof mostrarModalBlPosExclusao === 'function') {
                    mostrarModalBlPosExclusao(d.usuario || { nome });
                } else {
                    mostrarSucessoExclusao(nome);
                    // Aguarda para garantir que o arquivo já foi salvo no banco
                    setTimeout(() => { atualizarStats(); }, 1500);
                }
            } else {
                toast('❌ ' + (d.erro || 'Falha ao excluir'), 'error');
            }
        }

        // ── MODAL VER ARQUIVO ────────────────────────────────────────────────
        function abrirModalVerArquivo() {
            document.getElementById('arq-busca').value = '';
            document.getElementById('arq-filtro-tipo').value = '';
            document.getElementById('arq-lista').innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-archive" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Clique em buscar</div>';
            document.getElementById('modalVerArquivo').style.display = 'flex';
            carregarArquivo();
        }

        function fecharModalVerArquivo() {
            document.getElementById('modalVerArquivo').style.display = 'none';
        }

        async function carregarArquivo() {
            const busca = document.getElementById('arq-busca').value.trim();
            const tipo  = document.getElementById('arq-filtro-tipo').value;
            document.getElementById('arq-lista').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            const d = await api('ajax_action=listar_arquivo&busca=' + encodeURIComponent(busca) + '&tipo=' + encodeURIComponent(tipo));
            if (!d.sucesso) { document.getElementById('arq-lista').innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626;">Erro ao carregar</div>'; return; }
            const lista = d.registros || [];
            if (!lista.length) {
                document.getElementById('arq-lista').innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-folder-open" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Nenhum registro encontrado</div>';
                return;
            }
            const fmtVal = v => parseFloat(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
            const fmtDt  = s => s ? new Date(s.replace(' ','T')).toLocaleDateString('pt-BR') : '—';
            const rows = lista.map(r => {
                const tipoCls  = r.tipo === 'multa' ? 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;' : 'background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;';
                const tipoLabel = r.tipo === 'multa' ? 'Multa' : 'Inscricao';
                const btnComp = r.comprovante ? '' : 'display:none;';
                return `<div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;background:#fff;margin-bottom:8px;">
                    <span style="padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;flex-shrink:0;${tipoCls}">${tipoLabel}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:700;color:#1e293b;">${escHtml(r.nome)} <span style="color:#64748b;font-weight:400;font-size:12px;">· RGPM ${escHtml(r.rgpm)}</span></div>
                        <div style="font-size:11px;color:#64748b;margin-top:2px;">${escHtml(r.curso||'—')} · Arquivado em ${fmtDt(r.data_arquivado)}</div>
                        ${r.motivo_exclusao ? `<div style="font-size:11px;color:#92400e;margin-top:2px;"><i class="fas fa-comment-alt" style="margin-right:4px;"></i>${escHtml(r.motivo_exclusao)}</div>` : ''}
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:15px;font-weight:800;color:#1e293b;">${fmtVal(r.valor)}</div>
                        <button onclick="verCompArquivo(${r.id})" style="${btnComp}margin-top:5px;padding:5px 10px;background:#eff6ff;color:#1a56db;border:1px solid #bfdbfe;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;"><i class="fas fa-image"></i> Ver</button>
                    </div>
                </div>`;
            }).join('');
            document.getElementById('arq-lista').innerHTML = `<div style="font-size:12px;color:#64748b;margin-bottom:10px;">${lista.length} registro(s)</div>` + rows;
        }

        async function verCompArquivo(id) {
            const modal = document.getElementById('modalCompArquivo');
            const img   = document.getElementById('arq-comp-img');
            img.src = '';
            modal.style.display = 'flex';
            img.alt = 'Carregando...';
            const d = await api('ajax_action=ver_comprovante_arquivo&id=' + id);
            if (d.sucesso) { img.src = d.imagem; img.alt = 'Comprovante'; }
            else { img.alt = 'Erro ao carregar comprovante'; modal.style.display = 'none'; toast('❌ Comprovante nao encontrado', 'error'); }
        }
        function mostrarSucessoExclusao(nome) {
            // Remove modal anterior se existir
            const ant = document.getElementById('modal-exclusao-sucesso');
            if (ant) ant.remove();
            const modal = document.createElement('div');
            modal.id = 'modal-exclusao-sucesso';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(13,27,46,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
            modal.innerHTML = `
                <div style="background:#fff;border-radius:16px;max-width:380px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.25);overflow:hidden;text-align:center;">
                    <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:28px 24px 22px;">
                        <div style="width:60px;height:60px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                            <i class="fas fa-user-slash" style="color:white;font-size:26px;"></i>
                        </div>
                        <div style="font-size:17px;font-weight:800;color:white;margin-bottom:5px;">Conta Excluída!</div>
                        <div style="font-size:12px;color:rgba(255,255,255,0.75);">O usuário foi removido do sistema</div>
                    </div>
                    <div style="padding:20px 22px;">
                        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:13px;margin-bottom:16px;">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#dc2626;margin-bottom:5px;">Usuário Excluído</div>
                            <div style="font-size:15px;font-weight:800;color:#1e293b;">${escHtml(nome)}</div>
                            <div style="font-size:12px;color:#64748b;margin-top:3px;">Conta excluída permanentemente</div>
                        </div>
                        <button onclick="document.getElementById('modal-exclusao-sucesso').remove()" style="width:100%;padding:11px;background:#dc2626;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;color:white;cursor:pointer;">
                            <i class="fas fa-check"></i> Fechar
                        </button>
                    </div>
                </div>`;
            // Fecha ao clicar fora
            modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
            document.body.appendChild(modal);
        }

        // ── TAGS ──────────────────────────────────────────────────────────────────
        let tagsSelecionadas = new Set(), tagsRemocao = new Set();
        let _tagAlunosSelecionados = new Map(); // id -> {id, nome, rgpm, discord}
        let _remAlunosSelecionados = new Map();
        let _tagDebounce = null, _remDebounce = null;

        function popularCursos() {
            const nomes = Object.keys(tagsPorCurso);
            [document.getElementById('tag_curso'), document.getElementById('rem_curso')].forEach(sel => {
                if (!sel) return;
                while (sel.options.length>1) sel.remove(1);
                nomes.forEach(n => sel.add(new Option(n,n)));
                if (!nomes.length) { const op=new Option('Nenhum curso aberto',''); op.disabled=true; sel.add(op); }
            });
        }
        function abrirModalSetarTags() { popularCursos(); document.getElementById('tag_curso').value=''; tagsSelecionadas=new Set(); _tagAlunosSelecionados=new Map(); ['bloco_tags','bloco_rgpms','bloco_preview'].forEach(id=>document.getElementById(id).style.display='none'); document.getElementById('tag_busca_aluno').value=''; document.getElementById('tag_resultados_busca').style.display='none'; document.getElementById('tag_alunos_selecionados').innerHTML=''; document.getElementById('modalSetarTags').classList.remove('hidden'); }
        function fecharModalSetarTags() { document.getElementById('modalSetarTags').classList.add('hidden'); }
        function abrirModalRemoverTags() { popularCursos(); document.getElementById('rem_curso').value=''; tagsRemocao=new Set(); _remAlunosSelecionados=new Map(); ['rem_bloco_tags','rem_bloco_rgpms','rem_bloco_preview'].forEach(id=>document.getElementById(id).style.display='none'); document.getElementById('rem_busca_aluno').value=''; document.getElementById('rem_resultados_busca').style.display='none'; document.getElementById('rem_alunos_selecionados').innerHTML=''; document.getElementById('modalRemoverTags').classList.remove('hidden'); }
        function fecharModalRemoverTags() { document.getElementById('modalRemoverTags').classList.add('hidden'); }

        function carregarTagsCurso() {
            const c=document.getElementById('tag_curso').value; tagsSelecionadas=new Set();
            document.getElementById('bloco_rgpms').style.display='none'; document.getElementById('bloco_preview').style.display='none';
            if (!c||!tagsPorCurso[c]) { document.getElementById('bloco_tags').style.display='none'; return; }
            const lt=document.getElementById('lista_tags'); lt.innerHTML='';
            tagsPorCurso[c].forEach(tag => {
                const b=document.createElement('button'); b.type='button'; b.className='tag-pill';
                b.innerHTML=`<i class="fas fa-tag" style="font-size:10px;color:var(--blue);flex-shrink:0;"></i><span>${escHtml(tag)}</span><i class="fas fa-check" style="margin-left:auto;font-size:10px;display:none;color:var(--blue);"></i>`;
                b.onclick=()=>{ if (tagsSelecionadas.has(tag)) { tagsSelecionadas.delete(tag); b.classList.remove('selected'); b.querySelector('.fa-check').style.display='none'; } else { tagsSelecionadas.add(tag); b.classList.add('selected'); b.querySelector('.fa-check').style.display='inline'; } document.getElementById('bloco_rgpms').style.display=tagsSelecionadas.size>0?'block':'none'; atualizarPrevTag(); };
                lt.appendChild(b);
            });
            document.getElementById('bloco_tags').style.display='block';
        }

        function debounceTagBusca() { clearTimeout(_tagDebounce); _tagDebounce = setTimeout(buscarAlunoTag, 400); }
        let _multaDebounce;
        function debounceMultaBusca() { clearTimeout(_multaDebounce); _multaDebounce = setTimeout(buscarAlunoMulta, 400); }
        async function buscarAlunoTag() {
            const termo = document.getElementById('tag_busca_aluno').value.trim();
            if (!termo) return;
            const res = document.getElementById('tag_resultados_busca');
            res.style.display='block'; res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            const d = await api(`ajax_action=buscar_aluno_nivel3&termo=${encodeURIComponent(termo)}`);
            if (!d.sucesso || !d.usuarios.length) { res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;">Nenhum aluno encontrado.</div>'; return; }
            res.innerHTML = d.usuarios.map(u => `<div class="busca-result-item" data-id="${u.id}" data-nome="${escHtml(u.nome)}" data-rgpm="${escHtml(u.rgpm)}" data-discord="${escHtml(u.discord||'')}"><strong>${escHtml(u.nome)}</strong> <span style="color:var(--muted);font-size:11px;">RGPM: ${escHtml(u.rgpm)} | Discord: ${escHtml(u.discord||'—')}</span></div>`).join('');
            res.querySelectorAll('.busca-result-item').forEach(el => el.addEventListener('click', () => adicionarAlunoTag(el)));
        }
        function adicionarAlunoTag(el) {
            const id=el.dataset.id, nome=el.dataset.nome, rgpm=el.dataset.rgpm, discord=el.dataset.discord;
            if (_tagAlunosSelecionados.has(id)) return;
            _tagAlunosSelecionados.set(id, {id,nome,rgpm,discord});
            renderChipsTag(); atualizarPrevTag();
            document.getElementById('tag_resultados_busca').style.display='none';
            document.getElementById('tag_busca_aluno').value='';
        }
        function renderChipsTag() {
            const wrap = document.getElementById('tag_alunos_selecionados');
            wrap.innerHTML = [..._tagAlunosSelecionados.values()].map(u => `<span style="display:inline-flex;align-items:center;gap:5px;background:#dbeafe;border:1px solid #bfdbfe;color:#1e40af;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;"><i class="fas fa-user" style="font-size:9px;"></i>${escHtml(u.nome)} <button onclick="removerAlunoTag('${u.id}')" style="background:none;border:none;cursor:pointer;color:#1e40af;padding:0;font-size:11px;margin-left:2px;">✕</button></span>`).join('');
        }
        function removerAlunoTag(id) { _tagAlunosSelecionados.delete(id); renderChipsTag(); atualizarPrevTag(); }
        function atualizarPrevTag() {
            const c=document.getElementById('tag_curso').value, p=document.getElementById('bloco_preview');
            if (c&&tagsSelecionadas.size>0&&_tagAlunosSelecionados.size>0) { document.getElementById('prev_curso').textContent=c; const w=document.getElementById('prev_tags_wrap'); w.innerHTML=''; tagsSelecionadas.forEach(t=>{const sp=document.createElement('span');sp.className='badge badge-indigo';sp.textContent=t;w.appendChild(sp);}); document.getElementById('prev_total').textContent=_tagAlunosSelecionados.size+' usuário(s)'; p.style.display='block'; } else p.style.display='none';
        }
        async function executarSetarTags() {
            const c=document.getElementById('tag_curso').value;
            const ids=[..._tagAlunosSelecionados.keys()];
            if (!c||tagsSelecionadas.size===0||!ids.length) { toast('Preencha todos os campos!','error'); return; }
            const btn=document.querySelector('#modalSetarTags .btn-primary'); btn.disabled=true;
            const arr=[...tagsSelecionadas]; let atualiz=0, naoEnc=[];
            // Usa ID interno do banco — mais confiável que RGPM/Discord
            const idsDb   = [..._tagAlunosSelecionados.keys()];
            const idsRgpm = [..._tagAlunosSelecionados.values()].map(u => u.rgpm||u.discord);
            console.log('Enviando IDs internos para setar_tags:', idsDb);
            for (let i=0;i<arr.length;i++) {
                btn.innerHTML=`<i class="fas fa-spinner fa-spin"></i> Tag ${i+1}/${arr.length}...`;
                try {
                    const d=await api(`ajax_action=setar_tags&curso=${encodeURIComponent(c)}&tag=${encodeURIComponent(arr[i])}&ids=${encodeURIComponent(idsDb.join(','))}&usar_id=1`);
                    console.log('setar_tags resposta:', JSON.stringify(d));
                    if (d.sucesso) { atualiz=Math.max(atualiz,d.atualizados||0); if (d.lista_nao_encontrados?.length) naoEnc=[...new Set([...naoEnc,...d.lista_nao_encontrados])]; }
                    else { console.error('setar_tags erro:', d.erro); }
                } catch(e) { console.error('setar_tags exception:', e); }
            }
            btn.disabled=false; btn.innerHTML='<i class="fas fa-bolt"></i> Executar';
            if (atualiz === 0) {
                toast(`❌ Nenhum usuário atualizado! RGPMs: ${idsRgpm.join(', ')}`, 'error');
            } else {
                let msg=`✅ ${arr.length} tag(s) setadas em ${atualiz} usuário(s).`; if (naoEnc.length) msg+=` ⚠️ Não encontrados: ${naoEnc.join(', ')}`;
                toast(msg,'success'); fecharModalSetarTags(); atualizarStats();
            }
        }

        function carregarTagsRemocao() {
            const c=document.getElementById('rem_curso').value; tagsRemocao=new Set();
            document.getElementById('rem_bloco_rgpms').style.display='none'; document.getElementById('rem_bloco_preview').style.display='none';
            if (!c||!tagsPorCurso[c]) { document.getElementById('rem_bloco_tags').style.display='none'; return; }
            const lt=document.getElementById('rem_lista_tags'); lt.innerHTML='';
            tagsPorCurso[c].forEach(tag => {
                const b=document.createElement('button'); b.type='button'; b.className='tag-pill';
                b.innerHTML=`<i class="fas fa-tag" style="font-size:10px;color:var(--red);flex-shrink:0;"></i><span>${escHtml(tag)}</span><i class="fas fa-check" style="margin-left:auto;font-size:10px;display:none;color:var(--red);"></i>`;
                b.onclick=()=>{ if (tagsRemocao.has(tag)) { tagsRemocao.delete(tag); b.classList.remove('selected'); b.querySelector('.fa-check').style.display='none'; } else { tagsRemocao.add(tag); b.classList.add('selected'); b.querySelector('.fa-check').style.display='inline'; } document.getElementById('rem_bloco_rgpms').style.display=tagsRemocao.size>0?'block':'none'; atualizarPrevRem(); };
                lt.appendChild(b);
            });
            document.getElementById('rem_bloco_tags').style.display='block';
        }

        function debounceRemBusca() { clearTimeout(_remDebounce); _remDebounce = setTimeout(buscarAlunoRem, 400); }
        async function buscarAlunoRem() {
            const termo = document.getElementById('rem_busca_aluno').value.trim();
            if (!termo) return;
            const res = document.getElementById('rem_resultados_busca');
            res.style.display='block'; res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            const d = await api(`ajax_action=buscar_aluno_nivel3&termo=${encodeURIComponent(termo)}`);
            if (!d.sucesso || !d.usuarios.length) { res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;">Nenhum aluno encontrado.</div>'; return; }
            res.innerHTML = d.usuarios.map(u => `<div class="busca-result-item" data-id="${u.id}" data-nome="${escHtml(u.nome)}" data-rgpm="${escHtml(u.rgpm)}" data-discord="${escHtml(u.discord||'')}"><strong>${escHtml(u.nome)}</strong> <span style="color:var(--muted);font-size:11px;">RGPM: ${escHtml(u.rgpm)} | Discord: ${escHtml(u.discord||'—')}</span></div>`).join('');
            res.querySelectorAll('.busca-result-item').forEach(el => el.addEventListener('click', () => adicionarAlunoRem(el)));
        }
        function adicionarAlunoRem(el) {
            const id=el.dataset.id, nome=el.dataset.nome, rgpm=el.dataset.rgpm, discord=el.dataset.discord;
            if (_remAlunosSelecionados.has(id)) return;
            _remAlunosSelecionados.set(id, {id,nome,rgpm,discord});
            renderChipsRem(); atualizarPrevRem();
            document.getElementById('rem_resultados_busca').style.display='none';
            document.getElementById('rem_busca_aluno').value='';
        }
        function renderChipsRem() {
            const wrap = document.getElementById('rem_alunos_selecionados');
            wrap.innerHTML = [..._remAlunosSelecionados.values()].map(u => `<span style="display:inline-flex;align-items:center;gap:5px;background:#fee2e2;border:1px solid #fecaca;color:#dc2626;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;"><i class="fas fa-user" style="font-size:9px;"></i>${escHtml(u.nome)} <button onclick="removerAlunoRem('${u.id}')" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:0;font-size:11px;margin-left:2px;">✕</button></span>`).join('');
        }
        function removerAlunoRem(id) { _remAlunosSelecionados.delete(id); renderChipsRem(); atualizarPrevRem(); }
        function atualizarPrevRem() {
            const c=document.getElementById('rem_curso').value, p=document.getElementById('rem_bloco_preview');
            if (c&&tagsRemocao.size>0&&_remAlunosSelecionados.size>0) { document.getElementById('rem_prev_curso').textContent=c; const w=document.getElementById('rem_prev_tags_wrap'); w.innerHTML=''; tagsRemocao.forEach(t=>{const sp=document.createElement('span');sp.className='badge badge-red';sp.textContent=t;w.appendChild(sp);}); document.getElementById('rem_prev_total').textContent=_remAlunosSelecionados.size+' usuário(s)'; p.style.display='block'; } else p.style.display='none';
        }
        async function executarRemoverTags() {
            const c=document.getElementById('rem_curso').value;
            if (!c||tagsRemocao.size===0||!_remAlunosSelecionados.size) { toast('Preencha todos os campos!','error'); return; }
            const btn=document.querySelector('#modalRemoverTags .btn-danger'); btn.disabled=true;
            const arr=[...tagsRemocao]; let atualiz=0, naoEnc=[];
            const idsDb = [..._remAlunosSelecionados.keys()];
            for (let i=0;i<arr.length;i++) { btn.innerHTML=`<i class="fas fa-spinner fa-spin"></i> Tag ${i+1}/${arr.length}...`; const d=await api(`ajax_action=remover_tags&curso=${encodeURIComponent(c)}&tag=${encodeURIComponent(arr[i])}&ids=${encodeURIComponent(idsDb.join(','))}&usar_id=1`); if (d.sucesso) { atualiz=Math.max(atualiz,d.atualizados||0); if (d.lista_nao_encontrados?.length) naoEnc=[...new Set([...naoEnc,...d.lista_nao_encontrados])]; } }
            btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Executar';
            let msg=`✅ ${arr.length} tag(s) removidas! ${atualiz} usuário(s).`; if (naoEnc.length) msg+=` ⚠️ Não encontrados: ${naoEnc.join(', ')}`;
            toast(msg,'success'); fecharModalRemoverTags(); atualizarStats();
        }

        // ── ATA PDF ───────────────────────────────────────────────────────────────
        let _ataAlunosSelecionados = new Map(); // id -> {id,nome,rgpm,discord}
        let _ataAuxiliaresSelecionados = new Map();
        let _ataAtivo = false; // teve auxiliar
        let _ataDebounce = null, _auxDebounce = null;

        function abrirModal() {
            _ataAlunosSelecionados = new Map(); _ataAuxiliaresSelecionados = new Map(); _ataAtivo = false;
            document.getElementById('modalAta').classList.remove('hidden');
            ['ata_busca_aluno','ata_busca_aux','ata_duracao','ata_turno'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
            document.getElementById('ata_alunos_chips').innerHTML='';
            document.getElementById('ata_auxiliares_selecionados').innerHTML='';
            ['ata_resultados_busca','ata_resultados_aux','ata-preview-alunos'].forEach(id => { const el=document.getElementById(id); if(el) el.style.display='none'; });
            toggleAuxiliar(false);
        }
        function fecharModal() {
            document.getElementById('modalAta').classList.add('hidden');
            document.getElementById('ata-preview-alunos').style.display='none';
        }

        function toggleAuxiliar(sim) {
            _ataAtivo = sim;
            document.getElementById('bloco_auxiliares').style.display = sim ? 'block' : 'none';
            document.getElementById('btn_aux_sim').style.cssText = sim ? 'padding:5px 14px;border-radius:7px;border:1px solid var(--blue);font-size:12px;font-weight:700;font-family:Inter,sans-serif;cursor:pointer;background:#dbeafe;color:var(--blue);' : 'padding:5px 14px;border-radius:7px;border:1px solid var(--border);font-size:12px;font-weight:700;font-family:Inter,sans-serif;cursor:pointer;background:var(--white);color:var(--muted);';
            document.getElementById('btn_aux_nao').style.cssText = !sim ? 'padding:5px 14px;border-radius:7px;border:1px solid var(--blue);font-size:12px;font-weight:700;font-family:Inter,sans-serif;cursor:pointer;background:#dbeafe;color:var(--blue);' : 'padding:5px 14px;border-radius:7px;border:1px solid var(--border);font-size:12px;font-weight:700;font-family:Inter,sans-serif;cursor:pointer;background:var(--white);color:var(--muted);';
        }

        // Busca de alunos para ata
        function debounceAtaBusca() { clearTimeout(_ataDebounce); _ataDebounce = setTimeout(buscarAlunoAta, 400); }
        async function buscarAlunoAta() {
            const termo = document.getElementById('ata_busca_aluno').value.trim();
            if (!termo) return;
            const res = document.getElementById('ata_resultados_busca');
            res.style.display='block'; res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            const d = await api(`ajax_action=buscar_aluno_nivel3&termo=${encodeURIComponent(termo)}`);
            if (!d.sucesso || !d.usuarios.length) { res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;">Nenhum aluno encontrado (nível 3).</div>'; return; }
            res.innerHTML = d.usuarios.map(u => `<div class="busca-result-item" data-id="${u.id}" data-nome="${escHtml(u.nome)}" data-rgpm="${escHtml(u.rgpm)}" data-discord="${escHtml(u.discord||'')}"><strong>${escHtml(u.nome)}</strong> <span style="color:var(--muted);font-size:11px;">RGPM: ${escHtml(u.rgpm)} | Discord: ${escHtml(u.discord||'—')}</span></div>`).join('');
            res.querySelectorAll('.busca-result-item').forEach(el => el.addEventListener('click', () => adicionarAlunoAta(el)));
        }
        function adicionarAlunoAta(el) {
            const id=el.dataset.id, nome=el.dataset.nome, rgpm=el.dataset.rgpm, discord=el.dataset.discord;
            if (_ataAlunosSelecionados.has(id)) { document.getElementById('ata_resultados_busca').style.display='none'; return; }
            _ataAlunosSelecionados.set(id, {id,nome,rgpm,discord});
            renderChipsAta(); atualizarPreviewAlunos();
            document.getElementById('ata_resultados_busca').style.display='none';
            document.getElementById('ata_busca_aluno').value='';
        }
        function renderChipsAta() {
            const wrap = document.getElementById('ata_alunos_chips');
            wrap.innerHTML = [..._ataAlunosSelecionados.values()].map(u => `<span style="display:inline-flex;align-items:center;gap:5px;background:#f0f7ff;border:1px solid #c7d7f9;color:#1e40af;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;"><i class="fas fa-user" style="font-size:9px;"></i>${escHtml(u.nome)} <button onclick="removerAlunoAta('${u.id}')" style="background:none;border:none;cursor:pointer;color:#1e40af;padding:0;font-size:11px;margin-left:2px;">✕</button></span>`).join('');
        }
        function removerAlunoAta(id) { _ataAlunosSelecionados.delete(id); renderChipsAta(); atualizarPreviewAlunos(); }
        function atualizarPreviewAlunos() {
            const lista = [..._ataAlunosSelecionados.values()];
            const prev = document.getElementById('ata-preview-alunos');
            if (!lista.length) { prev.style.display='none'; return; }
            prev.style.display='block';
            document.getElementById('ata-preview-count').textContent = lista.length;
            document.getElementById('ata-preview-lista').innerHTML = lista.map(u => `<div>• ${escHtml(u.nome)} (${escHtml(u.rgpm)})</div>`).join('');
        }

        // Busca de auxiliares para ata
        function debounceAuxBusca() { clearTimeout(_auxDebounce); _auxDebounce = setTimeout(buscarAuxiliarAta, 400); }
        async function buscarAuxiliarAta() {
            const termo = document.getElementById('ata_busca_aux').value.trim();
            if (!termo) return;
            const res = document.getElementById('ata_resultados_aux');
            res.style.display='block'; res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';
            const d = await api(`ajax_action=buscar_auxiliar_nivel2&termo=${encodeURIComponent(termo)}`);
            if (!d.sucesso || !d.usuarios.length) { res.innerHTML='<div style="padding:10px;color:var(--muted);font-size:12px;">Nenhum instrutor/auxiliar encontrado (nível 2).</div>'; return; }
            res.innerHTML = d.usuarios.map(u => `<div class="busca-result-item" data-id="${u.id}" data-nome="${escHtml(u.nome)}" data-rgpm="${escHtml(u.rgpm)}" data-discord="${escHtml(u.discord||'')}"><strong>${escHtml(u.nome)}</strong> <span style="color:var(--muted);font-size:11px;">RGPM: ${escHtml(u.rgpm)} | Discord: ${escHtml(u.discord||'—')}</span></div>`).join('');
            res.querySelectorAll('.busca-result-item').forEach(el => el.addEventListener('click', () => adicionarAuxiliarAta(el)));
        }
        function adicionarAuxiliarAta(el) {
            const id=el.dataset.id, nome=el.dataset.nome, rgpm=el.dataset.rgpm, discord=el.dataset.discord;
            if (_ataAuxiliaresSelecionados.has(id)) { document.getElementById('ata_resultados_aux').style.display='none'; return; }
            _ataAuxiliaresSelecionados.set(id, {id,nome,rgpm,discord});
            renderChipsAux();
            document.getElementById('ata_resultados_aux').style.display='none';
            document.getElementById('ata_busca_aux').value='';
        }
        function renderChipsAux() {
            const wrap = document.getElementById('ata_auxiliares_selecionados');
            wrap.innerHTML = [..._ataAuxiliaresSelecionados.values()].map(u => `<span style="display:inline-flex;align-items:center;gap:5px;background:#fdf4ff;border:1px solid #e9d5ff;color:#7c3aed;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;"><i class="fas fa-user-tie" style="font-size:9px;"></i>${escHtml(u.nome)} <button onclick="removerAuxiliarAta('${u.id}')" style="background:none;border:none;cursor:pointer;color:#7c3aed;padding:0;font-size:11px;margin-left:2px;">✕</button></span>`).join('');
        }
        function removerAuxiliarAta(id) { _ataAuxiliaresSelecionados.delete(id); renderChipsAux(); }

        function normalizarAlunos(raw) { let itens; if (raw.includes(',')) { itens=raw.split(',').flatMap(p=>p.split('\n')); } else { itens=raw.split('\n'); } return itens.map(s=>s.trim()).filter(Boolean); }

        function gerarPDFFinal() {
            const { jsPDF }=window.jspdf; const doc=new jsPDF();
            const aula=document.getElementById('ata_aula').value.trim();
            const inst=document.getElementById('ata_instrutor').value.trim();
            const obs=document.getElementById('ata_obs').value.trim();
            const curso=document.getElementById('ata_curso').value;
            const duracao=document.getElementById('ata_duracao').value.trim();
            const turno=document.getElementById('ata_turno').value.trim();
            const alunos=[..._ataAlunosSelecionados.values()];
            const auxiliares=[..._ataAuxiliaresSelecionados.values()];

            if (!curso) { toast('⚠️ Selecione o curso!','error'); return; }
            if (!aula) { toast('⚠️ Informe o nome da aula!','error'); return; }
            if (!alunos.length) { toast('⚠️ Adicione ao menos um aluno!','error'); return; }

            doc.setFillColor(13,27,46); doc.rect(0,0,210,30,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(20); doc.setFont(undefined,'bold');
            doc.text('ATA DE REGISTRO E FREQUÊNCIA', 105, 20, { align:'center' });
            doc.setTextColor(50,50,50); doc.setFontSize(10);
            let y = 40;
            doc.text(`CURSO: ${curso}`,14,y); y+=6;
            doc.text(`AULA: ${aula.toUpperCase()}`,14,y); y+=6;
            if (inst) { doc.text(`INSTRUTOR: ${inst}`,14,y); y+=6; }
            if (duracao) { doc.text(`DURAÇÃO: ${duracao}`,14,y); y+=6; }
            if (turno) { doc.text(`TURNO: ${turno}`,14,y); y+=6; }
            if (auxiliares.length) { doc.text(`AUXILIAR(ES): ${auxiliares.map(u=>u.nome).join(', ')}`,14,y); y+=6; }
            if (obs) { doc.text(`OBS: ${obs}`,14,y); y+=6; }

            doc.autoTable({ startY:y+4, head:[['LISTAGEM DE ALUNOS']], body:alunos.map(u=>[u.nome+' ('+u.rgpm+')']), headStyles:{ fillColor:[13,27,46] } });

            const listaFinal = alunos.map(u => u.nome+' ('+u.rgpm+')').join('\n');
            const auxiliaresStr = auxiliares.map(u => u.nome+' ('+u.rgpm+')').join(', ');
            const pb=doc.output('blob'); const pf=new File([pb],'relatorio.pdf',{type:'application/pdf'});
            const fd=new FormData();
            fd.append('meu_pdf',pf); fd.append('nome_aula',aula); fd.append('lista_presenca',listaFinal);
            fd.append('instrutor',inst); fd.append('observacoes',obs); fd.append('curso',curso);
            fd.append('duracao_aula',duracao); fd.append('turno_aula',turno); fd.append('auxiliares',auxiliaresStr);
            fd.append('csrf_token',CSRF_TOKEN);
            fetch('salvar_ata.php',{method:'POST',body:fd}).then(r=>r.text()).then(()=>{ toast('✅ Ata salva!','success'); doc.save(`ATA_${aula.replace(/\s+/g,'_')}.pdf`); fecharModal(); _lastAtas=''; atualizarStats(); }).catch(e=>toast('Erro: '+e.message,'error'));
        }

        // ── CRONOGRAMA ────────────────────────────────────────────────────────────
        function abrirModalCronograma() { document.getElementById('modalCronograma').classList.remove('hidden'); }
        function fecharModalCronograma() { document.getElementById('modalCronograma').classList.add('hidden'); }
        function salvarCronograma() {
            const t=document.getElementById('cron_titulo').value, a=document.getElementById('cron_arquivo').files[0];
            if (!t||!a) { toast('Preencha todos os campos.','error'); return; }
            const fd=new FormData(); fd.append('titulo',t); fd.append('arquivo',a); fd.append('csrf_token',CSRF_TOKEN);
            fetch('salvar_cronograma.php',{method:'POST',body:fd}).then(r=>{ if (!r.ok) throw new Error('Erro '+r.status); return r.text(); })
                .then(r=>{ if (r.trim()==='sucesso') { toast('✅ Cronograma salvo!','success'); fecharModalCronograma(); _lastCronogramas=''; atualizarStats(); } else toast('❌ '+r,'error'); })
                .catch(e=>toast('Erro: '+e.message,'error'));
        }

        // ── EDITAR ATA ────────────────────────────────────────────────────────────
        function abrirModalEditarAta(id, nome) {
            document.getElementById('ea-id').value=id;
            document.getElementById('ea-sub').textContent='Carregando...';
            document.getElementById('ea-nome').value='';
            document.getElementById('ea-instrutor').value='';
            document.getElementById('ea-obs').value='';
            document.getElementById('ea-lista').value='';
            document.getElementById('ea-btn-salvar').disabled=false;
            document.getElementById('modalEditarAta').classList.remove('hidden');
            fetch('painel_admin.php',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`ajax_action=editar_ata&sub=carregar&id=${encodeURIComponent(id)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}` })
                .then(r=>r.text()).then(text=>{
                    let d; try { d=JSON.parse(text); } catch(e) { document.getElementById('ea-sub').textContent=nome; toast('⚠️ Não foi possível carregar os dados atuais da ata.','error'); return; }
                    if (!d.sucesso) { document.getElementById('ea-sub').textContent=nome; toast('⚠️ '+(d.erro||'Erro ao carregar ata'),'error'); return; }
                    const a=d.ata;
                    document.getElementById('ea-sub').textContent=a.nome_referencia||nome;
                    document.getElementById('ea-nome').value=a.nome_referencia||'';
                    document.getElementById('ea-instrutor').value=a.instrutor||'';
                    document.getElementById('ea-obs').value=a.observacoes||'';
                    document.getElementById('ea-lista').value=a.lista_presenca||'';
                    setTimeout(()=>document.getElementById('ea-nome').focus(),100);
                }).catch(()=>{ document.getElementById('ea-sub').textContent=nome; });
        }
        function fecharModalEditarAta() { document.getElementById('modalEditarAta').classList.add('hidden'); }
        async function salvarEdicaoAta() {
            const id=document.getElementById('ea-id').value;
            const nome=document.getElementById('ea-nome').value.trim();
            const inst=document.getElementById('ea-instrutor').value.trim();
            const obs=document.getElementById('ea-obs').value.trim();
            const listaRaw=document.getElementById('ea-lista').value.trim();
            const listaItens=listaRaw.split(/[\n,]/).map(s=>s.trim()).filter(Boolean);
            const lista=listaItens.join('\n');
            if (!nome) { toast('⚠️ Nome da aula é obrigatório!','error'); document.getElementById('ea-nome').focus(); return; }
            if (!lista) { toast('⚠️ A lista de presença não pode ficar vazia!','error'); return; }
            const btn=document.getElementById('ea-btn-salvar'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Salvando...';
            const body=`ajax_action=editar_ata&id=${encodeURIComponent(id)}&nome_referencia=${encodeURIComponent(nome)}&instrutor=${encodeURIComponent(inst)}&observacoes=${encodeURIComponent(obs)}&lista_presenca=${encodeURIComponent(lista)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`;
            try {
                const r=await fetch('painel_admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
                const text=await r.text(); let d; try { d=JSON.parse(text); } catch(e) { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Salvar Alterações'; toast('❌ Resposta inesperada do servidor.','error'); return; }
                btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Salvar Alterações';
                if (d.sucesso) { toast('✅ Ata atualizada com sucesso!','success'); fecharModalEditarAta(); _lastAtas=''; atualizarStats(); }
                else toast('❌ '+(d.erro||'Falha ao salvar'),'error');
            } catch(e) { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Salvar Alterações'; toast('❌ Erro de rede: '+e.message,'error'); }
        }

        // ── BLACKLIST ─────────────────────────────────────────────────────────────
        <?php if ($nivelSessao <= NIVEL_ADM): ?>

        // Estilos dos botões de duração
        (function() {
            const style = document.createElement('style');
            style.textContent = `
                .bl-dur-btn {
                    padding: 10px 8px;
                    border-radius: 8px;
                    font-family: 'Inter', sans-serif;
                    font-size: 13px;
                    font-weight: 700;
                    cursor: pointer;
                    border: 2px solid var(--border);
                    background: var(--light);
                    color: var(--muted);
                    transition: all .15s;
                }
                .bl-dur-btn.ativo {
                    border-color: #dc2626;
                    background: #fef2f2;
                    color: #dc2626;
                }
            `;
            document.head.appendChild(style);
        })();

        // Estado
        let _blPosUsuario  = null;  // dados do usuário excluído
        let _blPosDuracao  = null;  // duração selecionada
        let _blFormAberto  = false; // se o form de motivo está aberto

        // ── Cronômetro ─────────────────────────────────────────────────────────
        function calcularTempoRestante(expiracao) {
            if (!expiracao) return null;
            const diff = new Date(expiracao) - new Date();
            if (diff <= 0) return null;
            const d = Math.floor(diff / 86400000);
            const h = Math.floor((diff % 86400000) / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            if (d > 0) return `${d}d ${h}h ${m}m`;
            if (h > 0) return `${h}h ${m}m ${s}s`;
            return `${m}m ${s}s`;
        }

        let _blTimerInterval = null;

        function iniciarCronometros() {
            if (_blTimerInterval) clearInterval(_blTimerInterval);
            _blTimerInterval = setInterval(() => {
                document.querySelectorAll('[data-expiracao]').forEach(el => {
                    const exp = el.dataset.expiracao;
                    const resto = calcularTempoRestante(exp);
                    const id = el.dataset.blid;
                    if (!resto) {
                        // Expirou — remover automaticamente da lista visual e do BD
                        el.closest('tr')?.remove();
                        api(`ajax_action=remover_blacklist&id=${id}`).catch(()=>{});
                    } else {
                        el.textContent = resto;
                    }
                });
            }, 1000);
        }

        // ── Carregar lista ─────────────────────────────────────────────────────
        function carregarBlacklist() {
            const tbody = document.getElementById('tbody-blacklist');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></td></tr>';

            api('ajax_action=listar_blacklist').then(d => {
                if (!d || !d.sucesso) {
                    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Erro: ' + escHtml((d && d.erro) || 'Falha ao carregar') + '</p></div></td></tr>';
                    return;
                }
                const lista = Array.isArray(d.blacklist) ? d.blacklist : [];
                const ativos = lista.filter(b => !b.expirado);
                if (!ativos.length) {
                    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-shield-alt" style="color:#bbf7d0;font-size:2rem;"></i><p style="color:#15803d;font-weight:700;margin-top:8px;">Nenhum usuário na blacklist.</p></div></td></tr>';
                    if (_blTimerInterval) clearInterval(_blTimerInterval);
                    return;
                }

                tbody.innerHTML = ativos.map(b => {
                    const dtAplicacao = new Date(b.adicionado_em).toLocaleString('pt-BR');
                    const motivoText  = b.motivo_tipo === 'texto' ? escHtml(b.motivo_texto || '—') : '<span class="badge badge-indigo"><i class="fas fa-file-pdf"></i> PDF</span>';
                    const isPerm      = b.tempo === 'permanente';
                    const duracaoCell = isPerm
                        ? '<span class="badge badge-red"><i class="fas fa-infinity" style="margin-right:4px;"></i>Permanente</span>'
                        : `<span class="badge badge-yellow" data-expiracao="${b.expiracao}" data-blid="${b.id}">calculando...</span>`;

                    return `<tr>
                        <td class="strong">${escHtml(b.nome)}</td>
                        <td style="font-family:monospace;">${escHtml(b.rgpm)}</td>
                        <td>${escHtml(b.discord || '—')}</td>
                        <td style="max-width:180px;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${motivoText}</td>
                        <td>${duracaoCell}</td>
                        <td style="font-size:12px;">${escHtml(b.adicionado_por || '—')}</td>
                        <td style="font-size:11px;color:var(--muted);">${dtAplicacao}</td>
                    </tr>`;
                }).join('');

                iniciarCronometros();
            }).catch(() => {
                tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Erro ao carregar.</p></div></td></tr>';
            });
        }

        // ── Modal Remover da BL ────────────────────────────────────────────────
        function abrirModalRemoverBL() {
            document.getElementById('rem-bl-busca').value = '';
            const res = document.getElementById('rem-bl-resultados');
            res.style.display = 'none';
            res.innerHTML = '';
            document.getElementById('modalRemoverBL').style.display = 'flex';
        }
        function fecharModalRemoverBL() {
            document.getElementById('modalRemoverBL').style.display = 'none';
        }

        function buscarParaRemoverBL() {
            const termo = document.getElementById('rem-bl-busca').value.trim();
            if (!termo) { toast('Digite um nome ou RGPM para buscar', 'error'); return; }
            const res = document.getElementById('rem-bl-resultados');
            res.style.display = 'flex';
            res.innerHTML = '<div style="color:var(--muted);font-size:13px;padding:8px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

            api('ajax_action=listar_blacklist').then(d => {
                if (!d.sucesso || !d.blacklist.length) {
                    res.innerHTML = '<div style="color:var(--muted);font-size:13px;padding:8px;">Nenhum usuário na blacklist.</div>';
                    return;
                }
                const t = termo.toLowerCase();
                const encontrados = d.blacklist.filter(b =>
                    !b.expirado && (
                        b.nome.toLowerCase().includes(t) ||
                        b.rgpm.toLowerCase().includes(t) ||
                        (b.discord || '').toLowerCase().includes(t)
                    )
                );
                if (!encontrados.length) {
                    res.innerHTML = '<div style="color:var(--muted);font-size:13px;padding:8px;">Nenhum resultado.</div>';
                    return;
                }
                res.innerHTML = '';
                encontrados.forEach(b => {
                    const div = document.createElement('div');
                    div.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:11px 13px;border:1.5px solid #fecaca;border-radius:8px;background:#fff5f5;gap:10px;';
                    div.innerHTML = `
                        <div>
                            <div style="font-size:13px;font-weight:700;color:var(--text);">${escHtml(b.nome)} <span style="font-family:monospace;font-weight:400;font-size:12px;color:var(--muted);">| ${escHtml(b.rgpm)}</span></div>
                            <div style="font-size:11px;color:var(--muted);margin-top:2px;">${escHtml(b.motivo_texto || '—')}</div>
                        </div>
                        <button onclick="confirmarRemocaoBL(${b.id}, '${escHtml(b.nome)}')"
                            style="flex-shrink:0;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;border-radius:8px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;">
                            <i class="fas fa-unlock-alt"></i> Remover
                        </button>`;
                    res.appendChild(div);
                });
            });
        }

        function confirmarRemocaoBL(id, nome) {
            fecharModalRemoverBL();
            vpConfirm(
                'Remover da Blacklist',
                `Tem certeza que deseja remover <strong>${escHtml(nome)}</strong> da blacklist? O usuário poderá se cadastrar novamente.`,
                '#16a34a', '<i class="fas fa-unlock-alt"></i>',
                async () => {
                    const d = await api(`ajax_action=remover_blacklist&id=${id}`);
                    if (d.sucesso) { toast('✅ Usuário removido da blacklist!', 'success'); carregarBlacklist(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        // ── Modal pós-exclusão ─────────────────────────────────────────────────
        function mostrarModalBlPosExclusao(usuario) {
            _blPosUsuario = usuario;
            _blPosDuracao = null;
            _blFormAberto = false;

            document.getElementById('bl-pos-nome-sub').textContent   = 'Conta de ' + (usuario.nome || '—') + ' excluída';
            document.getElementById('bl-pos-nome-destaque').textContent = usuario.nome || '—';
            document.getElementById('bl-pos-motivo').value = '';
            document.getElementById('bl-pos-form').style.display = 'none';
            document.getElementById('bl-pos-btn-sim').innerHTML = '<i class="fas fa-ban"></i> Sim, adicionar à Blacklist';

            // Reset botões duração
            document.querySelectorAll('.bl-dur-btn').forEach(b => b.classList.remove('ativo'));

            document.getElementById('modalBlPosExclusao').style.display = 'flex';
        }

        function blPosSelecionarDuracao(btn) {
            document.querySelectorAll('.bl-dur-btn').forEach(b => b.classList.remove('ativo'));
            btn.classList.add('ativo');
            _blPosDuracao = btn.dataset.val;
        }

        function blPosNao() {
            document.getElementById('modalBlPosExclusao').style.display = 'none';
            mostrarSucessoExclusao(_blPosUsuario?.nome || '');
            _blPosUsuario = null;
            // Aguarda 1.5s para o INSERT do arquivo ser confirmado no banco antes de recalcular
            setTimeout(() => { atualizarStats(); }, 1500);
        }

        async function blPosSim() {
            // 1ª vez: abrir formulário de motivo/duração
            if (!_blFormAberto) {
                _blFormAberto = true;
                document.getElementById('bl-pos-form').style.display = 'flex';
                document.getElementById('bl-pos-btn-sim').innerHTML = '<i class="fas fa-ban"></i> Confirmar e Banir';
                return;
            }

            // 2ª vez: validar e salvar
            const motivo = document.getElementById('bl-pos-motivo').value.trim();
            if (!motivo) { toast('⚠️ Informe o motivo!', 'error'); document.getElementById('bl-pos-motivo').focus(); return; }
            if (!_blPosDuracao) { toast('⚠️ Selecione a duração!', 'error'); return; }

            const u = _blPosUsuario || {};

            // Envia duração nomeada — PHP calcula a data no servidor com timezone correto
            let expiracao = _blPosDuracao !== 'permanente' ? _blPosDuracao : '';

            const fd = new FormData();
            fd.append('ajax_action',  'adicionar_blacklist');
            fd.append('csrf_token',   CSRF_TOKEN);
            fd.append('nome',         u.nome     || '');
            fd.append('rgpm',         u.rgpm     || '');
            fd.append('discord',      u.discord  || '');
            fd.append('ip',           u.ip_publico || '');
            fd.append('motivo_tipo',  'texto');
            fd.append('motivo_texto', motivo);
            fd.append('tempo',        _blPosDuracao === 'permanente' ? 'permanente' : 'temporario');
            fd.append('expiracao',    expiracao || '');

            // Fingerprint
            ['fp_user_agent','fp_idioma','fp_timezone','fp_resolucao','fp_plataforma','fp_canvas_hash','fp_webgl_hash','fp_audio_hash','fp_fonts'].forEach(k => {
                fd.append(k, u[k] || '');
            });

            const btn = document.getElementById('bl-pos-btn-sim');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            document.getElementById('modalBlPosExclusao').style.display = 'none';

            const r = await fetch('painel_admin.php', { method: 'POST', body: fd });
            const d = await r.json();

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-ban"></i> Confirmar e Banir';

            if (d.sucesso) {
                toast('🚫 Usuário adicionado à blacklist!', 'success');
                carregarBlacklist();
            } else {
                toast('❌ ' + (d.erro || 'Falha ao adicionar à blacklist'), 'error');
            }

            mostrarSucessoExclusao(u.nome || '');
            _blPosUsuario = null;
            // Aguarda para garantir que o arquivo já foi salvo no banco
            setTimeout(() => { atualizarStats(); }, 1500);
        }

        <?php endif; ?>


        // ── SEARCH MEMBROS + INIT ─────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('pesquisaMembro')?.addEventListener('keyup', filtrarMembros);
            ['nc-nome','nc-descricao','nc-tipo','nc-status','nc-taxa','nc-tags'].forEach(id=>document.getElementById(id)?.addEventListener('input',atualizarPreviewCurso));
            document.getElementById('ec-tags')?.addEventListener('input', atualizarPreviewTagsEditar);
            document.getElementById('eu-busca')?.addEventListener('keydown', e=>{ if(e.key==='Enter') buscarUsuarioEditar(); });
            router('dashboard');
            setTimeout(iniciarPolling, 500);
        });

        // ══════════════════════════════════════════════════════════════════════
        // SISTEMA DE MULTAS — ADM
        // ══════════════════════════════════════════════════════════════════════

        function adm_opcaoMulta()       { abrirModalAplicarMulta(); toggleFab(); }
        function adm_opcaoValidarMulta(){ abrirModalValidarMulta(); toggleFab(); }
        function adm_opcaoLogsMultas()  { abrirModalLogsMultas();   toggleFab(); }

        // ── Formatação de moeda ────────────────────────────────────────────────
        function fmtMoeda(v) {
            return 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // ── Mascarar input de valor monetário ─────────────────────────────────
        function maskMoneyInput(el) {
            el.addEventListener('input', function() {
                let v = this.value.replace(/[^\d,]/g, ''); // só números e vírgula
                // Garante no máximo uma vírgula
                const parts = v.split(',');
                if (parts.length > 2) v = parts[0] + ',' + parts.slice(1).join('');
                // Limita a 2 casas decimais após a vírgula
                if (parts.length === 2 && parts[1].length > 2) {
                    v = parts[0] + ',' + parts[1].substring(0, 2);
                }
                this.value = v;
            });
        }

        // ── Contador regressivo ────────────────────────────────────────────────
        function calcRestante(expira) {
            const diff = new Date(expira) - new Date();
            if (diff <= 0) return null;
            const d = Math.floor(diff / 86400000);
            const h = Math.floor((diff % 86400000) / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            if (d > 0) return `${d}d ${h}h ${m}m`;
            if (h > 0) return `${h}h ${m}m ${s}s`;
            return `${m}m ${s}s`;
        }

        let _multaTimers = [];
        function iniciarTimersMulta() {
            _multaTimers.forEach(t => clearInterval(t));
            _multaTimers = [];
            document.querySelectorAll('[data-multa-expira]').forEach(el => {
                const t = setInterval(() => {
                    const r = calcRestante(el.dataset.multaExpira);
                    if (!r) { el.textContent = '⚠️ VENCIDO'; el.style.color = '#dc2626'; clearInterval(t); }
                    else el.textContent = r;
                }, 1000);
                _multaTimers.push(t);
            });
        }

        // ════════════════════════════════════════════
        // MODAL: APLICAR MULTA
        // ════════════════════════════════════════════
        let _multaAlunoSel = null;

        function abrirModalAplicarMulta() {
            _multaAlunoSel = null;
            document.getElementById('am-busca').value = '';
            document.getElementById('am-resultados').style.display = 'none';
            document.getElementById('am-resultados').innerHTML = '';
            document.getElementById('am-form').style.display = 'none';
            document.getElementById('am-valor').value = '';
            document.getElementById('am-link').value = '';
            document.getElementById('am-prazo').value = '24';
            document.getElementById('modalAplicarMulta').classList.remove('hidden');
            // Aplica máscara monetária no campo valor
            const el = document.getElementById('am-valor');
            if (!el._maskApplied) { maskMoneyInput(el); el._maskApplied = true; }
        }
        function fecharModalAplicarMulta() { document.getElementById('modalAplicarMulta').classList.add('hidden'); }

        async function buscarAlunoMulta() {
            const termo = document.getElementById('am-busca').value.trim();
            if (!termo) { document.getElementById('am-resultados').style.display = 'none'; return; }
            // Limpa seleção anterior ao buscar novamente
            _multaAlunoSel = null;
            document.getElementById('am-form').style.display = 'none';
            const res = document.getElementById('am-resultados');
            res.style.display = 'block';
            res.innerHTML = '<div style="padding:10px;color:var(--muted);font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>';

            try {
                const d = await api('ajax_action=multa_buscar_aluno&termo=' + encodeURIComponent(termo));

                if (!d.sucesso) {
                    res.innerHTML = '<div style="padding:10px;color:red;font-size:12px;"><i class="fas fa-exclamation-circle" style="margin-right:5px;"></i>' + escHtml(d.erro || 'Erro ao buscar') + '</div>';
                    return;
                }
                if (!d.usuarios || !d.usuarios.length) {
                    res.innerHTML = '<div style="padding:10px;color:var(--muted);font-size:12px;">Nenhum usuário encontrado.</div>';
                    return;
                }
                res.innerHTML = d.usuarios.map(u => {
                    const nivelLabel = String(u.nivel) === '1' ? '<span class="badge badge-red" style="font-size:10px;">ADM</span>' :
                                       String(u.nivel) === '2' ? '<span class="badge badge-indigo" style="font-size:10px;">Instrutor</span>' :
                                       '<span class="badge badge-blue" style="font-size:10px;">Aluno</span>';
                    return `<div class="busca-result-item" onclick="selecionarAlunoMulta(${u.id},'${escHtml(u.nome)}','${escHtml(u.rgpm)}','${escHtml(u.discord||'')}','${escHtml(u.meus_cursos||'')}')">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <strong>${escHtml(u.nome)}</strong>
                            ${nivelLabel}
                        </div>
                        <div style="font-size:11px;color:var(--muted);">RGPM: ${escHtml(u.rgpm)} · Discord: ${escHtml(u.discord||'—')} · ${escHtml(u.meus_cursos||'Sem curso')}</div>
                    </div>`;
                }).join('');
            } catch(e) {
                res.innerHTML = '<div style="padding:10px;color:red;font-size:12px;">Erro: ' + escHtml(e.message) + '</div>';
            }
        }

        function selecionarAlunoMulta(id, nome, rgpm, discord, curso) {
            _multaAlunoSel = { id, nome, rgpm, discord, curso };
            document.getElementById('am-resultados').style.display = 'none';
            document.getElementById('am-aluno-badge').innerHTML =
                `<i class="fas fa-user" style="margin-right:6px;color:var(--blue);"></i>${escHtml(nome)} · RGPM ${escHtml(rgpm)} · <span style="color:var(--muted);">${escHtml(curso||'sem curso')}</span>`;
            document.getElementById('am-form').style.display = 'block';
        }

        async function confirmarAplicarMulta() {
            if (!_multaAlunoSel) { toast('Selecione o aluno', 'error'); return; }
            const valorRaw = document.getElementById('am-valor').value.trim();
            const link     = document.getElementById('am-link').value.trim();
            const prazo    = document.getElementById('am-prazo').value;
            if (!valorRaw) { toast('Informe o valor', 'error'); return; }
            if (!link)     { toast('Informe o link da advertência', 'error'); return; }

            // Converte valor BR (ex: 150,00) para número (150.00)
            const valorNum = valorRaw.replace(',','.');

            const btn = document.getElementById('am-btn-confirmar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Aplicando...';

            const d = await api(
                `ajax_action=aplicar_multa&usuario_id=${_multaAlunoSel.id}`
                + `&valor=${encodeURIComponent(valorNum)}`
                + `&link_adv=${encodeURIComponent(link)}`
                + `&prazo_horas=${encodeURIComponent(prazo)}`
            );

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-gavel"></i> Aplicar Multa';

            if (d.sucesso) {
                toast(`✅ Multa #${d.id} aplicada para ${_multaAlunoSel.nome}!`, 'success');
                fecharModalAplicarMulta();
            } else {
                toast('❌ ' + (d.erro || 'Falha'), 'error');
            }
        }

        // ════════════════════════════════════════════
        // MODAL: VALIDAR / GERENCIAR MULTAS
        // ════════════════════════════════════════════
        function abrirModalValidarMulta() {
            document.getElementById('vm-busca').value = '';
            document.getElementById('modalValidarMulta').classList.remove('hidden');
            carregarMultasPendentesAdm();
        }
        function fecharModalValidarMulta() { document.getElementById('modalValidarMulta').classList.add('hidden'); _multaTimers.forEach(t => clearInterval(t)); _multaTimers = []; }

        async function carregarMultasPendentesAdm() {
            const busca  = document.getElementById('vm-busca').value.trim();
            const lista  = document.getElementById('vm-lista');
            lista.innerHTML = '<div style="text-align:center;padding:32px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
            const d = await api(`ajax_action=multa_listar_pendentes_adm&busca=${encodeURIComponent(busca)}`);
            document.getElementById('vm-total').textContent = d.total ? `${d.total} multa(s)` : 'Nenhuma';

            if (!d.sucesso || !d.total) {
                lista.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-check-double" style="font-size:2rem;color:#16a34a;display:block;margin-bottom:10px;"></i><p style="font-weight:700;color:#15803d;">Nenhuma multa pendente.</p></div>';
                return;
            }

            lista.innerHTML = d.multas.map(m => {
                const statusBadge = m.status === 'aguardando_validacao'
                    ? '<span class="badge badge-yellow"><i class="fas fa-hourglass-half"></i> Aguardando Validação</span>'
                    : '<span class="badge badge-red"><i class="fas fa-clock"></i> Pendente</span>';

                const prazoLabel = m.prazo_horas === 0 ? '1 minuto' :
                    m.prazo_horas < 24 ? `${m.prazo_horas}h` : `${m.prazo_horas/24} dia(s)`;

                const acoesHtml = m.status === 'aguardando_validacao' ? `
                    <button onclick="verComprovanteMulta(${m.id})" style="background:#dbeafe;color:#1a56db;border:1px solid #bfdbfe;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-image"></i> Ver</button>
                    <button onclick="validarMulta(${m.id},'${escHtml(m.nome_aluno)}')" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-check"></i> Aprovar</button>
                    <button onclick="negarMulta(${m.id},'${escHtml(m.nome_aluno)}')" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-times"></i> Negar</button>
                    <button onclick="deletarMulta(${m.id},'${escHtml(m.nome_aluno)}')" style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-trash"></i> Deletar</button>
                ` : (() => {
                    const vencida = new Date(m.prazo_expira.replace(' ','T')) <= new Date();
                    if (vencida) {
                        return `<span style="font-size:11px;color:#dc2626;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Prazo encerrado</span>`;
                    }
                    return `
                    <button onclick="editarMulta(${m.id},'${escHtml(m.nome_aluno)}',${m.valor},'${escHtml(m.link_adv)}',${m.prazo_horas})" style="background:#eff6ff;color:#1a56db;border:1px solid #bfdbfe;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-edit"></i> Editar</button>
                    <button onclick="deletarMulta(${m.id},'${escHtml(m.nome_aluno)}')" style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-trash"></i> Deletar</button>`;
                })();

                return `<div style="padding:14px 18px;border-bottom:1px solid var(--border);">
                    <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:5px;">
                                <strong style="font-size:13px;">${escHtml(m.nome_aluno)}</strong>
                                ${statusBadge}
                            </div>
                            <div style="font-size:11px;color:var(--muted);display:flex;flex-direction:column;gap:2px;">
                                <span><i class="fas fa-hashtag" style="width:14px;"></i> RGPM: <strong style="color:var(--text);">${escHtml(m.rgpm_aluno)}</strong></span>
                                <span><i class="fas fa-coins" style="width:14px;color:#d97706;"></i> Valor: <strong style="color:#15803d;">${fmtMoeda(m.valor)}</strong></span>
                                <span><i class="fas fa-link" style="width:14px;color:#7c3aed;"></i> <a href="${escHtml(m.link_adv)}" target="_blank" style="color:#7c3aed;">Ver Advertência</a></span>
                                <span><i class="fas fa-clock" style="width:14px;color:#d97706;"></i> Prazo: ${prazoLabel}</span>
                                <span><i class="fas fa-hourglass-end" style="width:14px;color:#dc2626;"></i> Restante: <strong style="color:#dc2626;" data-multa-expira="${m.prazo_expira}">${calcRestante(m.prazo_expira) || '⚠️ VENCIDO'}</strong></span>
                                <span><i class="fas fa-user-shield" style="width:14px;"></i> Aplicada por: ${escHtml(m.aplicada_por)}</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-self:flex-end;">${acoesHtml}</div>
                    </div>
                </div>`;
            }).join('');
            iniciarTimersMulta();
        }

        async function verComprovanteMulta(id) {
            const lb  = document.getElementById('vm-lightbox');
            const img = document.getElementById('vm-lb-img');
            const ld  = document.getElementById('vm-lb-loading');
            img.style.display = 'none'; ld.style.display = 'flex';
            lb.style.display  = 'flex';
            const d = await api(`ajax_action=multa_ver_comprovante&id=${id}`);
            ld.style.display = 'none';
            if (d.sucesso) { img.src = `data:${d.mime};base64,${d.imagem}`; img.style.display = 'block'; }
            else { toast('❌ ' + (d.erro || 'Não encontrado'), 'error'); lb.style.display = 'none'; }
        }

        async function validarMulta(id, nome) {
            vpConfirm('Aprovar Pagamento de Multa',
                `Confirmar pagamento da multa de <strong>${escHtml(nome)}</strong>? O cronômetro será encerrado.`,
                '#16a34a', '<i class="fas fa-check"></i>',
                async () => {
                    const d = await api(`ajax_action=multa_validar&id=${id}`);
                    if (d.sucesso) { toast('✅ Multa validada como paga!', 'success'); carregarMultasPendentesAdm(); atualizarStats(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        async function negarMulta(id, nome) {
            vpConfirm('Negar Comprovante de Multa',
                `Negar o comprovante de <strong>${escHtml(nome)}</strong>? O cronômetro voltará a contar normalmente.`,
                '#dc2626', '<i class="fas fa-times"></i>',
                async () => {
                    const d = await api(`ajax_action=multa_negar&id=${id}`);
                    if (d.sucesso) { toast('⚠️ Comprovante negado. Cronômetro retomado.', 'info'); carregarMultasPendentesAdm(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        // ════════════════════════════════════════════
        // MODAL: LOGS DE MULTAS
        // ════════════════════════════════════════════
        function abrirModalLogsMultas() {
            document.getElementById('lm-busca').value = '';
            document.getElementById('modalLogsMultas').classList.remove('hidden');
            carregarLogsMultas(true);
        }
        function fecharModalLogsMultas() { document.getElementById('modalLogsMultas').classList.add('hidden'); }

        async function limparLogsNaoPagos() {
            vpConfirm('Limpar Logs Não Pagos',
                'Remove todos os logs de multas <strong>não pagas</strong>, suas blacklists e desbloqueia as contas. Use apenas para testes.',
                '#d97706', '<i class="fas fa-broom"></i>',
                async () => {
                    const d = await api('ajax_action=multa_limpar_nao_pagas');
                    if (d.sucesso) { toast(`🧹 ${d.total} log(s) não pago(s) removido(s)!`, 'success'); carregarLogsMultas(); atualizarStats(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        async function deletarMulta(id, nome) {
            vpConfirm('Deletar Multa',
                `Deletar a multa de <strong>${escHtml(nome)}</strong>? O cronômetro será cancelado e a multa removida sem aplicar penalidade.`,
                '#64748b', '<i class="fas fa-trash"></i>',
                async () => {
                    const d = await api(`ajax_action=multa_deletar&id=${id}`);
                    if (d.sucesso) { toast('🗑️ Multa deletada com sucesso!', 'success'); carregarMultasPendentesAdm(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        function editarMulta(id, nome, valor, linkAdv, prazoHoras) {
            document.getElementById('em-id').value       = id;
            document.getElementById('em-nome').textContent = nome;
            document.getElementById('em-valor').value    = parseFloat(valor).toFixed(2).replace('.',',');
            document.getElementById('em-link').value     = linkAdv;
            document.getElementById('em-prazo').value    = prazoHoras;
            document.getElementById('modalEditarMulta').classList.remove('hidden');
        }
        function fecharModalEditarMulta() { document.getElementById('modalEditarMulta').classList.add('hidden'); }

        async function salvarEdicaoMulta() {
            const id    = document.getElementById('em-id').value;
            const valor = document.getElementById('em-valor').value.replace(',','.');
            const link  = document.getElementById('em-link').value.trim();
            const prazo = document.getElementById('em-prazo').value;
            if (!valor || parseFloat(valor) <= 0) { toast('⚠️ Informe um valor válido', 'error'); return; }
            if (!link)  { toast('⚠️ Informe o link da advertência', 'error'); return; }
            const btn = document.getElementById('em-btn-salvar');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            const d = await api(`ajax_action=multa_editar&id=${id}&valor=${encodeURIComponent(valor)}&link_adv=${encodeURIComponent(link)}&prazo_horas=${prazo}`);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
            if (d.sucesso) {
                toast('✅ Multa atualizada!', 'success');
                fecharModalEditarMulta();
                carregarMultasPendentesAdm();
            } else toast('❌ ' + (d.erro || 'Falha'), 'error');
        }

        async function deletarLogMulta(id) {
            vpConfirm('Deletar Log de Multa',
                `Deletar este registro permanentemente do histórico? Esta ação não pode ser desfeita.`,
                '#64748b', '<i class="fas fa-trash"></i>',
                async () => {
                    const d = await api(`ajax_action=multa_log_deletar&id=${id}`);
                    if (d.sucesso) { toast('🗑️ Log deletado!', 'success'); carregarLogsMultas(); atualizarStats(); }
                    else toast('❌ ' + (d.erro || 'Falha'), 'error');
                }
            );
        }

        async function carregarLogsMultas(limpar) {
            if (limpar === true) document.getElementById('lm-busca').value = '';
            const busca = document.getElementById('lm-busca').value.trim();
            const lista = document.getElementById('lm-lista');
            lista.innerHTML = '<div style="text-align:center;padding:32px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
            const d = await api(`ajax_action=multa_logs&busca=${encodeURIComponent(busca)}`);
            document.getElementById('lm-total').textContent = d.total ? `${d.total} registro(s)` : 'Nenhum';

            if (!d.sucesso || !d.total) {
                lista.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-scroll" style="font-size:2rem;display:block;margin-bottom:10px;"></i><p>Nenhum registro encontrado.</p></div>';
                return;
            }

            lista.innerHTML = d.logs.map((m, idx) => {
                const num = idx + 1;
                const isPaga = m.status === 'paga';
                const statusHtml = isPaga
                    ? '<span class="badge badge-green"><i class="fas fa-check-circle"></i> Paga</span>'
                    : '<span class="badge badge-red"><i class="fas fa-times-circle"></i> Não Paga</span>';

                const prazoLabel = m.prazo_horas === 0 ? '1 minuto' :
                    m.prazo_horas < 24 ? `${m.prazo_horas}h` : `${m.prazo_horas/24} dia(s)`;

                const dtAplicada  = m.aplicada_em  ? new Date(m.aplicada_em).toLocaleString('pt-BR')  : '—';
                const dtValidada  = m.validada_em  ? new Date(m.validada_em).toLocaleString('pt-BR')  : '—';
                const dtExpira    = m.prazo_expira ? new Date(m.prazo_expira).toLocaleString('pt-BR') : '—';
                const rowBg       = isPaga ? '' : 'background:rgba(254,242,242,0.4);';

                const validInfo = isPaga ? `
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-top:8px;">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#15803d;margin-bottom:4px;"><i class="fas fa-user-check"></i> Validada por</div>
                        <div style="font-size:13px;font-weight:800;">${escHtml(m.validada_por || '—')}</div>
                        ${m.validada_por_rgpm ? `<div style="font-size:11px;color:var(--muted);">RGPM: ${escHtml(m.validada_por_rgpm)}</div>` : ''}
                        <div style="font-size:11px;color:var(--muted);margin-top:2px;"><i class="fas fa-clock"></i> ${dtValidada}</div>
                    </div>
                ` : `
                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-top:8px;">
                        <div style="font-size:11px;font-weight:700;color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> Multa não paga — prazo encerrado em ${dtExpira}.</div>
                    </div>
                `;

                return `<div style="padding:15px 20px;border-bottom:1px solid var(--border);${rowBg}">
                    <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:220px;">
                            <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:6px;">
                                <strong style="font-size:13px;">${escHtml(m.nome_aluno)}</strong>
                                ${statusHtml}
                                <span class="badge badge-gray" style="font-size:10px;">Multa #${num}</span>
                            </div>
                            <div style="font-size:11px;color:var(--muted);display:flex;flex-direction:column;gap:2px;">
                                <span><i class="fas fa-hashtag" style="width:14px;"></i> RGPM: <strong style="color:var(--text);">${escHtml(m.rgpm_aluno)}</strong></span>
                                <span><i class="fab fa-discord" style="width:14px;color:#7c3aed;"></i> ${escHtml(m.discord_aluno || '—')}</span>
                                <span><i class="fas fa-graduation-cap" style="width:14px;"></i> ${escHtml(m.curso_aluno || '—')}</span>
                                <span><i class="fas fa-coins" style="width:14px;color:#d97706;"></i> Valor: <strong style="color:#15803d;">${fmtMoeda(m.valor)}</strong></span>
                                <span><i class="fas fa-link" style="width:14px;color:#7c3aed;"></i> <a href="${escHtml(m.link_adv)}" target="_blank" style="color:#7c3aed;">Ver Advertência</a></span>
                                <span><i class="fas fa-clock" style="width:14px;"></i> Prazo: ${prazoLabel} · Expirava: ${dtExpira}</span>
                                <span><i class="fas fa-user-shield" style="width:14px;"></i> Aplicada por: ${escHtml(m.aplicada_por || '—')} em ${dtAplicada}</span>
                            </div>
                            ${validInfo}
                        </div>
                        <div style="display:flex;align-items:flex-start;padding-top:2px;">
                            ${isPaga ? `<button onclick="deletarLogMulta(${m.id})" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:5px;"><i class="fas fa-trash"></i> Deletar</button>` : `<span style="font-size:11px;color:#dc2626;font-weight:600;"><i class="fas fa-lock"></i> Remover via Blacklist</span>`}
                        </div>
                    </div>
                </div>`;
            }).join('');
        }
    </script>
    <!-- ══ MODAL APLICAR MULTA ═══════════════════════════════════════════════════ -->
    <div id="modalAplicarMulta" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:540px;">
            <div class="dec-modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca;">
                <div>
                    <div class="dec-modal-title" style="color:#b91c1c;"><i class="fas fa-gavel" style="margin-right:8px;"></i>Aplicar Multa</div>
                    <div class="dec-modal-sub" style="color:#ef4444;">Preencha todos os dados com atenção</div>
                </div>
                <button onclick="fecharModalAplicarMulta()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body">
                <!-- Busca aluno -->
                <div>
                    <label class="field-label">Buscar Aluno (Nome, RGPM ou Discord)</label>
                    <div style="display:flex;gap:8px;">
                        <input id="am-busca" type="text" placeholder="Nome, RGPM ou Discord..." class="dec-input" style="flex:1;"
                               onkeydown="if(event.key==='Enter')buscarAlunoMulta()" oninput="debounceMultaBusca()">
                        <button onclick="buscarAlunoMulta()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;"><i class="fas fa-search"></i></button>
                    </div>
                    <div id="am-resultados" style="display:none;max-height:180px;overflow-y:auto;background:var(--light);border:1px solid var(--border);border-radius:8px;margin-top:6px;"></div>
                </div>

                <!-- Form (oculto até selecionar aluno) -->
                <div id="am-form" style="display:none;flex-direction:column;gap:14px;">
                    <div id="am-aluno-badge" style="padding:10px 13px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;font-weight:700;color:var(--blue);"></div>

                    <div>
                        <label class="field-label">Valor da Multa (R$) <span style="color:#dc2626;">*</span></label>
                        <input id="am-valor" type="text" placeholder="0,00" class="dec-input" inputmode="numeric">
                    </div>

                    <div>
                        <label class="field-label">Link da Mensagem de Advertência <span style="color:#dc2626;">*</span></label>
                        <input id="am-link" type="url" placeholder="https://discord.com/channels/..." class="dec-input">
                        <div style="font-size:11px;color:var(--muted);margin-top:4px;"><i class="fas fa-info-circle" style="margin-right:3px;"></i>Cole o link da mensagem no Discord que justifica a multa.</div>
                    </div>

                    <div>
                        <label class="field-label">Prazo para Pagamento <span style="color:#dc2626;">*</span></label>
                        <select id="am-prazo" class="dec-select">
                            <option value="0">🧪 1 Minuto (Teste)</option>
                            <option value="24" selected>1 Dia</option>
                            <option value="48">2 Dias</option>
                            <option value="72">3 Dias</option>
                            <option value="96">4 Dias</option>
                            <option value="120">5 Dias</option>
                        </select>
                    </div>

                    <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:13px;">
                        <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:4px;"><i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i>Atenção</div>
                        <div style="font-size:11px;color:#78350f;line-height:1.6;">Se o prazo esgotar sem pagamento, o aluno será <strong>removido do curso</strong> e adicionado à <strong>blacklist</strong> automaticamente.</div>
                    </div>
                </div>
            </div>
            <div class="dec-modal-foot" style="display:flex;gap:10px;">
                <button onclick="fecharModalAplicarMulta()" class="btn-secondary" style="flex:1;">Cancelar</button>
                <button id="am-btn-confirmar" onclick="confirmarAplicarMulta()" class="btn-danger" style="flex:2;background:#dc2626;color:white;border:none;"><i class="fas fa-gavel"></i> Aplicar Multa</button>
            </div>
        </div>
    </div>

    <!-- ══ MODAL VALIDAR MULTAS ═══════════════════════════════════════════════════ -->
    <div id="modalValidarMulta" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:720px;">
            <div class="dec-modal-header" style="background:#fef3c7;border-bottom:1px solid #fde68a;">
                <div>
                    <div class="dec-modal-title" style="color:#92400e;"><i class="fas fa-check-double" style="margin-right:8px;"></i>Gerenciar Multas</div>
                    <div class="dec-modal-sub">Multas pendentes e aguardando validação</div>
                </div>
                <button onclick="fecharModalValidarMulta()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--light);display:flex;gap:8px;">
                <input id="vm-busca" type="text" placeholder="Buscar por nome ou RGPM..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter')carregarMultasPendentesAdm()">
                <button onclick="carregarMultasPendentesAdm()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;"><i class="fas fa-search"></i></button>
            </div>
            <div class="dec-modal-body" style="padding:0;max-height:60vh;overflow-y:auto;">
                <div id="vm-lista"></div>
            </div>
            <!-- Lightbox comprovante multa -->
            <div id="vm-lightbox" style="display:none;position:fixed;inset:0;background:rgba(5,10,20,.97);z-index:999999;flex-direction:column;align-items:center;justify-content:center;gap:16px;">
                <div id="vm-lb-loading" style="display:flex;flex-direction:column;align-items:center;gap:10px;color:rgba(255,255,255,.6);font-size:14px;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;"></i> Carregando...</div>
                <img id="vm-lb-img" src="" alt="" style="display:none;max-width:90vw;max-height:80vh;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.6);">
                <button onclick="document.getElementById('vm-lightbox').style.display='none';" class="lb-ctrl" style="background:rgba(220,38,38,.3);"><i class="fas fa-times"></i> Fechar</button>
            </div>
            <div class="dec-modal-foot" style="display:flex;justify-content:space-between;align-items:center;">
                <span id="vm-total" style="font-size:12px;color:var(--muted);"></span>
                <button onclick="fecharModalValidarMulta()" class="btn-secondary" style="width:auto;padding:8px 18px;">Fechar</button>
            </div>
        </div>
    </div>

    <!-- ══ MODAL LOGS DE MULTAS ═══════════════════════════════════════════════════ -->
    <!-- ══ MODAL EDITAR MULTA ═══════════════════════════════════════════════════ -->
    <div id="modalEditarMulta" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:480px;">
            <div class="dec-modal-header" style="background:#eff6ff;border-bottom:1px solid #bfdbfe;">
                <div>
                    <div class="dec-modal-title" style="color:#1e40af;"><i class="fas fa-edit" style="margin-right:8px;"></i>Editar Multa</div>
                    <div class="dec-modal-sub" id="em-nome" style="color:#1a56db;font-weight:700;"></div>
                </div>
                <button onclick="fecharModalEditarMulta()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body" style="display:flex;flex-direction:column;gap:16px;">
                <input type="hidden" id="em-id">
                <div>
                    <label class="field-label"><i class="fas fa-coins"></i> Valor (R$)</label>
                    <input type="text" id="em-valor" class="dec-input" placeholder="Ex: 150,00">
                </div>
                <div>
                    <label class="field-label"><i class="fas fa-link"></i> Link da Advertência</label>
                    <input type="url" id="em-link" class="dec-input" placeholder="https://...">
                </div>
                <div>
                    <label class="field-label"><i class="fas fa-clock"></i> Prazo de Pagamento</label>
                    <select id="em-prazo" class="dec-input">
                        <option value="0">Imediato (1 minuto)</option>
                        <option value="6">6 horas</option>
                        <option value="12">12 horas</option>
                        <option value="24">24 horas</option>
                        <option value="48">48 horas</option>
                        <option value="72">3 dias</option>
                        <option value="168">7 dias</option>
                    </select>
                    <div style="font-size:11px;color:#f59e0b;margin-top:5px;"><i class="fas fa-exclamation-triangle"></i> Alterar o prazo reinicia o cronômetro a partir de agora.</div>
                </div>
            </div>
            <div class="dec-modal-footer">
                <button onclick="fecharModalEditarMulta()" class="btn-secondary">Cancelar</button>
                <button id="em-btn-salvar" onclick="salvarEdicaoMulta()" style="background:#1a56db;color:#fff;border:none;border-radius:9px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                    <i class="fas fa-save"></i> Salvar Alterações
                </button>
            </div>
        </div>
    </div>

    <div id="modalLogsMultas" class="dec-modal-bd hidden">
        <div class="dec-modal" style="max-width:780px;">
            <div class="dec-modal-header" style="background:#fdf4ff;border-bottom:1px solid #e9d5ff;">
                <div>
                    <div class="dec-modal-title" style="color:#6b21a8;"><i class="fas fa-scroll" style="margin-right:8px;"></i>Logs de Multas</div>
                    <div class="dec-modal-sub">Histórico completo — multas pagas e não pagas</div>
                </div>
                <button onclick="fecharModalLogsMultas()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--light);display:flex;gap:8px;">
                <input id="lm-busca" type="text" placeholder="Buscar por nome, RGPM ou ID Discord..." class="dec-input" style="flex:1;" onkeydown="if(event.key==='Enter')carregarLogsMultas()">
                <button onclick="carregarLogsMultas()" style="padding:9px 14px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:13px;cursor:pointer;"><i class="fas fa-search"></i> Buscar</button>
                <button onclick="carregarLogsMultas(true)" style="padding:9px 12px;background:var(--light);color:var(--muted);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;" title="Limpar"><i class="fas fa-times"></i></button>
            </div>
            <div class="dec-modal-body" style="padding:0;max-height:60vh;overflow-y:auto;">
                <div id="lm-lista"></div>
            </div>
            <div class="dec-modal-foot" style="display:flex;justify-content:space-between;align-items:center;">
                <span id="lm-total" style="font-size:12px;color:var(--muted);"></span>
                <div style="display:flex;gap:8px;">
                    <button onclick="limparLogsNaoPagos()" style="padding:8px 14px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer;"><i class="fas fa-broom"></i> Limpar não pagas</button>
                    <button onclick="fecharModalLogsMultas()" class="btn-secondary" style="width:auto;padding:8px 18px;">Fechar</button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>