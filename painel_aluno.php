<?php
// Inicia sessão e configurações globais — sempre, antes de qualquer coisa
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
session_start();
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

// ── AJAX handler ─────────────────────────────────────────────────────────────
if (!empty($_POST['ajax_action'])) {
    // Limpa QUALQUER output acumulado (warnings, notices do PHP, BOM, etc.)
    while (ob_get_level()) ob_end_clean();
    ob_start(); // novo buffer limpo para capturar erros inesperados

    // Handler de exceção — garante JSON válido mesmo em crash
    set_exception_handler(function($e) {
        ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["sucesso" => false, "erro" => "Excecao: " . $e->getMessage()]);
        exit;
    });
    set_error_handler(function($errno, $errstr) {
        ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["sucesso" => false, "erro" => "PHP Error $errno: $errstr"]);
        exit;
    });

    // Função auxiliar — emite JSON e sai, descartando qualquer lixo acumulado
    function responderJSON(array $dados): void {
        // Remove handlers antes de sair para evitar dupla resposta
        restore_error_handler();
        restore_exception_handler();
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION["usuario"])) {
        responderJSON(["sucesso" => false, "erro" => "Nao autorizado"]);
    }

    require_once "conexao.php";

    if (!isset($conexao) || $conexao->connect_error) {
        responderJSON(["sucesso" => false, "erro" => "Falha na conexao com o banco de dados"]);
    }

    $conexao->set_charset("utf8mb4");
    // ── Verifica se sessão foi invalidada (mudança de nível pelo admin) ──────
    if (!empty($_SESSION['rgpm'])) {
        $chkSessCol = db_val($conexao, "SHOW COLUMNS FROM usuarios LIKE 'session_invalidada'");
        if ($chkSessCol !== null) {
            $invRow = db_row($conexao, "SELECT session_invalidada FROM usuarios WHERE rgpm = ? LIMIT 1", 's', [$_SESSION['rgpm']]);
            if ($invRow && (int)$invRow['session_invalidada'] === 1) {
                db_query($conexao, "UPDATE usuarios SET session_invalidada = 0 WHERE rgpm = ?", 's', [$_SESSION['rgpm']]);
                responderJSON(["sucesso" => false, "force_logout" => true]);
            }
        }
    }
    $acao = $_POST['ajax_action'];
    $uid  = intval($_SESSION["id"] ?? 0);

    if ($acao === "debug_sessao") {
        responderJSON(["session" => $_SESSION, "uid" => $uid]);
    }

    // ── VERIFICAR NÍVEL: compara sessão com banco ──────────────────────────
    if ($acao === 'verificar_nivel') {
        $nivelSessao = intval($_SESSION['nivel'] ?? 0);
        $rgpm = $_SESSION['rgpm'] ?? '';
        if (!$rgpm) { responderJSON(['logout' => true]); }
        $q = $conexao->prepare("SELECT nivel FROM usuarios WHERE rgpm=? LIMIT 1");
        $q->bind_param('s', $rgpm);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row) {
            // Usuário não encontrado no banco = conta foi excluída
            responderJSON(['logout' => true, 'conta_excluida' => true]);
        }
        if (intval($row['nivel']) !== $nivelSessao) {
            responderJSON(['logout' => true, 'conta_excluida' => false]);
        }
        // ── Verifica multa vencida não paga ──────────────────────────────
        $chkTabela = $conexao->query("SHOW TABLES LIKE 'multas'");
        if ($chkTabela && $chkTabela->num_rows > 0 && $uid) {
            $agora = date('Y-m-d H:i:s');
            $qm = $conexao->prepare("SELECT id FROM multas WHERE usuario_id=? AND status IN ('pendente','aguardando_validacao') AND prazo_expira<=? AND processada=0 LIMIT 1");
            $qm->bind_param('is', $uid, $agora);
            $qm->execute();
            $mulvaRow = $qm->get_result()->fetch_assoc();
            if ($mulvaRow) {
                // Processa TODAS as multas vencidas deste usuário
                $agora2 = date('Y-m-d H:i:s');
                $qVenc = $conexao->query("SELECT * FROM multas WHERE usuario_id={$uid} AND status IN ('pendente','aguardando_validacao') AND prazo_expira<='{$agora2}' AND processada=0");
                if ($qVenc) {
                    while ($mv = $qVenc->fetch_assoc()) {
                        $mid   = intval($mv['id']);
                        $nomeE = $conexao->real_escape_string($mv['nome_aluno']);
                        $rgpmE = $conexao->real_escape_string($mv['rgpm_aluno']);
                        $discE = $conexao->real_escape_string($mv['discord_aluno'] ?? '');
                        $motE  = $conexao->real_escape_string("Multa #{$mid} nao paga no prazo.");
                        $admE  = $conexao->real_escape_string($mv['aplicada_por'] ?? 'Sistema');
                        $expBl = date('Y-m-d H:i:s', strtotime('+30 days'));

                        // 1. Marca multa como não paga
                        $conexao->query("UPDATE multas SET status='nao_paga',processada=1 WHERE id={$mid}");

                        // 2. Adiciona à blacklist PERMANENTE (se ainda não estiver)
                        $chkBl2 = $conexao->query("SELECT id FROM blacklist WHERE rgpm='{$rgpmE}' LIMIT 1");
                        if ($chkBl2 && $chkBl2->num_rows === 0) {
                            $conexao->query("INSERT INTO blacklist (nome,rgpm,discord,motivo_tipo,motivo_texto,tempo,expiracao,adicionado_por) VALUES ('{$nomeE}','{$rgpmE}','{$discE}','texto','{$motE}','permanente',NULL,'{$admE}')");
                        }

                        // 3. Limpa dados do usuário mas mantém a conta bloqueada
                        $conexao->query("DELETE FROM pagamentos_pendentes WHERE usuario_id={$uid}");
                        $conexao->query("UPDATE usuarios SET meus_cursos='', status='Bloqueado', tags='[]' WHERE id={$uid}");
                    }
                }
                // Sinaliza para o frontend mostrar overlay de multa vencida
                responderJSON(['logout' => true, 'conta_excluida' => true, 'motivo' => 'multa_nao_paga']);
            }
        }
        responderJSON(['logout' => false]);
    }

    if ($acao === "carregar_dados") {
        if (!$uid) { responderJSON(["sucesso" => false, "erro" => "uid=0", "session_keys" => array_keys($_SESSION)]); }

        $q = $conexao->prepare("SELECT nome, rgpm, discord, status, meus_cursos, tags FROM usuarios WHERE id=? LIMIT 1");
        if (!$q) {
            $q = $conexao->prepare("SELECT nome, rgpm, discord, status, meus_cursos FROM usuarios WHERE id=? LIMIT 1");
            if (!$q) { responderJSON(["sucesso" => false, "erro" => "Prepare falhou: " . $conexao->error]); }
        }
        $q->bind_param("i", $uid); $q->execute();
        $u = $q->get_result()->fetch_assoc();
        if (!$u) { responderJSON(["sucesso" => false, "erro" => "Usuario id=$uid nao encontrado no banco"]); }
        if (!isset($u["tags"])) $u["tags"] = "";

        $tags = [];
        if (!empty($u['tags'])) {
            $dec  = json_decode($u['tags'], true);
            $tags = is_array($dec) ? $dec : array_values(array_filter(array_map('trim', explode(',', $u['tags']))));
        }

        $qp = $conexao->prepare("SELECT id, curso, status, data_envio, IFNULL(motivo_rejeicao,'') as motivo_rejeicao, IFNULL(tipo_pagamento,'taxa') as tipo_pagamento FROM pagamentos_pendentes WHERE usuario_id=? ORDER BY data_envio DESC");
        $qp->bind_param("i", $uid); $qp->execute();
        $pagamentos = $qp->get_result()->fetch_all(MYSQLI_ASSOC);

        $qc = $conexao->query("SELECT id, titulo, data_envio AS data_upload FROM cronogramas ORDER BY data_envio DESC");
        $cronogramas = [];
        if ($qc) while ($r = $qc->fetch_assoc()) $cronogramas[] = $r;

        $qcurso = $conexao->query("SELECT id, nome, descricao, tipo_curso, IFNULL(valor_taxa,0) as valor_taxa FROM cursos WHERE status='Aberto' ORDER BY nome ASC");
        $cursos = [];
        if ($qcurso) while ($r = $qcurso->fetch_assoc()) $cursos[] = $r;

        $presencas = [];
        $chkReg = $conexao->query("SHOW TABLES LIKE 'registros_presenca'");
        if ($chkReg && $chkReg->num_rows > 0) {
            $qatas = $conexao->query("SELECT id, nome_referencia AS titulo, data_registro AS data_upload FROM registros_presenca ORDER BY data_registro DESC");
            if ($qatas) while ($r = $qatas->fetch_assoc()) {
                $r['tipo'] = 'ata';
                $presencas[] = $r;
            }
        }
        $chkPArq = $conexao->query("SHOW TABLES LIKE 'presenca_arquivos'");
        if ($chkPArq && $chkPArq->num_rows > 0) {
            $qpres = $conexao->query("SELECT id, titulo, arquivo, data_upload FROM presenca_arquivos ORDER BY data_upload DESC");
            if ($qpres) while ($r = $qpres->fetch_assoc()) {
                $r['tipo'] = 'arquivo';
                $presencas[] = $r;
            }
        }
        usort($presencas, function($a, $b) {
            return strtotime($b['data_upload']) - strtotime($a['data_upload']);
        });

        $notif = null;
        $qn = $conexao->prepare("SELECT id, curso, status FROM pagamentos_pendentes WHERE usuario_id=? AND status IN ('validado','rejeitado') AND (notificado IS NULL OR notificado=0) ORDER BY id DESC LIMIT 1");
        $qn->bind_param("i", $uid); $qn->execute();
        $rowN = $qn->get_result()->fetch_assoc();
        if ($rowN) {
            $upd = $conexao->prepare("UPDATE pagamentos_pendentes SET notificado=1 WHERE id=?");
            $notif_id = intval($rowN['id']); $upd->bind_param("i", $notif_id); $upd->execute();
            $notif = ["status" => $rowN['status'], "curso" => $rowN['curso']];
        }

        // Verifica se taxa do CURSO foi paga — ignora pagamentos de multa
        $taxaPagaBanco = false;
        foreach ($pagamentos as $pg) {
            if ($pg['status'] === 'validado' && ($pg['tipo_pagamento'] ?? 'taxa') === 'taxa') {
                $taxaPagaBanco = true;
                break;
            }
        }

        responderJSON([
            "sucesso"     => true,
            "nome"        => $u['nome'],
            "rgpm"        => $u['rgpm'],
            "discord"     => $u['discord'] ?? '—',
            "status"      => $u['status'],
            "meus_cursos" => trim($u['meus_cursos'] ?? ''),
            "tags"        => $tags,
            "taxa_paga"   => $taxaPagaBanco,
            "pagamentos"  => $pagamentos,
            "cronogramas" => $cronogramas,
            "cursos"      => $cursos,
            "presencas"   => $presencas,
            "notificacao" => $notif
        ]);
    }

    if ($acao === 'enviar_pagamento') {
        $curso  = trim($_POST['curso'] ?? '');
        $uNome  = trim($_SESSION["usuario"] ?? '');
        $uRgpm  = trim($_SESSION["rgpm"] ?? '');
        $uDiscord = '';

        if (!$uid || !$curso) {
            responderJSON(["sucesso" => false, "erro" => "Dados inválidos"]);
        }

        $dq = $conexao->prepare("SELECT discord, status, meus_cursos FROM usuarios WHERE id=? LIMIT 1");
        $dq->bind_param("i", $uid); $dq->execute();
        $dr = $dq->get_result()->fetch_assoc();
        $uDiscord = $dr['discord'] ?? '';

        if (!empty($dr['meus_cursos'])) {
            responderJSON(["sucesso" => false, "erro" => "Seu comprovante para o curso " . $dr['meus_cursos'] . " já foi aprovado. Não é possível enviar novo comprovante."]);
            exit;
        }

        $chk = $conexao->prepare("SELECT id FROM pagamentos_pendentes WHERE usuario_id=? AND status='pendente'");
        $chk->bind_param("i", $uid); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            responderJSON(["sucesso" => false, "erro" => "Você já possui um pagamento pendente. Aguarde a validação."]);
            exit;
        }

        if (empty($_FILES['comprovante']['tmp_name'])) {
            responderJSON(["sucesso" => false, "erro" => "Arquivo não recebido"]);
        }

        $tmpPath = $_FILES['comprovante']['tmp_name'];
        $mimeRaw = mime_content_type($tmpPath);
        $mime = strtolower(trim(explode(';', $mimeRaw)[0]));
        $tiposAceitos = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];

        // Carrega via GD — converte qualquer formato para JPEG
        $gdImg = @imagecreatefromstring(file_get_contents($tmpPath));
        if (!$gdImg && !in_array($mime, $tiposAceitos)) {
            responderJSON(["sucesso" => false, "erro" => "Formato de imagem não suportado. Use PNG, JPG ou WEBP."]);
        }
        if (!$gdImg) {
            responderJSON(["sucesso" => false, "erro" => "Não foi possível ler a imagem. Tente converter para JPG antes de enviar."]);
        }

        // ── Redimensiona para caber no max_allowed_packet do MySQL ──────────
        // Host compartilhado geralmente tem max_allowed_packet=1MB, então visamos ~500KB
        $maxLarg = 900;
        $origW = imagesx($gdImg);
        $origH = imagesy($gdImg);

        if ($origW > $maxLarg) {
            $novaH = intval($origH * $maxLarg / $origW);
            $novaImg = imagecreatetruecolor($maxLarg, $novaH);
            // Preserva transparência se houver
            imagealphablending($novaImg, false);
            imagesavealpha($novaImg, true);
            imagecopyresampled($novaImg, $gdImg, 0, 0, 0, 0, $maxLarg, $novaH, $origW, $origH);
            imagedestroy($gdImg);
            $gdImg = $novaImg;
        }

        // Salva como JPEG com qualidade progressivamente menor até caber em 900KB
        $imgData = null;
        $mime = 'image/jpeg';
        foreach ([80, 65, 50, 38, 28] as $qualidade) {
            ob_start();
            imagejpeg($gdImg, null, $qualidade);
            $tentativa = ob_get_clean();
            $imgData = $tentativa;
            if (strlen($tentativa) <= 500000) break; // <= ~500KB, seguro pro MySQL 1MB limit
        }
        imagedestroy($gdImg);

        if (!$imgData) {
            responderJSON(["sucesso" => false, "erro" => "Falha ao processar imagem"]);
        }

        $stmt = $conexao->prepare("INSERT INTO pagamentos_pendentes (usuario_id, nome, rgpm, discord, curso, comprovante, mime_type, data_envio, status, notificado) VALUES (?,?,?,?,?,?,?,NOW(),'pendente',0)");
        $stmt->bind_param("issssss", $uid, $uNome, $uRgpm, $uDiscord, $curso, $imgData, $mime);

        if ($stmt->execute()) responderJSON(["sucesso" => true]);
        else responderJSON(["sucesso" => false, "erro" => "Erro ao salvar: " . $stmt->error]);
        exit;
    }

    if ($acao === 'cancelar_inscricao') {
        $chk = $conexao->prepare("SELECT id FROM pagamentos_pendentes WHERE usuario_id=? AND status='pendente' LIMIT 1");
        $chk->bind_param("i", $uid); $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row) {
            responderJSON(["sucesso" => false, "erro" => "Nenhuma inscrição pendente encontrada"]);
        }
        $del = $conexao->prepare("DELETE FROM pagamentos_pendentes WHERE id=?");
        $del_id = intval($row['id']); $del->bind_param("i", $del_id); $del->execute();
        responderJSON(["sucesso" => true]);
    }

    // ── MULTAS DO ALUNO ──────────────────────────────────────────────────────
    if (in_array($acao, ['multa_listar_aluno','multa_enviar_comprovante','multa_ver_comprovante'], true)) {
        require_once __DIR__ . '/multas_aluno_actions.php';
        exit;
    }

    responderJSON(["sucesso" => false, "erro" => "Ação desconhecida"]);
}

if (!isset($_SESSION["usuario"])) { header("Location: login.php"); exit; }

$usuNome = $_SESSION["usuario"] ?? 'Usuário';
$usuId   = intval($_SESSION["id"] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Painel DEC · Aluno</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
  --navy:#0d1b2e;--blue:#1a56db;--blue2:#1e40af;
  --bg:#f1f5f9;--white:#fff;--border:#e2e8f0;
  --text:#1e293b;--muted:#64748b;--light:#f8fafc;
  --red:#dc2626;--red-p:#fef2f2;
  --green:#15803d;--green-p:#dcfce7;
  --yp:#fef3c7;--yc:#92400e;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);font-family:'Inter',sans-serif;color:var(--text);min-height:100vh;}
.app-wrap{display:flex;height:100vh;overflow:hidden;}
.sidebar{width:255px;background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;box-shadow:4px 0 20px rgba(0,0,0,.25);z-index:100;transition:transform .3s ease;}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:12px;}
.brand{font-size:16px;font-weight:800;color:#fff;font-family:'Sora',sans-serif;} .brand span{color:#60a5fa;}
.sub{font-size:10px;color:rgba(255,255,255,.35);font-weight:500;letter-spacing:.03em;margin-top:2px;}
.sidebar nav{flex:1;overflow-y:auto;padding:12px 0;}
.nav-section{padding:14px 20px 5px;font-size:9.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.25);}
.nav-link{display:flex;align-items:center;gap:11px;padding:10px 20px;margin:1px 10px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.5);font-size:13px;font-weight:500;transition:all .18s;}
.nav-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.06);font-size:12px;flex-shrink:0;}
.nav-link:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85);}
.nav-link.active{background:var(--blue);color:#fff;box-shadow:0 4px 14px rgba(26,86,219,.4);}
.nav-link.active .nav-icon{background:rgba(255,255,255,.2);}
.sidebar-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);font-size:10px;color:rgba(255,255,255,.2);}
.main-wrap{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
header{height:60px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 20px;flex-shrink:0;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.header-left{display:flex;align-items:center;gap:10px;}
.hamburger{display:none;background:none;border:none;cursor:pointer;padding:6px;border-radius:8px;color:var(--text);font-size:18px;}
.header-title{font-size:15px;font-weight:700;color:var(--text);}
.header-right{display:flex;align-items:center;gap:8px;}
.user-chip{display:flex;align-items:center;gap:8px;background:var(--light);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;color:var(--text);}
.avatar{width:28px;height:28px;background:var(--blue);border-radius:6px;display:flex;align-items:center;justify-content:center;color:white;font-size:11px;font-weight:700;flex-shrink:0;}
.btn-logout{display:flex;align-items:center;gap:6px;background:var(--red-p);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:600;text-decoration:none;transition:all .18s;}
.btn-logout:hover{background:#fee2e2;}
#viewport{flex:1;overflow-y:auto;background:var(--bg);padding:20px;scrollbar-width:thin;}
.section-view{display:none;} .section-view.active{display:block;animation:fadeUp .25s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);background:var(--light);}
.ch-left{display:flex;align-items:center;gap:12px;}
.ch-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:13px;flex-shrink:0;background:var(--blue);}
.ch-icon.green{background:#16a34a;} .ch-icon.amber{background:#d97706;} .ch-icon.purple{background:#7c3aed;}
.ch-title{font-size:14px;font-weight:700;color:var(--text);}
.ch-sub{font-size:11px;color:var(--muted);margin-top:1px;}
.card-body{padding:20px;}
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);border-radius:10px;overflow:hidden;margin-bottom:16px;}
.info-cell{background:var(--white);padding:14px 18px;}
.ic-label{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.ic-value{font-size:14px;font-weight:700;color:var(--text);word-break:break-all;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:var(--green-p);color:var(--green);border:1px solid #bbf7d0;}
.badge-blue{background:#dbeafe;color:var(--blue2);border:1px solid #bfdbfe;}
.badge-red{background:var(--red-p);color:var(--red);border:1px solid #fecaca;}
.badge-yellow{background:var(--yp);color:var(--yc);border:1px solid #fde68a;}
.badge-gray{background:var(--light);color:var(--muted);border:1px solid var(--border);}
.dec-select{width:100%;padding:9px 32px 9px 13px;border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;color:var(--text);background:var(--white);outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;transition:border-color .18s;}
.dec-select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.1);}
.dec-select:disabled{background-color:#f1f5f9;color:var(--muted);cursor:not-allowed;}
.field-label{display:block;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;width:100%;}
.btn-primary:hover{background:var(--blue2);}
.btn-primary:disabled{opacity:.55;cursor:not-allowed;}
.btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;background:var(--red-p);color:var(--red);border:1px solid #fecaca;border-radius:8px;font-family:'Inter',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:all .18s;}
.btn-danger:hover{background:#fee2e2;}
.btn-dl{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#1a56db,#1e40af);color:#fff;border:none;border-radius:9px;padding:9px 16px;font-size:12px;font-weight:700;text-decoration:none;transition:all .22s;box-shadow:0 2px 8px rgba(26,86,219,.25);}
.btn-dl:hover{background:linear-gradient(135deg,#1e40af,#1a56db);box-shadow:0 4px 16px rgba(26,86,219,.35);transform:translateY(-1px);}
.btn-dl i{font-size:11px;}
.upload-area{border:2px dashed var(--border);border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;background:var(--light);position:relative;}
.upload-area:hover,.upload-area.drag-over{border-color:var(--blue);background:#f0f7ff;}
.upload-area.has-file{border-color:var(--blue);border-style:solid;background:#f0f7ff;box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.upload-icon{font-size:2rem;color:#93c5fd;margin-bottom:10px;}
.upload-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px;}
.upload-sub{font-size:12px;color:var(--muted);}
.paste-hint{margin-top:10px;font-size:11px;color:var(--blue);font-weight:600;background:#dbeafe;padding:6px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;}
.preview-img{max-width:100%;max-height:220px;border-radius:9px;margin-top:12px;border:1px solid var(--border);display:none;}
.dec-table{width:100%;border-collapse:collapse;font-size:13px;}
.dec-table th{padding:10px 14px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);background:var(--light);}
.dec-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:var(--muted);}
.dec-table td.strong{font-weight:600;color:var(--text);}
.dec-table tr:last-child td{border-bottom:none;}
.dec-table tbody tr:hover td{background:#f8fafc;}
.alerta{border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:10px;font-size:13px;margin-bottom:14px;}
.alerta-pendente{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
.alerta-aprovado{background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;}
.alerta-bloqueio{background:#fee2e2;border:1px solid #fecaca;color:#dc2626;}
.sync-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:4px;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.25;}}
.empty-state{text-align:center;padding:40px;color:var(--muted);}
.empty-state i{font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;}
.empty-state p{font-size:14px;}
.toast{position:fixed;top:20px;right:20px;z-index:9000;width:auto;max-width:360px;background:var(--white);border:1px solid var(--border);border-radius:12px;padding:12px 16px;box-shadow:0 8px 30px rgba(0,0,0,.15);display:inline-flex;align-items:center;gap:10px;transform:translateX(calc(100% + 40px));transition:transform .35s cubic-bezier(.2,.8,.2,1);pointer-events:none;}
.toast.show{transform:translateX(0);pointer-events:all;}
.toast-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.ti-success{background:#dcfce7;color:#16a34a;} .ti-error{background:#fee2e2;color:#dc2626;} .ti-info{background:#dbeafe;color:#1a56db;} .ti-warn{background:#fef3c7;color:#d97706;}
.toast-title{font-size:13px;font-weight:700;color:var(--text);} .toast-msg{font-size:12px;color:var(--muted);margin-top:2px;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;}
.skel{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:skel 1.4s infinite;border-radius:6px;}
@keyframes skel{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
.cron-grid{display:flex;flex-direction:column;gap:12px;}
.cron-card{background:var(--white);border:1px solid var(--border);border-radius:14px;display:flex;align-items:stretch;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.05);transition:box-shadow .22s, transform .22s;position:relative;}
.cron-card:hover{box-shadow:0 6px 24px rgba(26,86,219,.12);transform:translateY(-2px);}
.cron-card-stripe{width:5px;background:linear-gradient(180deg,#1a56db,#7c3aed);flex-shrink:0;}
.cron-card-icon{width:52px;display:flex;align-items:center;justify-content:center;background:#f0f7ff;border-right:1px solid #e0eeff;flex-shrink:0;}
.cron-card-icon i{font-size:20px;color:#1a56db;}
.cron-card-body{flex:1;padding:14px 18px;display:flex;flex-direction:column;gap:5px;min-width:0;}
.cron-card-title{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'Sora',sans-serif;}
.cron-card-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.cron-meta-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);font-weight:500;}
.cron-meta-item i{font-size:10px;color:#94a3b8;}
.cron-card-actions{display:flex;align-items:center;padding:12px 16px;flex-shrink:0;}
.cron-badge-new{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;margin-left:8px;vertical-align:middle;}
.curso-select-wrapper{position:relative;}
.curso-select-trigger{width:100%;padding:12px 42px 12px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:'Inter',sans-serif;font-size:13.5px;color:var(--text);background:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:space-between;transition:border-color .18s, box-shadow .18s;user-select:none;}
.curso-select-trigger:hover{border-color:#94a3b8;}
.curso-select-trigger.open{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.1);border-radius:10px 10px 0 0;}
.curso-select-trigger-text{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.curso-select-trigger-text.placeholder{color:var(--muted);}
.curso-select-arrow{width:20px;height:20px;border-radius:5px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--muted);flex-shrink:0;transition:transform .2s;}
.curso-select-trigger.open .curso-select-arrow{transform:rotate(180deg);}
.curso-dropdown{position:absolute;top:100%;left:0;right:0;background:var(--white);border:1.5px solid var(--blue);border-top:none;border-radius:0 0 12px 12px;box-shadow:0 12px 32px rgba(0,0,0,.12);z-index:500;max-height:320px;overflow-y:auto;display:none;animation:dropFade .15s ease;}
.curso-dropdown.open{display:block;}
@keyframes dropFade{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
.curso-option{padding:13px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .14s;display:flex;align-items:center;gap:13px;}
.curso-option:last-child{border-bottom:none;}
.curso-option:hover{background:#f8fafc;}
.curso-option.selected{background:#f0f7ff;}
.curso-opt-icon{width:38px;height:38px;border-radius:9px;background:linear-gradient(135deg,#dbeafe,#e0e7ff);display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--blue);flex-shrink:0;}
.curso-opt-body{flex:1;min-width:0;}
.curso-opt-nome{font-size:13.5px;font-weight:700;color:var(--text);margin-bottom:2px;}
.curso-opt-desc{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.curso-opt-taxa{font-size:12px;font-weight:800;color:#15803d;background:#dcfce7;border:1px solid #bbf7d0;border-radius:7px;padding:3px 9px;white-space:nowrap;flex-shrink:0;}
.curso-opt-taxa.gratuito{color:#1e40af;background:#dbeafe;border-color:#bfdbfe;}
.curso-info-box{background:linear-gradient(135deg,#f0f7ff,#f5f3ff);border:1px solid #c7d7f9;border-radius:11px;padding:14px 16px;display:flex;align-items:center;gap:13px;animation:fadeUp .2s ease;}
.curso-info-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#1a56db,#7c3aed);display:flex;align-items:center;justify-content:center;color:white;font-size:16px;flex-shrink:0;}
.curso-info-nome{font-size:14px;font-weight:700;color:var(--text);}
.curso-info-taxa{font-size:12px;font-weight:700;color:#15803d;margin-top:2px;}
.curso-info-desc{font-size:11.5px;color:var(--muted);margin-top:2px;}
.curso-bloqueado-box{background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:12px;}
.curso-bloqueado-box i{color:#1a56db;font-size:1.1rem;flex-shrink:0;}
.curso-bloqueado-nome{font-size:13.5px;font-weight:700;color:var(--text);}
.curso-bloqueado-hint{font-size:11px;color:var(--muted);margin-top:1px;}
.mc-hero{background:linear-gradient(135deg,#0d1b2e 0%,#1a3a6b 100%);border-radius:14px;padding:22px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px;position:relative;overflow:hidden;}
.mc-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04);}
.mc-hero::after{content:'';position:absolute;bottom:-60px;right:60px;width:120px;height:120px;border-radius:50%;background:rgba(96,165,250,.07);}
.mc-hero-left{display:flex;align-items:center;gap:14px;z-index:1;}
.mc-hero-avatar{width:52px;height:52px;border-radius:13px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px;color:#93c5fd;flex-shrink:0;}
.mc-hero-title{font-family:'Sora',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:4px;}
.mc-hero-status{display:flex;align-items:center;gap:6px;}
.mc-hero-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.mc-hero-badge.green{background:rgba(21,128,61,.3);color:#86efac;border:1px solid rgba(21,128,61,.4);}
.mc-hero-badge.blue{background:rgba(26,86,219,.3);color:#93c5fd;border:1px solid rgba(26,86,219,.4);}
.mc-tags-section{margin-top:4px;}
.mc-tags-label{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.mc-tags-grid{display:flex;flex-wrap:wrap;gap:8px;}
.mc-tag{display:flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;font-size:12px;font-weight:600;transition:all .18s;position:relative;overflow:hidden;}
.mc-tag-aula{background:#f0f7ff;border:1px solid #c7d7f9;color:#1e40af;}
.mc-tag-pagou{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;}
.mc-tag-especial{background:#fdf4ff;border:1px solid #e9d5ff;color:#7c3aed;}
.mc-tag-neutro{background:#f8fafc;border:1px solid var(--border);color:var(--muted);}
.mc-tag i{font-size:10px;flex-shrink:0;}
.mc-tag-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;}
.mc-pago-chip{display:flex;align-items:center;gap:6px;background:rgba(21,128,61,.15);border:1px solid rgba(134,239,172,.3);border-radius:8px;padding:8px 13px;margin-bottom:14px;font-size:12px;font-weight:700;color:#86efac;z-index:1;position:relative;}
.pres-stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:13px;box-shadow:0 1px 5px rgba(0,0,0,.05);}
.pres-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.pres-stat-value{font-size:20px;font-weight:800;color:var(--text);font-family:'Sora',sans-serif;line-height:1;}
.pres-stat-label{font-size:11px;color:var(--muted);margin-top:3px;font-weight:500;}
@media(max-width:768px){
  .sidebar{position:fixed;top:0;left:0;height:100%;transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .sidebar-overlay.active{display:block;}
  .hamburger{display:flex!important;align-items:center;justify-content:center;}
  #viewport{padding:14px;}
  .info-grid{grid-template-columns:1fr 1fr;}
  .chip-name{display:none;}
  header{padding:0 14px;}
  .dec-table th,.dec-table td{padding:9px 10px;}
  .cron-card-title{font-size:13px;}
  .mc-hero{padding:16px;}
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sb-overlay" onclick="closeSidebar()"></div>
<div class="app-wrap">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div style="width:40px;height:40px;background:var(--blue);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-shield-alt" style="color:white;font-size:16px;"></i>
    </div>
    <div><div class="brand">DEC <span>PMESP</span></div><div class="sub">PAINEL DO ALUNO</div></div>
  </div>
  <nav>
    <div class="nav-section">Geral</div>
    <div class="nav-link active" onclick="router('perfil')">
      <div class="nav-icon"><i class="fas fa-user"></i></div>Dashboard
    </div>
    <div class="nav-link" onclick="router('meu-curso')">
      <div class="nav-icon"><i class="fas fa-graduation-cap"></i></div>Curso
    </div>
    <div class="nav-section" id="nav-sec-atividades" style="display:none;">Atividades</div>
    <div class="nav-link" id="nav-pagamento" onclick="router('pagamento')" style="display:none;">
      <div class="nav-icon"><i class="fas fa-receipt"></i></div>Pagamento
    </div>
    <div class="nav-link" id="nav-presenca" onclick="router('presenca')" style="display:none;">
      <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>Presença
    </div>
    <div class="nav-link" id="nav-escala" onclick="router('escala')" style="display:none;">
      <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>Cronogramas
    </div>
    <div class="nav-link" id="nav-multas" onclick="router('multas')" style="display:none;">
      <div class="nav-icon" style="background:rgba(220,38,38,.25);"><i class="fas fa-gavel" style="color:#ef4444;"></i></div><span style="color:rgba(255,255,255,.8);">Multas</span><span id="nav-multas-badge" style="display:none;margin-left:auto;background:#dc2626;color:#fff;border-radius:20px;font-size:10px;font-weight:800;padding:2px 7px;"></span>
    </div>
  </nav>
  <div class="sidebar-footer">© DEC PMESP · Painel do Aluno</div>
</aside>
<div class="main-wrap">
  <header>
    <div class="header-left">
      <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <div class="header-title" id="view-title">Meu Perfil</div>
    </div>
    <div class="header-right">
      <div class="user-chip">
        <div class="avatar" id="hdr-avatar"><?= strtoupper(substr($usuNome,0,2)) ?></div>
        <span class="chip-name" id="hdr-nome"><?= htmlspecialchars($usuNome) ?></span>
      </div>
      <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i><span class="chip-name">Sair</span></a>
    </div>
  </header>
  <div id="viewport">
    <div id="perfil" class="section-view active">
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon"><i class="fas fa-user"></i></div>
            <div><div class="ch-title">Meu Perfil</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div>
          </div>
          <span class="badge badge-blue" id="perfil-badge">—</span>
        </div>
        <div class="card-body">
          <div class="info-grid">
            <div class="info-cell"><div class="ic-label">Nome</div><div class="ic-value" id="p-nome"><div class="skel" style="height:18px;width:80%;"></div></div></div>
            <div class="info-cell"><div class="ic-label">RGPM</div><div class="ic-value" id="p-rgpm"><div class="skel" style="height:18px;width:60%;"></div></div></div>
            <div class="info-cell"><div class="ic-label">Discord</div><div class="ic-value" id="p-discord"><div class="skel" style="height:18px;width:70%;"></div></div></div>
          </div>
        </div>
      </div>
    </div>
    <div id="meu-curso" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon green"><i class="fas fa-graduation-cap"></i></div><div><div class="ch-title">Meu Curso</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div></div>
        </div>
        <div class="card-body" id="curso-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
      <!-- Matricula: exibida apenas quando sem curso -->
      <div id="card-matricula" class="card" style="display:none;">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon" style="background:linear-gradient(135deg,#1a56db,#7c3aed);"><i class="fas fa-plus-circle"></i></div><div><div class="ch-title">Inscrever-se em um Curso</div><div class="ch-sub">Cursos abertos disponíveis</div></div></div>
        </div>
        <div class="card-body" id="matricula-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
    </div>
    <div id="pagamento" class="section-view">
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon amber"><i class="fas fa-receipt"></i></div><div><div class="ch-title">Enviar Comprovante</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div></div>
        </div>
        <div class="card-body" id="pag-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon purple"><i class="fas fa-history"></i></div><div><div class="ch-title">Histórico de Pagamentos</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div></div>
        </div>
        <div id="historico-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
    </div>
    <div id="presenca" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490);"><i class="fas fa-clipboard-list"></i></div><div><div class="ch-title">Arquivos de Presença</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div></div>
          <span id="pres-count-badge" style="display:none;" class="badge badge-blue"></span>
        </div>
        <div class="card-body" id="presenca-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
    </div>
    <div id="escala" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left"><div class="ch-icon" style="background:linear-gradient(135deg,#1a56db,#7c3aed);"><i class="fas fa-calendar-alt"></i></div><div><div class="ch-title">Cronogramas</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div></div>
          <span id="cron-count-badge" style="display:none;" class="badge badge-blue"></span>
        </div>
        <div class="card-body" id="cronogramas-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
    </div>
    <div id="multas" class="section-view">
      <div id="aviso-multas" style="display:none;align-items:flex-start;gap:10px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:12px;padding:14px 16px;margin-bottom:14px;">
        <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1.1rem;flex-shrink:0;margin-top:1px;"></i>
        <div>
          <div style="font-weight:800;color:#92400e;font-size:13px;margin-bottom:3px;">Atenção!</div>
          <div style="font-size:12px;color:#78350f;line-height:1.6;">O não pagamento da multa dentro do prazo estipulado resultará no <strong>bloqueio permanente da sua conta</strong>. Pague dentro do prazo para evitar penalidades.</div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon" style="background:linear-gradient(135deg,#dc2626,#991b1b);"><i class="fas fa-gavel"></i></div>
            <div><div class="ch-title">Minhas Multas</div><div class="ch-sub"><span class="sync-dot"></span>Sincronização automática</div></div>
          </div>
          <span id="multas-count-badge" style="display:none;" class="badge" style="background:#dc2626;color:#fff;"></span>
        </div>
        <div class="card-body" id="multas-body"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Carregando...</p></div></div>
      </div>
    </div>
  </div>
</div>
</div>
<!-- MODAL ENVIAR COMPROVANTE DE MULTA -->
<div id="modal-multa-comp" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:#fef2f2;border-bottom:1px solid #fecaca;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:15px;font-weight:800;color:#b91c1c;"><i class="fas fa-file-upload" style="margin-right:8px;"></i>Enviar Comprovante</div>
        <div style="font-size:12px;color:#ef4444;margin-top:2px;">Envie o comprovante de pagamento da multa</div>
      </div>
      <button onclick="fecharModalMultaComp()" style="background:none;border:none;cursor:pointer;font-size:18px;color:#94a3b8;"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
      <div id="mmc-info" style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:13px;"></div>
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px;">Comprovante (imagem)</label>
        <input type="file" id="mmc-file" accept="image/*" style="display:none" onchange="mmcHandleFile(this)">
        <div id="mmc-upload-area" onclick="document.getElementById('mmc-file').click()" style="border:2px dashed #fca5a5;border-radius:12px;padding:28px 16px;text-align:center;cursor:pointer;background:#fff5f5;transition:border-color .2s;">
          <div id="mmc-placeholder"><i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:#ef4444;margin-bottom:8px;display:block;"></i><div style="font-weight:700;color:#b91c1c;font-size:14px;">Clique para selecionar</div><div style="font-size:12px;color:#ef4444;margin-top:4px;">PNG, JPG, JPEG, WEBP</div></div>
          <div id="mmc-preview-wrap" style="display:none;"><img id="mmc-preview-img" style="max-width:100%;max-height:200px;border-radius:10px;border:1px solid #fecaca;display:block;margin:0 auto;" alt="Preview"><div id="mmc-filename" style="font-size:12px;color:#dc2626;margin-top:8px;font-weight:600;"></div></div>
        </div>
      </div>
    </div>
    <div style="padding:0 20px 20px;display:flex;gap:10px;">
      <button onclick="fecharModalMultaComp()" style="flex:1;padding:11px;border:1px solid #e2e8f0;border-radius:9px;background:#fff;color:#64748b;font-weight:700;font-size:13px;cursor:pointer;font-family:Inter,sans-serif;">Cancelar</button>
      <button id="mmc-btn-enviar" onclick="mmcEnviar()" style="flex:2;padding:11px;border:none;border-radius:9px;background:#dc2626;color:#fff;font-weight:800;font-size:13px;cursor:pointer;font-family:Inter,sans-serif;"><i class="fas fa-paper-plane"></i> Enviar Comprovante</button>
    </div>
  </div>
</div>
<div class="toast" id="toast">
  <div class="toast-icon ti-info" id="toast-icon"><i id="toast-icone" class="fas fa-info-circle"></i></div>
  <div style="flex:1;"><div class="toast-title" id="toast-titulo"></div><div class="toast-msg" id="toast-msg"></div></div>
  <button onclick="fecharToast()" style="background:none;border:none;cursor:pointer;color:var(--muted);padding:2px;font-size:14px;align-self:flex-start;"><i class="fas fa-times"></i></button>
</div>
<script>
var _uid = <?= $usuId ?>;
var _estado = null;
var _hashUltimo = '';
var _fileParaEnviar = null;
var _enviando = false;
var _cursosDisponiveis = [];

// Se o UID mudou (novo login ou recadastro após exclusão), limpa estado anterior
var _uidSalvo = sessionStorage.getItem('dec_uid');
if (_uidSalvo && parseInt(_uidSalvo) !== _uid) {
  sessionStorage.removeItem('dec_abas');
  sessionStorage.removeItem('dec_curso');
}
sessionStorage.setItem('dec_uid', _uid);

// Restaura estado das abas do sessionStorage (mesmo UID = mesmo usuário recarregando)
var _abasBloqueadas = sessionStorage.getItem('dec_abas') === '1';
var _cursoEscolhido = sessionStorage.getItem('dec_curso') || null;

// Aplica imediatamente para evitar sumiço no reload
if (_abasBloqueadas) {
  ['nav-pagamento','nav-presenca','nav-escala','nav-sec-atividades','nav-multas'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.style.display = '';
  });
}

// ── FLAG para evitar múltiplos overlays de exclusão ───────────────────
var _contaExcluidaDetectada = false;
// ── Referências dos intervalos para poder parar ───────────────────────
var _pollingDados = null;
var _pollingNivel = null;

function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sb-overlay').classList.toggle('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sb-overlay').classList.remove('active'); }

var VIEW_TITLES = {perfil:'Dashboard','meu-curso':'Curso',pagamento:'Pagamento',presenca:'Presença',escala:'Cronogramas',multas:'Multas'};
function router(id){
  document.querySelectorAll('.section-view').forEach(function(s){ s.classList.remove('active'); });
  var sec = document.getElementById(id); if (sec) sec.classList.add('active');
  document.querySelectorAll('.nav-link').forEach(function(l){ l.classList.remove('active'); if ((l.getAttribute('onclick')||'').indexOf("'"+id+"'")>-1) l.classList.add('active'); });
  document.getElementById('view-title').textContent = VIEW_TITLES[id]||id;
  closeSidebar();
}

function atualizarNavSidebar(temCurso) {
  // Se abas foram ativadas manualmente (inscrição), não deixa polling esconder
  if (!temCurso && _abasBloqueadas) return;
  if (temCurso) {
    _abasBloqueadas = true;
    sessionStorage.setItem('dec_abas', '1');
  }
  var ids = ['nav-pagamento','nav-presenca','nav-escala','nav-sec-atividades','nav-multas'];
  ids.forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = temCurso ? '' : 'none';
  });
}

var _toastTimer = null;
function mostrarToast(titulo,msg,tipo){ tipo=tipo||'info'; var iconMap={success:'fa-check-circle',error:'fa-exclamation-circle',info:'fa-info-circle',warn:'fa-exclamation-triangle'}; var clsMap={success:'ti-success',error:'ti-error',info:'ti-info',warn:'ti-warn'}; document.getElementById('toast-titulo').textContent=titulo; document.getElementById('toast-msg').textContent=msg; document.getElementById('toast-icone').className='fas '+(iconMap[tipo]||'fa-info-circle'); document.getElementById('toast-icon').className='toast-icon '+(clsMap[tipo]||'ti-info'); document.getElementById('toast').classList.add('show'); clearTimeout(_toastTimer); _toastTimer=setTimeout(fecharToast,5500); }
function fecharToast(){ document.getElementById('toast').classList.remove('show'); }

var _audioCtx = null;
function getAudioCtx(){ if(!_audioCtx){try{_audioCtx=new(window.AudioContext||window.webkitAudioContext)();}catch(e){}} return _audioCtx; }
document.addEventListener('click',function(){ getAudioCtx(); },{once:true});
function tocarSomAprovado(){ var ctx=getAudioCtx();if(!ctx)return;var notas=[329.63,415.30,493.88],tempo=ctx.currentTime;notas.forEach(function(freq,i){var osc=ctx.createOscillator(),gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);osc.type='sine';osc.frequency.setValueAtTime(freq,tempo+i*0.12);gain.gain.setValueAtTime(0,tempo+i*0.12);gain.gain.linearRampToValueAtTime(0.28,tempo+i*0.12+0.02);gain.gain.exponentialRampToValueAtTime(0.001,tempo+i*0.12+0.9);osc.start(tempo+i*0.12);osc.stop(tempo+i*0.12+0.9);}); }
function tocarSomRejeitado(){ var ctx=getAudioCtx();if(!ctx)return;var notas=[220.00,164.81],tempo=ctx.currentTime;notas.forEach(function(freq,i){var osc=ctx.createOscillator(),gain=ctx.createGain();osc.connect(gain);gain.connect(ctx.destination);osc.type='triangle';osc.frequency.setValueAtTime(freq,tempo+i*0.18);gain.gain.setValueAtTime(0,tempo+i*0.18);gain.gain.linearRampToValueAtTime(0.22,tempo+i*0.18+0.02);gain.gain.exponentialRampToValueAtTime(0.001,tempo+i*0.18+0.55);osc.start(tempo+i*0.18);osc.stop(tempo+i*0.18+0.55);}); }

function iniciarPolling(){
  carregarDados();
  _pollingDados = setInterval(carregarDados, 5000);
}

function carregarDados(){
  fetch('painel_aluno.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=carregar_dados'})
  .then(function(r){return r.json();})
  .then(function(d){
    if(d.force_logout){window.location.href='logout.php';return;}
    if(!d.sucesso){console.warn("AJAX sucesso=false:",JSON.stringify(d));return;}
    var hash=JSON.stringify([d.status,d.meus_cursos,d.tags,d.pagamentos,d.cronogramas,d.cursos,d.presencas,d.nome,d.rgpm,d.discord]);
    var mudou=hash!==_hashUltimo; _hashUltimo=hash; _estado=d; _cursosDisponiveis=d.cursos||[];

    // Verifica inscrição real no banco — só libera abas se pagamento FOI APROVADO
    var temNoBanco = !!(d.meus_cursos) || !!(d.taxa_paga);
    // Só limpa sessionStorage se: banco não tem nada E não há curso escolhido localmente
    // Isso evita apagar o estado de quem escolheu curso mas ainda não enviou comprovante
    if (!temNoBanco && !_cursoEscolhido) {
      // Sem nada no banco e sem escolha local — usuário realmente novo/limpo
      _abasBloqueadas = false;
      sessionStorage.removeItem('dec_abas');
      sessionStorage.removeItem('dec_curso');
    } else if (temNoBanco) {
      atualizarNavSidebar(true);
    } else {
      // Tem curso escolhido localmente mas pagamento ainda não aprovado — mantém abas bloqueadas
      atualizarNavSidebar(false);
    }

    if(mudou){renderPerfil(d);renderMeuCurso(d);renderPagamento(d);renderHistorico(d.pagamentos);renderCronogramas(d.cronogramas);renderPresenca(d.presencas||[]);}
    // Carrega multas se o aluno tiver vínculo com o sistema OU abas já estiverem ativas
    var temVinculo = !!(d.meus_cursos) || (d.pagamentos||[]).length > 0 || !!_cursoEscolhido || _abasBloqueadas;
    if(temVinculo){ carregarMultas(); } else { renderMultas([]); }
    if(d.notificacao){
      if(d.notificacao.status==='validado'){tocarSomAprovado();mostrarToast('✅ Pagamento Aprovado!','Seu pagamento para "'+d.notificacao.curso+'" foi aprovado!','success');}
      else if(d.notificacao.status==='rejeitado'){tocarSomRejeitado();mostrarToast('❌ Pagamento Rejeitado','Seu comprovante para "'+d.notificacao.curso+'" foi rejeitado. Envie novamente.','error');}
    }
  })
  .catch(function(err){console.error("AJAX erro:",err);});
}

function renderPerfil(d){ document.getElementById('p-nome').textContent=d.nome||'—'; document.getElementById('p-rgpm').textContent=d.rgpm||'—'; document.getElementById('p-discord').textContent=d.discord||'—'; document.getElementById('hdr-nome').textContent=d.nome||'—'; document.getElementById('hdr-avatar').textContent=(d.nome||'?').substring(0,2).toUpperCase(); var badge=document.getElementById('perfil-badge'); var clsMap={Aprovado:'badge-green',Ativo:'badge-blue',Pendente:'badge-yellow',Inativo:'badge-red',Afastado:'badge-yellow',Reprovado:'badge-red'}; badge.className='badge '+(clsMap[d.status]||'badge-gray'); badge.textContent=d.status||'—'; }

function renderMeuCurso(d){
  var body=document.getElementById('curso-body');
  var cardMatricula=document.getElementById('card-matricula');
  var matriculaBody=document.getElementById('matricula-body');
  if(!d.meus_cursos){
    // Só usa _cursoEscolhido se o banco realmente não tem nada (não é resquício de sessão anterior)
    // _cursoEscolhido é limpo quando o UID muda (vide inicialização)
    if(_cursoEscolhido && !d.taxa_paga){
      // Reconstruir tela de curso inscrito localmente
      if(cardMatricula) cardMatricula.style.display='none';
      body.innerHTML='<div class="mc-hero"><div class="mc-hero-left"><div class="mc-hero-avatar"><i class="fas fa-graduation-cap"></i></div><div><div class="mc-hero-title">'+esc(_cursoEscolhido)+'</div><div class="mc-hero-status"><span class="mc-hero-badge blue"><i class="fas fa-circle-dot"></i> Inscrito</span></div></div></div></div>'
        +'<div style="display:flex;align-items:center;gap:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:14px;"><i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1.1rem;flex-shrink:0;"></i><div><div style="font-weight:700;color:#92400e;font-size:13px;">Taxa do curso não paga</div><div style="font-size:12px;color:#78350f;margin-top:2px;">Você pode receber as tags normalmente, mas sua taxa ainda está pendente. Acesse <strong>Pagamento</strong> para enviar o comprovante.</div></div></div>';
      var tagsLocais=(d.tags||[]).filter(function(t){return t.toLowerCase().indexOf('pagou')===-1;});
      if(tagsLocais.length){
        body.innerHTML+='<div class="mc-tags-section"><div class="mc-tags-label"><i class="fas fa-tags" style="margin-right:5px;"></i>Minhas TEGs do Curso</div><div class="mc-tags-grid">'+tagsLocais.map(buildTagHtml).join('')+'</div></div>';
      } else {
        body.innerHTML+='<div style="margin-top:8px;padding:12px 16px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--muted);"><i class="fas fa-tag" style="margin-right:6px;"></i>Nenhuma TEG atribuída ainda.</div>';
      }
      return;
    }
    body.innerHTML='<div class="empty-state"><i class="fas fa-graduation-cap"></i><p>Você ainda não está matriculado em nenhum curso.</p></div>';
    // Mostrar card de matrícula com cursos disponíveis
    if(cardMatricula) cardMatricula.style.display='';
    if(matriculaBody && d.cursos && d.cursos.length){
      matriculaBody.innerHTML='<div style="display:flex;flex-direction:column;gap:12px;">'+d.cursos.map(function(c){
        var taxa=parseFloat(c.valor_taxa)||0;
        var taxaLabel=taxa>0?'R$ '+taxa.toLocaleString('pt-BR',{minimumFractionDigits:2}):'Gratuito';
        var taxaCls=taxa>0?'color:#15803d;background:#dcfce7;border:1px solid #bbf7d0;':'color:#1e40af;background:#dbeafe;border:1px solid #bfdbfe;';
        var desc=c.descricao?'<div style="font-size:12px;color:var(--muted);margin-top:2px;">'+esc(c.descricao)+'</div>':'';
        return '<div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:var(--white);">'
          +'<div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#dbeafe,#e0e7ff);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--blue);flex-shrink:0;"><i class="fas fa-graduation-cap"></i></div>'
          +'<div style="flex:1;min-width:0;"><div style="font-size:14px;font-weight:700;color:var(--text);">'+esc(c.nome)+'</div>'+desc+'</div>'
          +'<div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">'
          +'<span style="font-size:12px;font-weight:700;padding:4px 10px;border-radius:8px;'+taxaCls+'">'+taxaLabel+'</span>'
          +'<button data-curso="'+esc(c.nome)+'" onclick="inscreverCurso(this.dataset.curso)" style="padding:8px 16px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:Inter,sans-serif;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;"><i class="fas fa-plus"></i> Inscrever</button>'
          +'</div></div>';
      }).join('')+'</div>';
    } else if(matriculaBody){
      matriculaBody.innerHTML='<div class="empty-state"><i class="fas fa-door-closed"></i><p>Nenhum curso aberto no momento.</p></div>';
    }
    return;
  }
  if(cardMatricula) cardMatricula.style.display='none';
  _cursoEscolhido = null; // banco confirmou
  sessionStorage.removeItem('dec_curso'); // limpa estado local
  var badgeCls=d.status==='Aprovado'?'green':'blue';
  var tagPagou=(d.tags||[]).filter(function(t){return t.toLowerCase().indexOf('pagou')>-1;});
  var tagsAula=(d.tags||[]).filter(function(t){return t.indexOf('📃')>-1;});
  var tagsOutras=(d.tags||[]).filter(function(t){return t.toLowerCase().indexOf('pagou')===-1&&t.indexOf('📃')===-1;});
  // Taxa paga = validado no banco OU tem tag "pagou"
  var taxaPaga = !!(d.taxa_paga) || tagPagou.length>0;
  var html='<div class="mc-hero"><div class="mc-hero-left"><div class="mc-hero-avatar"><i class="fas fa-graduation-cap"></i></div><div><div class="mc-hero-title">'+esc(d.meus_cursos)+'</div><div class="mc-hero-status"><span class="mc-hero-badge '+badgeCls+'"><i class="fas fa-'+(d.status==='Aprovado'?'check-circle':'circle-dot')+'"></i> '+esc(d.status)+'</span></div></div></div>';
  if(taxaPaga) html+='<div class="mc-pago-chip"><i class="fas fa-check-circle"></i> '+(tagPagou.length?esc(tagPagou[0]):'Taxa Paga ✅')+'</div>';
  html+='</div>';
  // Aviso de taxa não paga
  if(!taxaPaga){
    html+='<div style="display:flex;align-items:center;gap:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:14px;"><i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1.1rem;flex-shrink:0;"></i><div><div style="font-weight:700;color:#92400e;font-size:13px;">Taxa do curso não paga</div><div style="font-size:12px;color:#78350f;margin-top:2px;">Você pode receber as tags normalmente, mas sua taxa ainda está pendente. Acesse <strong>Pagamento</strong> para enviar o comprovante.</div></div></div>';
  }
  var todasTagsVisiveis=tagsAula.concat(tagsOutras);
  if(todasTagsVisiveis.length){html+='<div class="mc-tags-section"><div class="mc-tags-label"><i class="fas fa-tags" style="margin-right:5px;"></i>Minhas TEGs do Curso</div><div class="mc-tags-grid">'+todasTagsVisiveis.map(buildTagHtml).join('')+'</div></div>';}
  else{html+='<div style="margin-top:8px;padding:12px 16px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--muted);"><i class="fas fa-tag" style="margin-right:6px;"></i>Nenhuma TEG atribuída ainda.</div>';}
  body.innerHTML=html;
}
function buildTagHtml(t){ var cls='mc-tag-neutro',icon='fa-tag'; var tl=t.toLowerCase(); if(t.indexOf('📃')>-1||tl.indexOf('aula')>-1){cls='mc-tag-aula';icon='fa-book-open';}else if(tl.indexOf('pagou')>-1||tl.indexOf('pago')>-1){cls='mc-tag-pagou';icon='fa-check-circle';}else if(t.indexOf('⭐')>-1||tl.indexOf('destaque')>-1||tl.indexOf('especial')>-1){cls='mc-tag-especial';icon='fa-star';} return '<div class="mc-tag '+cls+'"><i class="fas '+icon+'"></i><span class="mc-tag-text">'+esc(t)+'</span></div>'; }

function renderPagamento(d){ var body=document.getElementById('pag-body'); var temPendente=(d.pagamentos||[]).some(function(p){return p.status==='pendente';}); var pagPendente=null; for(var i=0;i<(d.pagamentos||[]).length;i++){if(d.pagamentos[i].status==='pendente'){pagPendente=d.pagamentos[i];break;}}
  // Bloqueia aba SOMENTE se taxa foi validada (paga no banco)
  var taxaPagaConfirmada = !!(d.taxa_paga);
  if(taxaPagaConfirmada){body.innerHTML='<div style="display:flex;flex-direction:column;align-items:center;gap:16px;padding:32px 20px;text-align:center;"><div style="width:60px;height:60px;border-radius:16px;background:#dcfce7;border:1px solid #86efac;display:flex;align-items:center;justify-content:center;font-size:26px;color:#15803d;"><i class="fas fa-check-circle"></i></div><div><div style="font-size:16px;font-weight:800;color:#15803d;margin-bottom:6px;">Pagamento Confirmado!</div><div style="font-size:13px;color:var(--muted);max-width:320px;line-height:1.6;">Seu comprovante para o curso <strong>'+esc(d.meus_cursos)+'</strong> já foi aprovado.</div></div><div class="alerta alerta-aprovado" style="width:100%;margin-bottom:0;"><i class="fas fa-lock" style="font-size:1.1rem;flex-shrink:0;"></i><div>Esta seção está bloqueada.</div></div></div>';return;}  if(temPendente&&pagPendente){
    body.innerHTML='<div class="alerta alerta-pendente"><i class="fas fa-clock" style="font-size:1.3rem;flex-shrink:0;margin-top:1px;"></i><div style="flex:1;"><strong>Aguardando validação</strong><br>Comprovante enviado para <strong>'+esc(pagPendente.curso)+'</strong>.</div></div><button class="btn-danger" onclick="cancelarInscricao()" style="width:100%;margin-top:4px;"><i class="fas fa-times-circle"></i> Cancelar Inscrição</button>';
    return;
  }
  // Curso já definido: pelo banco (meus_cursos) ou pelo estado local (_cursoEscolhido)
  var cursoAtual = d.meus_cursos || _cursoEscolhido || '';
  var jaInscrito = !!(cursoAtual);
  if(!jaInscrito){
    // Sem inscrição — mostrar seletor de cursos (não deveria chegar aqui normalmente)
    body.innerHTML='<div style="display:flex;flex-direction:column;gap:16px;"><div><label class="field-label">Curso</label>'+buildCursoSelector(d.cursos||[])+'</div><div id="pag-info-curso" style="display:none;"></div><div><label class="field-label">Comprovante de Pagamento</label><input type="file" id="pag-file" accept="image/*" style="display:none" onchange="handleFileSelect(this)"><div class="upload-area" id="upload-area"><div id="upload-placeholder" style="pointer-events:none;"><div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div><div class="upload-title">Clique, arraste ou cole (Ctrl+V)</div><div class="upload-sub">PNG, JPG, JPEG, WEBP</div></div><div id="upload-preview-wrap" style="display:none;pointer-events:none;"><img id="preview-img" style="max-width:100%;max-height:240px;border-radius:10px;border:1px solid var(--border);display:block;margin:0 auto;" alt="Preview"><div id="upload-filename" style="font-size:12px;color:var(--blue);margin-top:8px;font-weight:600;text-align:center;"></div></div></div></div><button class="btn-primary" id="btn-enviar" onclick="enviarPagamento()"><i class="fas fa-paper-plane"></i> Enviar Comprovante</button></div>';
    anexarUploadEvents(); if(_fileParaEnviar)setFile(_fileParaEnviar); return;
  }
  // Inscrito: mostrar curso fixo + só upload (sem seletor)
  var pag_body_html='<div style="display:flex;flex-direction:column;gap:16px;">';
  // Se é apenas escolha local (sem comprovante enviado ainda), permite trocar curso
  var podeTracar = !d.meus_cursos && !temPendente;
  pag_body_html+='<div class="curso-bloqueado-box" style="justify-content:space-between;">';
  pag_body_html+='<div style="display:flex;align-items:center;gap:12px;"><i class="fas fa-graduation-cap" style="color:var(--blue);font-size:1.1rem;flex-shrink:0;"></i><div><div class="curso-bloqueado-nome">'+esc(cursoAtual)+'</div><div class="curso-bloqueado-hint">Você está inscrito neste curso</div></div></div>';
  if(podeTracar) pag_body_html+='<button onclick="trocarCurso()" style="background:none;border:1px solid #bfdbfe;color:var(--blue);border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:Inter,sans-serif;white-space:nowrap;"><i class="fas fa-exchange-alt"></i> Trocar</button>';
  pag_body_html+='</div>';
  pag_body_html+='<input type="hidden" id="pag-curso-valor" value="'+esc(cursoAtual)+'">';
  pag_body_html+='<div><label class="field-label">Comprovante de Pagamento</label><input type="file" id="pag-file" accept="image/*" style="display:none" onchange="handleFileSelect(this)"><div class="upload-area" id="upload-area"><div id="upload-placeholder" style="pointer-events:none;"><div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div><div class="upload-title">Clique, arraste ou cole (Ctrl+V)</div><div class="upload-sub">PNG, JPG, JPEG, WEBP</div><div class="paste-hint"><i class="fas fa-clipboard"></i> Ctrl+V para colar imagem</div></div><div id="upload-preview-wrap" style="display:none;pointer-events:none;"><img id="preview-img" style="max-width:100%;max-height:240px;border-radius:10px;border:1px solid var(--border);display:block;margin:0 auto;" alt="Preview"><div id="upload-filename" style="font-size:12px;color:var(--blue);margin-top:8px;font-weight:600;text-align:center;"></div><div style="font-size:11px;color:var(--muted);margin-top:4px;text-align:center;">Clique para trocar a imagem</div></div></div></div>';
  pag_body_html+='<button class="btn-primary" id="btn-enviar" onclick="enviarPagamento()"><i class="fas fa-paper-plane"></i> Enviar Comprovante</button></div>';
  body.innerHTML=pag_body_html;
  anexarUploadEvents();
  if(_fileParaEnviar)setFile(_fileParaEnviar);
}

function buildCursoSelector(cursos){ if(!cursos.length)return'<div class="curso-bloqueado-box"><i class="fas fa-info-circle"></i><div><div class="curso-bloqueado-nome">Nenhum curso aberto</div><div class="curso-bloqueado-hint">Aguarde a abertura de inscrições.</div></div></div>'; var opcoes=cursos.map(function(c){var taxa=parseFloat(c.valor_taxa)||0;var taxaHtml=taxa>0?'<span class="curso-opt-taxa">R$ '+taxa.toLocaleString('pt-BR',{minimumFractionDigits:2})+'</span>':'<span class="curso-opt-taxa gratuito">Gratuito</span>';var descHtml=c.descricao?'<div class="curso-opt-desc">'+esc(c.descricao)+'</div>':'';return'<div class="curso-option" data-nome="'+esc(c.nome)+'" data-desc="'+esc(c.descricao||'')+'" data-taxa="'+(parseFloat(c.valor_taxa)||0)+'"><div class="curso-opt-icon"><i class="fas fa-graduation-cap"></i></div><div class="curso-opt-body"><div class="curso-opt-nome">'+esc(c.nome)+'</div>'+descHtml+'</div>'+taxaHtml+'</div>';}).join(''); return'<div class="curso-select-wrapper" id="curso-select-wrapper"><input type="hidden" id="pag-curso-valor" value=""><div class="curso-select-trigger" id="curso-trigger"><span class="curso-select-trigger-text placeholder" id="curso-trigger-text">Selecione o curso...</span><span class="curso-select-arrow"><i class="fas fa-chevron-down"></i></span></div><div class="curso-dropdown" id="curso-dropdown">'+opcoes+'</div></div>'; }
function toggleCursoDropdown(){ var trigger=document.getElementById('curso-trigger');var dropdown=document.getElementById('curso-dropdown');if(!trigger||!dropdown)return;var isOpen=dropdown.classList.contains('open');trigger.classList.toggle('open',!isOpen);dropdown.classList.toggle('open',!isOpen); }
function selecionarCurso(nome,desc,taxa){ var input=document.getElementById('pag-curso-valor');if(input)input.value=nome;var triggerText=document.getElementById('curso-trigger-text');if(triggerText){triggerText.textContent=nome;triggerText.classList.remove('placeholder');}var trigger=document.getElementById('curso-trigger');var dropdown=document.getElementById('curso-dropdown');if(trigger)trigger.classList.remove('open');if(dropdown)dropdown.classList.remove('open');var info=document.getElementById('pag-info-curso');if(info){info.innerHTML=buildCursoInfoBox(nome,desc,taxa);info.style.display='block';}document.querySelectorAll('.curso-option').forEach(function(o){o.classList.toggle('selected',o.dataset.nome===nome);}); }
document.addEventListener('click',function(e){ if(e.target.closest('#curso-trigger')){toggleCursoDropdown();return;}var opt=e.target.closest('.curso-option');if(opt){var nome=opt.dataset.nome||'';var desc=opt.dataset.desc||'';var taxa=parseFloat(opt.dataset.taxa)||0;if(nome)selecionarCurso(nome,desc,taxa);return;}if(!e.target.closest('#curso-select-wrapper')){var t=document.getElementById('curso-trigger');var dd=document.getElementById('curso-dropdown');if(t)t.classList.remove('open');if(dd)dd.classList.remove('open');} });
function buildCursoInfoBox(nome,desc,taxa){ var t=parseFloat(taxa)||0;var taxaHtml=t>0?'<div class="curso-info-taxa"><i class="fas fa-tag" style="margin-right:4px;"></i>Taxa: R$ '+t.toLocaleString('pt-BR',{minimumFractionDigits:2})+'</div>':'<div class="curso-info-taxa" style="color:#1e40af;"><i class="fas fa-gift" style="margin-right:4px;"></i>Curso gratuito</div>';var descHtml=desc?'<div class="curso-info-desc">'+esc(desc)+'</div>':'';return'<div class="curso-info-box"><div class="curso-info-icon"><i class="fas fa-graduation-cap"></i></div><div><div class="curso-info-nome">'+esc(nome)+'</div>'+taxaHtml+descHtml+'</div></div>'; }

function renderHistorico(pagamentos){
  var body=document.getElementById('historico-body');
  // Filtra apenas pagamentos de taxa (não multa)
  var taxas = (pagamentos||[]).filter(function(p){ return (p.tipo_pagamento||'taxa') === 'taxa'; });
  if(!taxas.length){body.innerHTML='<div class="empty-state"><i class="fas fa-receipt"></i><p>Nenhum pagamento enviado ainda.</p></div>';return;}
  var rows=taxas.map(function(p){var dt=fmtDate(p.data_envio);var bc=p.status==='validado'?'badge-green':p.status==='rejeitado'?'badge-red':'badge-yellow';var ic=p.status==='validado'?'fa-check':p.status==='rejeitado'?'fa-times':'fa-clock';var motivoHtml='';if(p.status==='rejeitado'&&p.motivo_rejeicao){motivoHtml='<br><span style="display:inline-flex;align-items:center;gap:6px;margin-top:5px;padding:5px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;font-size:11px;color:#dc2626;font-weight:600;max-width:260px;white-space:normal;"><i class="fas fa-comment-alt" style="font-size:10px;flex-shrink:0;"></i>'+esc(p.motivo_rejeicao)+'</span>';}return'<tr><td class="strong">'+esc(p.curso)+'</td><td>'+dt+'</td><td><span class="badge '+bc+'"><i class="fas '+ic+'"></i> '+ucfirst(p.status)+'</span>'+motivoHtml+'</td></tr>';}).join('');body.innerHTML='<div style="overflow-x:auto;"><table class="dec-table"><thead><tr><th>Curso</th><th>Enviado em</th><th>Status</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
}

function renderCronogramas(lista){ var body=document.getElementById('cronogramas-body');var badge=document.getElementById('cron-count-badge');if(!lista||!lista.length){if(badge)badge.style.display='none';body.innerHTML='<div class="empty-state"><i class="fas fa-calendar-alt"></i><p>Nenhum cronograma disponível.</p></div>';return;}if(badge){badge.textContent=lista.length+(lista.length===1?' arquivo':' arquivos');badge.style.display='inline-flex';}var agora=Date.now();var cards=lista.map(function(c){var dt=fmtDateOnly(c.data_upload);var dUpload=new Date(c.data_upload.replace(' ','T')).getTime();var isNovo=(agora-dUpload)<(3*24*60*60*1000);return'<div class="cron-card"><div class="cron-card-stripe"></div><div class="cron-card-icon"><i class="fas fa-file-alt" style="color:#1a56db;"></i></div><div class="cron-card-body"><div class="cron-card-title">'+esc(c.titulo)+(isNovo?'<span class="cron-badge-new"><i class="fas fa-bolt"></i> Novo</span>':'')+'</div><div class="cron-card-meta"><div class="cron-meta-item"><i class="fas fa-calendar"></i><span>'+dt+'</span></div></div></div><div class="cron-card-actions"><a href="baixar_cronograma.php?id='+encodeURIComponent(c.id)+'" target="_blank" class="btn-dl"><i class="fas fa-download"></i> Baixar</a></div></div>';}).join('');body.innerHTML='<div class="cron-grid">'+cards+'</div>'; }

function renderPresenca(lista){ var body=document.getElementById('presenca-body');var badge=document.getElementById('pres-count-badge');if(!lista||!lista.length){if(badge)badge.style.display='none';body.innerHTML='<div class="empty-state"><i class="fas fa-clipboard-list"></i><p>Nenhuma ata ou arquivo de presença disponível.</p></div>';return;}if(badge){badge.textContent=lista.length+(lista.length===1?' item':' itens');badge.style.display='inline-flex';}var agora=Date.now();var cards=lista.map(function(c){var dt=fmtDateOnly(c.data_upload);var dUpload=new Date((c.data_upload||'').replace(' ','T')).getTime();var isNovo=(agora-dUpload)<(3*24*60*60*1000);var isAta=c.tipo==='ata';var icon=isAta?'fa-clipboard-list':'fa-file-pdf';var iconColor=isAta?'#0891b2':'#dc2626';var iconBg=isAta?'#ecfeff':'#fff1f2';var iconBorder=isAta?'#a5f3fc':'#fecaca';var tipoBadge=isAta?'<span style="font-size:10px;background:#ecfeff;color:#0891b2;border:1px solid #a5f3fc;border-radius:20px;padding:2px 8px;font-weight:700;">Ata</span>':'<span style="font-size:10px;background:#fff1f2;color:#dc2626;border:1px solid #fecaca;border-radius:20px;padding:2px 8px;font-weight:700;">PDF</span>';var href=isAta?'baixar_ata.php?id='+encodeURIComponent(c.id):'baixar_cronograma.php?id='+encodeURIComponent(c.id)+'&pasta=presenca_arquivos';return'<div class="cron-card"><div class="cron-card-stripe" style="background:linear-gradient(180deg,#0891b2,#0e7490);"></div><div class="cron-card-icon" style="background:'+iconBg+';border-color:'+iconBorder+';"><i class="fas '+icon+'" style="color:'+iconColor+';"></i></div><div class="cron-card-body"><div class="cron-card-title">'+esc(c.titulo)+' '+tipoBadge+(isNovo?' <span class="cron-badge-new"><i class="fas fa-bolt"></i> Novo</span>':'')+'</div><div class="cron-card-meta"><div class="cron-meta-item"><i class="fas fa-calendar"></i><span>'+dt+'</span></div></div></div><div class="cron-card-actions"><a href="'+href+'" target="_blank" class="btn-dl"><i class="fas fa-download"></i> Baixar</a></div></div>';}).join('');body.innerHTML='<div class="cron-grid">'+cards+'</div>'; }

function handleFileSelect(input){ var f=input.files[0];if(f)setFile(f); }
function setFile(file){ if(!file.type.startsWith('image/')){mostrarToast('⚠️ Atenção','Apenas imagens são aceitas.','warn');return;}_fileParaEnviar=file;var reader=new FileReader();reader.onload=function(e){var img=document.getElementById('preview-img');var placeholder=document.getElementById('upload-placeholder');var previewWrap=document.getElementById('upload-preview-wrap');var fn=document.getElementById('upload-filename');var area=document.getElementById('upload-area');if(!img)return;img.src=e.target.result;if(placeholder)placeholder.style.display='none';if(previewWrap)previewWrap.style.display='block';if(fn)fn.textContent=file.name||'comprovante.png';if(area)area.classList.add('has-file');};reader.readAsDataURL(file); }
function anexarUploadEvents(){ var area=document.getElementById('upload-area');if(!area)return;area.addEventListener('click',function(){document.getElementById('pag-file').click();});area.addEventListener('dragover',function(e){e.preventDefault();area.classList.add('drag-over');});area.addEventListener('dragleave',function(){area.classList.remove('drag-over');});area.addEventListener('drop',function(e){e.preventDefault();area.classList.remove('drag-over');var f=e.dataTransfer.files[0];if(f)setFile(f);}); }
document.addEventListener('paste',function(e){ if(!document.getElementById('upload-area'))return;var items=e.clipboardData&&e.clipboardData.items;if(!items)return;for(var i=0;i<items.length;i++){if(items[i].type.startsWith('image/')){e.preventDefault();var file=items[i].getAsFile();if(file){var ext=items[i].type.split('/')[1]||'png';setFile(new File([file],'comprovante.'+ext,{type:items[i].type}));mostrarToast('✅ Imagem colada!','Pronto para envio.','success');}break;}} });

function enviarPagamento(){ if(_enviando)return;var cursoInput=document.getElementById('pag-curso-valor');var curso=cursoInput?cursoInput.value:'';if(!curso){mostrarToast('⚠️ Atenção','Selecione um curso.','warn');return;}if(!_fileParaEnviar){mostrarToast('⚠️ Atenção','Adicione o comprovante de pagamento.','warn');return;}_enviando=true;var btn=document.getElementById('btn-enviar');if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Enviando...';}var fd=new FormData();fd.append('ajax_action','enviar_pagamento');fd.append('curso',curso);fd.append('comprovante',_fileParaEnviar,'comprovante.'+(_fileParaEnviar.type.split('/')[1]||'png'));fetch('painel_aluno.php',{method:'POST',body:fd}).then(function(r){return r.text();}).then(function(txt){_enviando=false;if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Enviar Comprovante';}console.log('RAW RESPONSE ('+txt.length+' chars):', JSON.stringify(txt.substring(0,200)));var jsonStart=txt.indexOf('{');if(jsonStart>0){console.warn('LIXO ANTES DO JSON ('+jsonStart+' chars):', JSON.stringify(txt.substring(0,jsonStart)));}var cleanTxt=jsonStart>=0?txt.substring(jsonStart):txt;try{var d=JSON.parse(cleanTxt);if(d.sucesso){_fileParaEnviar=null;mostrarToast('✅ Comprovante Enviado!','Aguarde a validação do administrador.','success');_hashUltimo='';carregarDados();}else{mostrarToast('❌ Erro',d.erro||'Falha ao enviar comprovante.','error');}}catch(e){mostrarToast('❌ Erro DEBUG','Resposta: '+txt.substring(0,120),'error');}}).catch(function(err){_enviando=false;if(btn){btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Enviar Comprovante';}mostrarToast('❌ Erro',err.message,'error');}); }

function inscreverCurso(nomeCurso){
  // Guarda curso escolhido para usar no render imediato (persiste no reload)
  _cursoEscolhido = nomeCurso;
  sessionStorage.setItem('dec_curso', nomeCurso);

  // Habilita abas imediatamente (sem esperar polling)
  atualizarNavSidebar(true);

  // Atualiza aba Curso imediatamente com o curso escolhido (taxa não paga ainda)
  var body = document.getElementById('curso-body');
  var cardMatricula = document.getElementById('card-matricula');
  if(cardMatricula) cardMatricula.style.display='none';
  if(body){
    var cursoObj = (_cursosDisponiveis||[]).find(function(c){ return c.nome===nomeCurso; });
    var html = '<div class="mc-hero"><div class="mc-hero-left"><div class="mc-hero-avatar"><i class="fas fa-graduation-cap"></i></div>';
    html += '<div><div class="mc-hero-title">'+esc(nomeCurso)+'</div>';
    html += '<div class="mc-hero-status"><span class="mc-hero-badge blue"><i class="fas fa-circle-dot"></i> Inscrito</span></div></div></div></div>';
    html += '<div style="display:flex;align-items:center;gap:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:14px;">';
    html += '<i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1.1rem;flex-shrink:0;"></i>';
    html += '<div><div style="font-weight:700;color:#92400e;font-size:13px;">Taxa do curso não paga</div>';
    html += '<div style="font-size:12px;color:#78350f;margin-top:2px;">Você pode receber as tags normalmente, mas sua taxa ainda está pendente. Acesse <strong>Pagamento</strong> para enviar o comprovante.</div></div></div>';
    html += '<div style="margin-top:8px;padding:12px 16px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--muted);"><i class="fas fa-tag" style="margin-right:6px;"></i>Nenhuma TEG atribuída ainda.</div>';
    body.innerHTML = html;
  }

  // Vai para aba pagamento e pré-seleciona o curso
  router('pagamento');
  setTimeout(function(){
    var input = document.getElementById('pag-curso-valor');
    if(input){ input.value = nomeCurso; }
    var trigger = document.getElementById('curso-trigger-text');
    if(trigger){ trigger.textContent = nomeCurso; trigger.classList.remove('placeholder'); }
    var cursoObj = (_cursosDisponiveis||[]).find(function(c){ return c.nome===nomeCurso; });
    if(cursoObj){
      var info = document.getElementById('pag-info-curso');
      if(info){ info.innerHTML = buildCursoInfoBox(cursoObj.nome, cursoObj.descricao||'', cursoObj.valor_taxa||0); info.style.display='block'; }
    }
  }, 100);
}

function trocarCurso(){
  // Limpa apenas o estado local — volta a mostrar a lista de cursos
  _cursoEscolhido = null;
  _abasBloqueadas = false;
  sessionStorage.removeItem('dec_curso');
  sessionStorage.removeItem('dec_abas');
  // Esconde abas e volta para tela de seleção
  var ids = ['nav-pagamento','nav-presenca','nav-escala','nav-sec-atividades'];
  ids.forEach(function(id){ var el=document.getElementById(id); if(el) el.style.display='none'; });
  router('meu-curso');
  _hashUltimo='';
  carregarDados();
}

function cancelarInscricao(){ if(!confirm('Tem certeza que deseja cancelar sua inscrição pendente?'))return;fetch('painel_aluno.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=cancelar_inscricao'}).then(function(r){return r.json();}).then(function(d){if(d.sucesso){mostrarToast('✅ Inscrição cancelada','Você pode selecionar outro curso.','info');_fileParaEnviar=null;_hashUltimo='';_abasBloqueadas=false;_cursoEscolhido=null;sessionStorage.removeItem('dec_abas');sessionStorage.removeItem('dec_curso');atualizarNavSidebar(false);carregarDados();}else{mostrarToast('❌ Erro',d.erro||'Não foi possível cancelar.','error');}}); }

function esc(s){ var d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }
function ucfirst(s){ return s?s.charAt(0).toUpperCase()+s.slice(1):''; }
function fmtDate(str){ if(!str)return'—';try{var d=new Date(str.replace(' ','T'));return d.toLocaleDateString('pt-BR')+' '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});}catch(e){return str;} }
function fmtDateOnly(str){ if(!str)return'—';try{return new Date(str.replace(' ','T')).toLocaleDateString('pt-BR');}catch(e){return str;} }

// ══════════════════════════════════════════════════════════════
//  MULTAS DO ALUNO
// ══════════════════════════════════════════════════════════════
var _mmcMultaId = null;
var _mmcFile    = null;
var _mmcEnviando = false;

function carregarMultas(){
  fetch('painel_aluno.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=multa_listar_aluno'})
  .then(function(r){return r.json();})
  .then(function(d){
    // Mesmo se sucesso=false, renderiza lista vazia (evita travar em "Carregando...")
    renderMultas(d.sucesso ? (d.multas||[]) : []);
  }).catch(function(){
    renderMultas([]);
  });
}

function renderMultas(lista){
  var body  = document.getElementById('multas-body');
  var badge = document.getElementById('multas-count-badge');
  var navBadge = document.getElementById('nav-multas-badge');
  var navLink  = document.getElementById('nav-multas');
  if(!body) return;

  if(!lista.length){
    if(badge)   { badge.style.display='none'; }
    if(navBadge){ navBadge.style.display='none'; }
    var aviso = document.getElementById('aviso-multas');
    if(aviso) aviso.style.display='none';
    body.innerHTML='<div class="empty-state"><i class="fas fa-check-circle" style="color:#15803d;"></i><p style="color:#15803d;font-weight:700;">Nenhuma multa pendente. Tudo certo! ✅</p></div>';
    return;
  }

  // Tem multas — mostrar aviso
  var aviso = document.getElementById('aviso-multas');
  if(aviso) aviso.style.display='flex';
  if(badge){   badge.textContent=lista.length; badge.style.display='inline-flex'; badge.style.background='#dc2626'; badge.style.color='#fff'; }
  if(navBadge){ navBadge.textContent=lista.length; navBadge.style.display='inline-flex'; }

  var statusMap = {pendente:'Pendente',aguardando_validacao:'Aguardando Validação'};
  var statusCls = {pendente:'badge-red',aguardando_validacao:'badge-yellow'};

  var cards = lista.map(function(m, idx){
    var num = idx + 1;
    var prazo = new Date(m.prazo_expira.replace(' ','T'));
    var agora = new Date();
    var diffMs = prazo - agora;
    var diffH  = Math.floor(diffMs / 3600000);
    var diffD  = Math.floor(diffH / 24);
    var prazoStr = diffMs <= 0 ? '<span style="color:#dc2626;font-weight:700;">Prazo encerrado</span>'
                 : diffD >= 1  ? '<span style="color:#d97706;font-weight:700;">'+diffD+' dia(s) restante(s)</span>'
                 : '<span style="color:#dc2626;font-weight:700;">'+diffH+' hora(s) restante(s)</span>';
    var valor = parseFloat(m.valor).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    var cls   = statusCls[m.status]||'badge-gray';
    var label = statusMap[m.status]||m.status;
    var btnComp = m.status==='pendente'
      ? '<button data-mid="'+m.id+'" data-num="'+num+'" data-curso="'+esc(m.curso_aluno||'')+'" data-valor="'+esc(valor)+'" onclick="mmcAbrir(this)" style="padding:8px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-family:Inter,sans-serif;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;"><i class="fas fa-file-upload"></i> Enviar Comprovante</button>'
      : '<span style="font-size:12px;color:#d97706;font-weight:600;"><i class="fas fa-clock"></i> Aguardando admin</span>'
    return '<div style="border:1px solid #fecaca;border-radius:12px;padding:16px;background:#fff5f5;display:flex;flex-direction:column;gap:10px;">'
      +'<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">'
      +'<div style="font-size:14px;font-weight:800;color:#b91c1c;"><i class="fas fa-gavel" style="margin-right:6px;"></i>Multa #'+num+'</div>'
      +'<span class="badge '+cls+'">'+label+'</span>'
      +'</div>'
      +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
      +'<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:10px;">'
      +'<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;">Curso</div>'
      +'<div style="font-size:13px;font-weight:700;color:#1e293b;">'+esc(m.curso_aluno||'—')+'</div>'
      +'</div>'
      +'<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:10px;">'
      +'<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;">Valor</div>'
      +'<div style="font-size:15px;font-weight:800;color:#dc2626;">'+valor+'</div>'
      +'</div>'
      +'<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:10px;">'
      +'<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;">Prazo</div>'
      +'<div style="font-size:12px;">'+prazoStr+'</div>'
      +'</div>'
      +'<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:10px;">'
      +'<div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:3px;">Advertência</div>'
      +'<div style="font-size:12px;"><a href="'+esc(m.link_adv)+'" target="_blank" style="color:#1a56db;font-weight:700;"><i class="fas fa-external-link-alt"></i> Ver mensagem</a></div>'
      +'</div>'
      +'</div>'
      +'<div style="display:flex;justify-content:flex-end;">'+btnComp+'</div>'
      +'</div>';
  }).join('');
  body.innerHTML='<div style="display:flex;flex-direction:column;gap:14px;">'+cards+'</div>';
}

function mmcAbrir(btn){
  abrirModalMultaComp(
    parseInt(btn.dataset.mid),
    btn.dataset.curso,
    btn.dataset.valor,
    parseInt(btn.dataset.num)||1
  );
}
function abrirModalMultaComp(multaId, curso, valor, num){
  num = num || multaId;
  _mmcMultaId = multaId;
  _mmcFile    = null;
  document.getElementById('mmc-file').value = '';
  document.getElementById('mmc-placeholder').style.display = '';
  document.getElementById('mmc-preview-wrap').style.display = 'none';
  document.getElementById('mmc-info').innerHTML =
    '<strong>Multa #'+num+'</strong> · '+esc(curso)
    +'<br><span style="color:#dc2626;font-weight:700;">'+esc(valor)+'</span>';
  document.getElementById('mmc-btn-enviar').disabled = false;
  document.getElementById('mmc-btn-enviar').innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Comprovante';
  var modal = document.getElementById('modal-multa-comp');
  modal.style.display = 'flex';
}

function fecharModalMultaComp(){
  document.getElementById('modal-multa-comp').style.display = 'none';
}

function mmcHandleFile(input){
  var f = input.files[0]; if(!f) return;
  if(!f.type.startsWith('image/')){mostrarToast('⚠️ Atenção','Apenas imagens são aceitas.','warn');return;}
  _mmcFile = f;
  var reader = new FileReader();
  reader.onload = function(e){
    document.getElementById('mmc-preview-img').src = e.target.result;
    document.getElementById('mmc-placeholder').style.display = 'none';
    document.getElementById('mmc-preview-wrap').style.display = '';
    document.getElementById('mmc-filename').textContent = f.name;
  };
  reader.readAsDataURL(f);
}

function mmcEnviar(){
  if(_mmcEnviando) return;
  if(!_mmcMultaId){ mostrarToast('❌ Erro','Multa inválida.','error'); return; }
  if(!_mmcFile){    mostrarToast('⚠️ Atenção','Selecione uma imagem.','warn'); return; }
  _mmcEnviando = true;
  var btn = document.getElementById('mmc-btn-enviar');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

  var fd = new FormData();
  fd.append('ajax_action','multa_enviar_comprovante');
  fd.append('multa_id', _mmcMultaId);
  fd.append('comprovante_multa', _mmcFile, 'comprovante.jpg');

  fetch('painel_aluno.php',{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    _mmcEnviando = false;
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Comprovante';
    if(d.sucesso){
      fecharModalMultaComp();
      mostrarToast('✅ Comprovante enviado!','Aguarde a validação do administrador.','success');
      carregarMultas();
    } else {
      mostrarToast('❌ Erro', d.erro||'Falha ao enviar.','error');
    }
  }).catch(function(e){
    _mmcEnviando = false;
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Comprovante';
    mostrarToast('❌ Erro',e.message,'error');
  });
}

// ══════════════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function(){
  router('perfil');
  carregarMultas();
  iniciarPolling();

  // ── Polling de nível: verifica exclusão/mudança a cada 5s ─────────────
  function verificarNivelLoop() {
    // Se conta já foi detectada como excluída, não faz mais nada
    if (_contaExcluidaDetectada) return;

    fetch('painel_aluno.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'ajax_action=verificar_nivel'
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.logout) return;

      if (d.conta_excluida) {
        // ── Marcar flag e parar TODOS os pollings ─────────────────────
        _contaExcluidaDetectada = true;
        if (_pollingDados)  { clearInterval(_pollingDados);  _pollingDados  = null; }
        if (_pollingNivel)  { clearInterval(_pollingNivel);  _pollingNivel  = null; }

        // ── Conteúdo do overlay depende do motivo ─────────────────────
        var multaNaoPaga = (d.motivo === 'multa_nao_paga');

        // ── Montar overlay ────────────────────────────────────────────
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(13,27,46,0.97);display:flex;align-items:center;justify-content:center;padding:16px;';

        if (multaNaoPaga) {
          overlay.innerHTML =
            '<div style="background:#fff;border-radius:20px;padding:36px 32px;max-width:440px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.6);">' +
            '<div style="width:76px;height:76px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">' +
            '<i class="fas fa-ban" style="color:#dc2626;font-size:32px;"></i></div>' +
            '<div style="font-size:22px;font-weight:800;color:#b91c1c;margin-bottom:8px;">Conta Bloqueada</div>' +
            '<div style="font-size:13px;color:#64748b;line-height:1.8;margin-bottom:20px;">' +
            'Sua multa <strong style="color:#dc2626;">não foi paga</strong> dentro do prazo estabelecido.<br>' +
            'Sua conta foi <strong style="color:#dc2626;">bloqueada permanentemente</strong> e você foi adicionado à <strong style="color:#dc2626;">blacklist</strong>.<br>' +
            'Entre em contato com a administração para regularizar sua situação.' +
            '</div>' +
            '<a href="https://discord.com/channels/1024694525593141288/1319470189326368769" target="_blank" ' +
            'style="display:inline-flex;align-items:center;gap:8px;padding:11px 24px;background:#5865f2;color:#fff;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;margin-bottom:20px;">' +
            '<i class="fab fa-discord" style="font-size:16px;"></i> Falar com a Administração</a>' +
            '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px;">' +
            '<div style="font-size:11px;color:#dc2626;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Redirecionando em</div>' +
            '<div id="countdown-excluido" style="font-size:52px;font-weight:800;color:#dc2626;line-height:1;font-family:monospace;">10</div>' +
            '<div style="font-size:11px;color:#ef4444;margin-top:4px;">segundos para a tela de login</div>' +
            '</div>' +
            '</div>';
        } else {
          overlay.innerHTML =
            '<div style="background:#fff;border-radius:20px;padding:40px 32px;max-width:420px;width:90%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.5);">' +
            '<div style="width:72px;height:72px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">' +
            '<i class="fas fa-user-slash" style="color:#dc2626;font-size:30px;"></i></div>' +
            '<div style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:10px;">Conta Excluída</div>' +
            '<div style="font-size:14px;color:#64748b;line-height:1.7;margin-bottom:24px;">' +
            'Sua conta foi excluída pela <strong style="color:#dc2626;">administração</strong>.<br>Se acreditar que isso é um engano, entre em contato com um administrador.' +
            '</div>' +
            '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px;margin-bottom:20px;">' +
            '<div style="font-size:12px;color:#dc2626;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Redirecionando em</div>' +
            '<div id="countdown-excluido" style="font-size:48px;font-weight:800;color:#dc2626;line-height:1;font-family:monospace;">10</div>' +
            '<div style="font-size:11px;color:#ef4444;margin-top:4px;">segundos</div>' +
            '</div>' +
            '<div style="font-size:12px;color:#94a3b8;"><i class="fas fa-arrow-right" style="margin-right:5px;"></i>Você será levado à tela de login</div>' +
            '</div>';
        }
        document.body.appendChild(overlay);

        // ── Contagem regressiva — UM único intervalo ──────────────────
        var segundos = 10;
        var el = document.getElementById('countdown-excluido');
        var countTimer = setInterval(function() {
          segundos--;
          if (el) el.textContent = segundos;
          if (segundos <= 0) {
            clearInterval(countTimer);
            window.location.href = 'logout.php';
          }
        }, 1000);

      } else {
        // Nível alterado — mensagem original
        document.body.insertAdjacentHTML('beforeend',
          '<div style="position:fixed;inset:0;z-index:999999;background:rgba(13,27,46,0.93);display:flex;align-items:center;justify-content:center;">' +
          '<div style="background:#fff;border-radius:16px;padding:32px 28px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4);">' +
          '<div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">' +
          '<i class="fas fa-user-shield" style="color:#dc2626;font-size:26px;"></i></div>' +
          '<div style="font-size:17px;font-weight:800;color:#1e293b;margin-bottom:8px;">Seu nível foi alterado</div>' +
          '<div style="font-size:13px;color:#64748b;margin-bottom:20px;line-height:1.6;">Seu acesso foi modificado.<br>Você será redirecionado para fazer login novamente.</div>' +
          '<div style="color:#64748b;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Redirecionando...</div>' +
          '</div></div>'
        );
        setTimeout(function(){ window.location.href = 'logout.php'; }, 2500);
      }
    })
    .catch(function(){});
  }

  _pollingNivel = setInterval(verificarNivelLoop, 5000);

  // Polling dedicado de multas vencidas a cada 10s
  setInterval(function() {
    if (_contaExcluidaDetectada) return;
    fetch('painel_aluno.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'ajax_action=verificar_nivel'
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.force_logout) { window.location.href = 'logout.php'; return; }
      if (d.logout && d.conta_excluida && d.motivo === 'multa_nao_paga') {
        verificarNivelLoop();
      }
    })
    .catch(function(){});
  }, 10000);
});
</script>
</body>
</html>