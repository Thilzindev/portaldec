<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
session_start();
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

if (!empty($_POST['ajax_action'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION["usuario"])) { echo json_encode(["sucesso"=>false,"erro"=>"Não autorizado"]); exit; }
    require "conexao.php"; $conexao->set_charset("utf8mb4");
    if (!empty($_SESSION['rgpm'])) {
        $chkSessCI = db_val($conexao, "SHOW COLUMNS FROM usuarios LIKE 'session_invalidada'");
        if ($chkSessCI !== null) {
            $invRowI = db_row($conexao, "SELECT session_invalidada FROM usuarios WHERE rgpm = ? LIMIT 1", 's', [$_SESSION['rgpm']]);
            if ($invRowI && (int)$invRowI['session_invalidada'] === 1) {
                db_query($conexao, "UPDATE usuarios SET session_invalidada = 0 WHERE rgpm = ?", 's', [$_SESSION['rgpm']]);
                echo json_encode(["sucesso"=>false,"force_logout"=>true]); exit;
            }
        }
    }
    $acao = $_POST['ajax_action'];

    if ($acao === 'verificar_nivel') {
        $nivelSessao = intval($_SESSION['nivel'] ?? 0);
        $rgpm = $_SESSION['rgpm'] ?? '';
        if (!$rgpm) { echo json_encode(['logout' => true]); exit; }
        $q = $conexao->prepare("SELECT nivel FROM usuarios WHERE rgpm=? LIMIT 1");
        $q->bind_param('s', $rgpm); $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if (!$row || intval($row['nivel']) !== $nivelSessao) { echo json_encode(['logout' => true]); exit; }
        echo json_encode(['logout' => false]); exit;
    }

    if ($acao === 'listar_alunos') {
        // FIX: use 'busca_curso' to avoid conflict with generic 'curso' param
        $curso = trim($_POST['busca_curso'] ?? $_POST['curso'] ?? '');
        $busca = trim($_POST['busca'] ?? '');
        $sql = "SELECT id, nome, rgpm, discord, status, meus_cursos FROM usuarios WHERE nivel = 3";
        $params = []; $types = '';
        if ($curso) { $sql .= " AND meus_cursos LIKE ?"; $params[] = "%{$curso}%"; $types .= 's'; }
        if ($busca) { $sql .= " AND (nome LIKE ? OR rgpm LIKE ? OR discord LIKE ?)"; $like="%{$busca}%"; $params[]=$like;$params[]=$like;$params[]=$like; $types .= 'sss'; }
        $sql .= " ORDER BY nome ASC";
        if ($params) { $stmt=$conexao->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
        else { $rows=$conexao->query($sql)->fetch_all(MYSQLI_ASSOC); }
        echo json_encode(["sucesso"=>true,"alunos"=>$rows,"total"=>count($rows)]); exit;
    }

    if ($acao === 'meus_dados') {
        $sessUsuAjax = $_SESSION["usuario"];
        $nomeSessao = is_array($sessUsuAjax) ? ($sessUsuAjax["nome"] ?? '') : ($sessUsuAjax ?: '');
        if (!$nomeSessao) { echo json_encode(["sucesso"=>false,"erro"=>"Sem nome"]); exit; }
        $qMe = $conexao->prepare("SELECT nome, rgpm, discord FROM usuarios WHERE nome = ? LIMIT 1");
        if (!$qMe) { echo json_encode(["sucesso"=>false,"erro"=>$conexao->error]); exit; }
        $qMe->bind_param("s", $nomeSessao); $qMe->execute();
        $rowMe = $qMe->get_result()->fetch_assoc();
        if (!$rowMe) { echo json_encode(["sucesso"=>false,"erro"=>"Não encontrado"]); exit; }
        echo json_encode(["sucesso"=>true,"nome"=>$rowMe['nome'],"rgpm"=>$rowMe['rgpm']??'—',"discord"=>$rowMe['discord']??'—']); exit;
    }

    if ($acao === 'dashboard_stats') {
        $qTotal  = $conexao->query("SELECT COUNT(*) as t FROM usuarios WHERE nivel=3");
        $total   = $qTotal ? intval($qTotal->fetch_assoc()['t']) : 0;
        $qAprov  = $conexao->query("SELECT COUNT(*) as t FROM usuarios WHERE nivel=3 AND status='Aprovado'");
        $aprovados = $qAprov ? intval($qAprov->fetch_assoc()['t']) : 0;
        $qCursos = $conexao->query("SELECT id, nome, status, alunos_matriculados FROM cursos WHERE status='Aberto' ORDER BY nome ASC");
        $cursos=[]; if($qCursos){while($r=$qCursos->fetch_assoc()){$cursos[]=$r;}}
        $qCron=$conexao->query("SELECT id, titulo, data_envio AS data_upload FROM cronogramas ORDER BY data_envio DESC");
        $cronogramas=[]; if($qCron){while($r=$qCron->fetch_assoc()){$cronogramas[]=$r;}}
        $atas=[];
        $qAtas=$conexao->query("SELECT id, nome_referencia AS nome_aula, data_registro AS data_criacao FROM registros_presenca ORDER BY data_registro DESC LIMIT 20");
        if($qAtas){while($r=$qAtas->fetch_assoc()){$atas[]=$r;}}
        echo json_encode(["sucesso"=>true,"total"=>$total,"aprovados"=>$aprovados,"cursos"=>$cursos,"cronogramas"=>$cronogramas,"atas"=>$atas]); exit;
    }

    if ($acao === 'deletar_ata') {
        $id=intval($_POST['id']??0); if(!$id){echo json_encode(["sucesso"=>false,"erro"=>"ID inválido"]);exit;}
        $stmt=$conexao->prepare("DELETE FROM registros_presenca WHERE id=?");$stmt->bind_param("i",$id);
        echo $stmt->execute()?json_encode(["sucesso"=>true]):json_encode(["sucesso"=>false,"erro"=>$conexao->error]); exit;
    }

    if ($acao === 'deletar_cronograma') {
        $id=intval($_POST['id']??0); if(!$id){echo json_encode(["sucesso"=>false,"erro"=>"ID inválido"]);exit;}
        $stmt=$conexao->prepare("DELETE FROM cronogramas WHERE id=?");$stmt->bind_param("i",$id);
        echo $stmt->execute()?json_encode(["sucesso"=>true]):json_encode(["sucesso"=>false,"erro"=>$conexao->error]); exit;
    }

    if ($acao === 'importar_cronograma') {
        $titulo=trim($_POST['titulo']??''); if(!$titulo){echo json_encode(["sucesso"=>false,"erro"=>"Título obrigatório"]);exit;}
        if(empty($_FILES['arquivo'])||$_FILES['arquivo']['error']!==0){echo json_encode(["sucesso"=>false,"erro"=>"Arquivo inválido"]);exit;}
        $ext=strtolower(pathinfo($_FILES['arquivo']['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['pdf','jpg','jpeg','png','docx'])){echo json_encode(["sucesso"=>false,"erro"=>"Tipo não permitido"]);exit;}
        $conteudo=file_get_contents($_FILES['arquivo']['tmp_name']);
        if($conteudo===false){echo json_encode(["sucesso"=>false,"erro"=>"Falha ao ler arquivo"]);exit;}
        $stmt=$conexao->prepare("INSERT INTO cronogramas (titulo, arquivo_pdf, data_envio) VALUES (?, ?, NOW())");
        if(!$stmt){echo json_encode(["sucesso"=>false,"erro"=>$conexao->error]);exit;}
        $null=null; $stmt->bind_param("sb",$titulo,$null); $stmt->send_long_data(1,$conteudo);
        echo $stmt->execute()?json_encode(["sucesso"=>true]):json_encode(["sucesso"=>false,"erro"=>$stmt->error]); exit;
    }

    echo json_encode(["sucesso"=>false,"erro"=>"Ação desconhecida"]); exit;
}

if (!isset($_SESSION["usuario"])) { header("Location: login.php"); exit; }
require "conexao.php"; $conexao->set_charset("utf8mb4");

$sessUsu = $_SESSION["usuario"];
if (is_array($sessUsu)) {
    $usuNome=$sessUsu["nome"]??"Instrutor"; $usuRgpm=$sessUsu["rgpm"]??"—"; $usuDiscord=$sessUsu["discord"]??"—";
} else {
    $usuNome=$sessUsu?:"Instrutor"; $usuRgpm=$_SESSION["rgpm"]??"—"; $usuDiscord=$_SESSION["discord"]??"—";
}
if ($usuDiscord==="—"||$usuRgpm==="—") {
    $qUsu=$conexao->prepare("SELECT rgpm, discord FROM usuarios WHERE nome=? LIMIT 1");
    if($qUsu){$qUsu->bind_param("s",$usuNome);$qUsu->execute();$rowUsu=$qUsu->get_result()->fetch_assoc();
    if($rowUsu){if($usuRgpm==="—")$usuRgpm=$rowUsu["rgpm"]??"—";if($usuDiscord==="—")$usuDiscord=$rowUsu["discord"]??"—";}}
}

$qCursosA=$conexao->query("SELECT id, nome, alunos_matriculados FROM cursos WHERE status='Aberto' ORDER BY nome ASC");
$cursosAbertos=[]; if($qCursosA){while($r=$qCursosA->fetch_assoc()){$cursosAbertos[]=$r;}}
$qCron=$conexao->query("SELECT id, titulo, data_envio AS data_upload FROM cronogramas ORDER BY data_envio DESC");
$cronogramas=[]; if($qCron){while($r=$qCron->fetch_assoc()){$cronogramas[]=$r;}}
$atas=[];
$qAtas=$conexao->query("SELECT id, nome_referencia AS nome_aula, data_registro AS data_criacao FROM registros_presenca ORDER BY data_registro DESC LIMIT 20");
if($qAtas){while($r=$qAtas->fetch_assoc()){$atas[]=$r;}}

function corCurso($nome){
    $n=strtoupper($nome);
    if(strpos($n,"CFSD")!==false)return["bg"=>"#dbeafe","color"=>"#1e40af","border"=>"#bfdbfe","label"=>"CFSD"];
    if(strpos($n,"CFC")!==false)return["bg"=>"#dcfce7","color"=>"#15803d","border"=>"#bbf7d0","label"=>"CFC"];
    if(strpos($n,"CFO")!==false)return["bg"=>"#fef3c7","color"=>"#92400e","border"=>"#fde68a","label"=>"CFO"];
    if(strpos($n,"CFS")!==false)return["bg"=>"#ede9fe","color"=>"#4338ca","border"=>"#ddd6fe","label"=>"CFS"];
    return["bg"=>"#f1f5f9","color"=>"#475569","border"=>"#e2e8f0","label"=>"CURSO"];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Painel DEC · Instrutor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
<style>
:root{--navy:#0d1b2e;--blue:#1a56db;--blue2:#1e40af;--bg:#f1f5f9;--white:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--light:#f8fafc;--red:#dc2626;--red-p:#fef2f2;--green:#15803d;--green-p:#dcfce7;--purple:#7c3aed;--purple-p:#ede9fe;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);font-family:'Inter',sans-serif;color:var(--text);height:100vh;display:flex;overflow:hidden;}
.sidebar{width:255px;background:var(--navy);display:flex;flex-direction:column;flex-shrink:0;box-shadow:4px 0 20px rgba(0,0,0,.25);z-index:100;transition:transform .3s ease;}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:12px;}
.brand{font-size:16px;font-weight:800;color:#fff;}.brand span{color:#60a5fa;}
.sub{font-size:10px;color:rgba(255,255,255,.35);margin-top:2px;}
.nav-section{padding:14px 20px 5px;font-size:9.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.25);}
.nav-link{display:flex;align-items:center;gap:11px;padding:10px 20px;margin:1px 10px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.5);font-size:13px;font-weight:500;transition:all .18s;border:1px solid transparent;}
.nav-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.06);font-size:12px;flex-shrink:0;}
.nav-link:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85);}
.nav-link.active{background:var(--blue);color:#fff;box-shadow:0 4px 14px rgba(26,86,219,.4);}
.nav-link.active .nav-icon{background:rgba(255,255,255,.2);}
.sidebar-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);font-size:10px;color:rgba(255,255,255,.2);}
main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}
header{height:60px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 20px;flex-shrink:0;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.hamburger{display:none;background:none;border:none;cursor:pointer;padding:6px;border-radius:8px;color:var(--text);font-size:18px;}
.header-title{font-size:15px;font-weight:700;color:var(--text);}
.user-chip{display:flex;align-items:center;gap:8px;background:var(--light);border:1px solid var(--border);border-radius:8px;padding:5px 12px 5px 5px;}
.avatar{width:30px;height:30px;background:#4338ca;border-radius:6px;display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:700;flex-shrink:0;}
.btn-logout{display:flex;align-items:center;gap:6px;background:var(--red-p);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;}
.btn-logout:hover{background:#fee2e2;}
#viewport{flex:1;overflow-y:auto;background:var(--bg);padding:20px;scrollbar-width:thin;}
.section-view{display:none;}.section-view.active{display:block;animation:fadeUp .25s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);background:var(--light);}
.ch-left{display:flex;align-items:center;gap:12px;}
.ch-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-size:13px;flex-shrink:0;}
.ch-title{font-size:14px;font-weight:700;color:var(--text);}
.ch-sub{font-size:11px;color:var(--muted);margin-top:1px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:var(--green-p);color:var(--green);border:1px solid #bbf7d0;}
.badge-blue{background:#dbeafe;color:var(--blue2);border:1px solid #bfdbfe;}
.badge-yellow{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge-gray{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.dec-input{width:100%;padding:9px 13px;border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;color:var(--text);background:var(--white);outline:none;transition:border-color .18s;}
.dec-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.1);}
.dec-select{width:100%;padding:9px 32px 9px 13px;border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;color:var(--text);background:var(--white);outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;}
.field-label{display:block;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.dec-table{width:100%;border-collapse:collapse;font-size:13px;}
.dec-table th{padding:10px 14px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);background:var(--light);}
.dec-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9;color:var(--muted);}
.dec-table td.strong{font-weight:600;color:var(--text);}
.dec-table tr:last-child td{border-bottom:none;}
.dec-table tbody tr:hover td{background:#f8fafc;}
.instrutor-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:20px;}
.instrutor-avatar{width:50px;height:50px;background:linear-gradient(135deg,#4338ca,#1a56db);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:20px;font-weight:800;flex-shrink:0;box-shadow:0 4px 12px rgba(67,56,202,.3);}
.instrutor-info{flex:1;min-width:0;}
.instrutor-nome{font-size:15px;font-weight:800;color:var(--text);}
.instrutor-role{display:inline-flex;align-items:center;gap:4px;background:#ede9fe;color:#4338ca;border:1px solid #ddd6fe;border-radius:20px;padding:2px 8px;font-size:10px;font-weight:700;margin-top:4px;}
.instrutor-meta{display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;}
.instrutor-meta-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);}
.instrutor-meta-item i{font-size:10px;color:#94a3b8;}
.instrutor-meta-item span{font-weight:600;color:var(--text);}
.curso-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .18s;}
.curso-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.curso-badge{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;}
.curso-nome{font-size:14px;font-weight:700;color:var(--text);}
.curso-mat{display:flex;align-items:center;gap:5px;margin-top:6px;font-size:12px;font-weight:600;}
.pill-curso{padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:var(--white);color:var(--muted);transition:all .18s;white-space:nowrap;}
.pill-curso:hover{border-color:#94a3b8;color:var(--text);}
.pill-todos{background:var(--navy)!important;color:#fff!important;border-color:var(--navy)!important;}
.btn-dl{display:inline-flex;align-items:center;gap:5px;background:var(--green-p);color:var(--green);border:1px solid #bbf7d0;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;text-decoration:none;transition:all .18s;cursor:pointer;}
.btn-dl:hover{background:#bbf7d0;}
.btn-del{display:inline-flex;align-items:center;gap:5px;background:var(--red-p);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer;transition:all .18s;}
.btn-del:hover{background:#fee2e2;}
.dec-modal-bd{position:fixed;inset:0;background:rgba(13,27,46,.55);backdrop-filter:blur(4px);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;}
.dec-modal{background:var(--white);border:1px solid var(--border);border-radius:14px;width:100%;max-width:520px;max-height:92vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.dec-modal-header{background:var(--light);border-bottom:1px solid var(--border);padding:15px 20px;display:flex;align-items:center;justify-content:space-between;}
.dec-modal-title{font-size:15px;font-weight:800;}
.dec-modal-body{padding:20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:14px;}
.dec-modal-foot{padding:14px 20px;border-top:1px solid var(--border);background:var(--light);}
.dec-textarea{width:100%;padding:9px 13px;border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;resize:none;outline:none;transition:border-color .18s;}
.dec-textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.1);}
.modal-close{width:30px;height:30px;border:1px solid var(--border);background:var(--white);border-radius:7px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--muted);transition:all .18s;}
.modal-close:hover{background:var(--red-p);color:var(--red);}
.btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;width:100%;}
.btn-primary:hover{background:var(--blue2);}
.btn-primary:disabled{opacity:.55;cursor:not-allowed;}
.btn-secondary{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;background:var(--light);color:var(--muted);border:1px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;}
.btn-secondary:hover{background:var(--border);color:var(--text);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;}
.sync-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;margin-right:5px;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.4;}}
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{background:var(--text);color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .25s ease;display:flex;align-items:center;gap:8px;}
.toast.success{background:#15803d;}.toast.error{background:var(--red);}
@keyframes slideIn{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:translateX(0);}}
.upload-drop{border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all .18s;color:var(--muted);}
.upload-drop:hover,.upload-drop.over{border-color:var(--blue);background:#eff6ff;color:var(--blue);}
.upload-drop input{display:none;}
@media(max-width:768px){
  body{overflow:auto;height:auto;min-height:100vh;flex-direction:column;}
  .sidebar{position:fixed;top:0;left:0;height:100%;transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .sidebar-overlay.active{display:block;}
  .hamburger{display:flex!important;align-items:center;justify-content:center;}
  main{width:100%;}
  #viewport{padding:14px;}
  .dec-modal{max-width:calc(100vw - 16px);max-height:95vh;}
  .dec-table{font-size:12px;}
  .dec-table th,.dec-table td{padding:9px 10px;}
  .chip-name{display:none;}
  header{padding:0 14px;}
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sb-overlay" onclick="closeSidebar()"></div>
<div class="toast-wrap" id="toast-wrap"></div>

<nav class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div style="width:40px;height:40px;background:#4338ca;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-chalkboard-teacher" style="color:white;font-size:16px;"></i>
    </div>
    <div><div class="brand">DEC <span>PMESP</span></div><div class="sub">PAINEL DO INSTRUTOR</div></div>
  </div>
  <nav style="flex:1;overflow-y:auto;padding:12px 0;">
    <div class="nav-section">Geral</div>
    <div class="nav-link active" onclick="router('dashboard')">
      <div class="nav-icon"><i class="fas fa-tachometer-alt"></i></div>Dashboard
    </div>
    <div class="nav-section">Gestão</div>
    <div class="nav-link" onclick="router('alunos')">
      <div class="nav-icon"><i class="fas fa-user-graduate"></i></div>Alunos
    </div>
    <div class="nav-link" onclick="router('presenca')">
      <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>Presença
    </div>
    <div class="nav-link" onclick="router('escala')">
      <div class="nav-icon"><i class="fas fa-calendar-alt"></i></div>Cronogramas
    </div>
  </nav>
  <div class="sidebar-footer">&copy; DEC PMESP &middot; Painel Instrutor</div>
</nav>

<main>
  <header>
    <div style="display:flex;align-items:center;gap:10px;">
      <button class="hamburger" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <div class="header-title" id="view-title">Dashboard</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <div class="user-chip">
        <div class="avatar"><?php echo strtoupper(substr(trim($usuNome),0,1)); ?></div>
        <span class="chip-name" style="font-size:13px;font-weight:600;color:var(--text);"><?php echo htmlspecialchars($usuNome); ?></span>
      </div>
      <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i><span class="chip-name">Sair</span></a>
    </div>
  </header>

  <div id="viewport">

    <!-- DASHBOARD -->
    <div id="dashboard" class="section-view active">
      <div class="instrutor-card" style="border-left:3px solid #4338ca;">
        <div class="instrutor-avatar" id="dash-avatar"><?php echo strtoupper(substr(trim($usuNome),0,1)); ?></div>
        <div class="instrutor-info">
          <div class="instrutor-nome" id="dash-nome"><?php echo htmlspecialchars($usuNome); ?></div>
          <div class="instrutor-role"><i class="fas fa-chalkboard-teacher"></i> Instrutor DEC</div>
          <div class="instrutor-meta">
            <div class="instrutor-meta-item"><i class="fas fa-id-badge"></i><span>RGPM:</span><span id="dash-rgpm"><?php echo htmlspecialchars($usuRgpm); ?></span></div>
            <div class="instrutor-meta-item"><i class="fab fa-discord"></i><span>Discord:</span><span id="dash-discord"><?php echo htmlspecialchars($usuDiscord); ?></span></div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon" style="background:#4338ca;"><i class="fas fa-graduation-cap"></i></div>
            <div><div class="ch-title">Cursos Ativos</div><div class="ch-sub"><span class="sync-dot"></span>Atualização automática</div></div>
          </div>
        </div>
        <div id="dash-cursos-lista" style="display:flex;flex-direction:column;gap:12px;padding:16px 20px;">
          <?php if($cursosAbertos): foreach($cursosAbertos as $c): $cor=corCurso($c["nome"]); $mat=intval($c["alunos_matriculados"]??0); ?>
          <div class="curso-card">
            <div class="curso-badge" style="background:<?php echo $cor["bg"]; ?>;color:<?php echo $cor["color"]; ?>;border:1px solid <?php echo $cor["border"]; ?>;"><?php echo $cor["label"]; ?></div>
            <div>
              <div class="curso-nome"><?php echo htmlspecialchars($c["nome"]); ?></div>
              <div class="curso-mat" style="color:<?php echo $cor["color"]; ?>;"><i class="fas fa-user-graduate"></i> <?php echo $mat; ?> aluno<?php echo $mat!=1?"s":""; ?> matriculado<?php echo $mat!=1?"s":""; ?></div>
            </div>
          </div>
          <?php endforeach; else: ?>
          <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">Nenhum curso aberto.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ALUNOS -->
    <div id="alunos" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon" style="background:var(--purple);"><i class="fas fa-user-graduate"></i></div>
            <div><div class="ch-title">Alunos</div><div class="ch-sub" id="alunos-count"><span class="sync-dot"></span>carregando...</div></div>
          </div>
          <button onclick="baixarAlunos()" style="background:var(--green-p);color:var(--green);border:1px solid #bbf7d0;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
            <i class="fas fa-file-pdf"></i><span class="chip-name"> PDF</span>
          </button>
        </div>
        <div style="padding:14px 20px;background:var(--light);border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;flex-wrap:wrap;gap:6px;" id="alunos-pills">
            <button class="pill-curso pill-todos" data-curso="" onclick="selecionarPill(this)">
              <i class="fas fa-list" style="font-size:9px;margin-right:4px;"></i>Todos
            </button>
            <?php foreach($cursosAbertos as $c): $cor=corCurso($c["nome"]); ?>
            <button class="pill-curso" data-curso="<?php echo htmlspecialchars($c["nome"]); ?>" data-bg="<?php echo $cor["bg"]; ?>" data-color="<?php echo $cor["color"]; ?>" data-bdr="<?php echo $cor["border"]; ?>" onclick="selecionarPill(this)">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $cor["color"]; ?>;margin-right:5px;vertical-align:middle;"></span><?php echo $cor["label"]; ?> &middot; <?php echo htmlspecialchars($c["nome"]); ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="text" id="busca-alunos" class="dec-input" placeholder="Buscar por nome, RGPM ou Discord..." oninput="buscaAlunosDebounce()">
        </div>
        <div id="alunos-table-wrap">
          <div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
        </div>
      </div>
    </div>

    <!-- PRESENÇA -->
    <div id="presenca" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon" style="background:#d97706;"><i class="fas fa-clipboard-list"></i></div>
            <div><div class="ch-title">Registro de Presença</div><div class="ch-sub"><span class="sync-dot"></span>Atualização automática</div></div>
          </div>
          <button onclick="abrirModalAta()" style="background:var(--blue);color:white;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
            <i class="fas fa-plus"></i> Nova Ata
          </button>
        </div>
        <div style="padding:0;" id="atas-wrap">
          <?php if($atas && count($atas)>0): ?>
          <table class="dec-table">
            <thead><tr><th>Aula</th><th>Data</th><th style="text-align:right;">Ações</th></tr></thead>
            <tbody>
              <?php foreach($atas as $ata): ?>
              <tr>
                <td class="strong"><?php echo htmlspecialchars($ata["nome_aula"]); ?></td>
                <td><?php echo date("d/m/Y H:i",strtotime($ata["data_criacao"])); ?></td>
                <td style="text-align:right;">
                  <div style="display:flex;gap:6px;justify-content:flex-end;">
                    <a href="baixar_ata.php?id=<?php echo $ata["id"]; ?>" target="_blank" class="btn-dl"><i class="fas fa-download"></i></a>
                    <button onclick="deletarAta(<?php echo $ata["id"]; ?>,'<?php echo addslashes(htmlspecialchars($ata["nome_aula"])); ?>')" class="btn-del"><i class="fas fa-trash"></i></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--muted);">
            <i class="fas fa-clipboard-list" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:10px;"></i>
            <div style="font-size:14px;">Nenhuma ata registrada.</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- CRONOGRAMAS -->
    <div id="escala" class="section-view">
      <div class="card">
        <div class="card-header">
          <div class="ch-left">
            <div class="ch-icon" style="background:#0891b2;"><i class="fas fa-calendar-alt"></i></div>
            <div><div class="ch-title">Cronogramas</div><div class="ch-sub"><span class="sync-dot"></span>Atualização automática</div></div>
          </div>
          <button onclick="abrirModalCronograma()" style="background:var(--blue);color:white;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
            <i class="fas fa-upload"></i> Importar
          </button>
        </div>
        <div id="cronogramas-wrap" style="padding:0;">
          <?php if($cronogramas): foreach($cronogramas as $cron): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:40px;height:40px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:16px;"><i class="fas fa-file-pdf"></i></div>
              <div>
                <div style="font-size:14px;font-weight:700;color:var(--text);"><?php echo htmlspecialchars($cron["titulo"]); ?></div>
                <div style="font-size:11px;color:var(--muted);"><?php echo date("d/m/Y",strtotime($cron["data_upload"])); ?></div>
              </div>
            </div>
            <div style="display:flex;gap:8px;">
              <a href="baixar_cronograma.php?id=<?php echo $cron["id"]; ?>" target="_blank" class="btn-dl"><i class="fas fa-download"></i> Baixar</a>
              <button onclick="deletarCronograma(<?php echo $cron["id"]; ?>,'<?php echo addslashes(htmlspecialchars($cron["titulo"])); ?>')" class="btn-del"><i class="fas fa-trash"></i></button>
            </div>
          </div>
          <?php endforeach; else: ?>
          <div style="text-align:center;padding:40px;color:var(--muted);">
            <i class="fas fa-calendar-alt" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
            <div style="font-size:14px;">Nenhum cronograma disponível.</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /viewport -->
</main>

<!-- MODAL NOVA ATA -->
<div id="modalAta" class="dec-modal-bd" style="display:none;">
  <div class="dec-modal" style="max-width:540px;">
    <div class="dec-modal-header">
      <div class="dec-modal-title"><i class="fas fa-clipboard-list" style="color:#d97706;margin-right:7px;"></i>Nova Ata de Presença</div>
      <button onclick="fecharModalAta()" class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <div class="dec-modal-body">
      <div><label class="field-label">Curso</label>
        <select id="ata_curso" class="dec-select">
          <option value="">Selecione...</option>
          <?php foreach($cursosAbertos as $c): ?>
          <option value="<?php echo htmlspecialchars($c["nome"]); ?>"><?php echo htmlspecialchars($c["nome"]); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="field-label">Nome da Aula</label><input type="text" id="ata_aula" class="dec-input" placeholder="Ex: Aula 01 - Abordagem"></div>
      <div><label class="field-label">Instrutor</label><input type="text" id="ata_instrutor" class="dec-input" value="<?php echo htmlspecialchars($usuNome); ?>"></div>
      <div>
        <label class="field-label">Lista de Presença <span style="font-size:10px;color:var(--muted);">(um por linha: Nome - RGPM)</span></label>
        <textarea id="ata_lista" class="dec-textarea" rows="8" placeholder="João Silva - 123456&#10;Maria Santos - 789012"></textarea>
      </div>
      <div><label class="field-label">Observações</label><textarea id="ata_obs" class="dec-textarea" rows="2" placeholder="Observações adicionais..."></textarea></div>
    </div>
    <div class="dec-modal-foot" style="display:flex;gap:10px;">
      <button onclick="fecharModalAta()" class="btn-secondary" style="flex:1;">Cancelar</button>
      <button onclick="gerarAta()" id="ata-btn-gerar" class="btn-primary" style="flex:2;background:#16a34a;"><i class="fas fa-file-pdf"></i> Gerar Ata PDF</button>
    </div>
  </div>
</div>

<!-- MODAL IMPORTAR CRONOGRAMA -->
<div id="modalCronograma" class="dec-modal-bd" style="display:none;">
  <div class="dec-modal" style="max-width:460px;">
    <div class="dec-modal-header">
      <div class="dec-modal-title"><i class="fas fa-upload" style="color:#0891b2;margin-right:7px;"></i>Importar Cronograma</div>
      <button onclick="fecharModalCronograma()" class="modal-close"><i class="fas fa-times"></i></button>
    </div>
    <div class="dec-modal-body">
      <div><label class="field-label">Título</label><input type="text" id="cron_titulo" class="dec-input" placeholder="Ex: Cronograma CFSD - Turma 01"></div>
      <div>
        <label class="field-label">Arquivo</label>
        <div class="upload-drop" id="cron-drop" onclick="document.getElementById('cron_arquivo').click()">
          <input type="file" id="cron_arquivo" accept=".pdf,.jpg,.jpeg,.png,.docx" onchange="onArquivoSelecionado(this)">
          <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:8px;display:block;"></i>
          <div id="cron-drop-txt" style="font-size:13px;font-weight:600;">Clique ou arraste o arquivo aqui</div>
          <div style="font-size:11px;margin-top:4px;">PDF, JPG, PNG ou DOCX</div>
        </div>
      </div>
    </div>
    <div class="dec-modal-foot" style="display:flex;gap:10px;">
      <button onclick="fecharModalCronograma()" class="btn-secondary" style="flex:1;">Cancelar</button>
      <button onclick="importarCronograma()" id="cron-btn-salvar" class="btn-primary" style="flex:2;background:#0891b2;"><i class="fas fa-upload"></i> Importar</button>
    </div>
  </div>
</div>
<script>
// ── UTILS ─────────────────────────────────────────────────
function escHtml(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function toast(msg,type){var w=document.getElementById('toast-wrap');var t=document.createElement('div');t.className='toast '+(type||'');t.innerHTML=msg;w.appendChild(t);setTimeout(function(){t.remove();},3500);}
function toggleSidebar(){var s=document.getElementById('sidebar'),o=document.getElementById('sb-overlay');s.classList.toggle('open');o.classList.toggle('active');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sb-overlay').classList.remove('active');}
function formatDate(str,withTime){if(!str)return'—';try{var d=new Date(str.replace(' ','T'));var dd=String(d.getDate()).padStart(2,'0'),mm=String(d.getMonth()+1).padStart(2,'0'),yy=d.getFullYear();if(withTime){var hh=String(d.getHours()).padStart(2,'0'),mi=String(d.getMinutes()).padStart(2,'0');return dd+'/'+mm+'/'+yy+' '+hh+':'+mi;}return dd+'/'+mm+'/'+yy;}catch(e){return str;}}
function corCurso(nome){var n=(nome||'').toUpperCase();if(n.indexOf('CFSD')>=0)return{bg:'#dbeafe',color:'#1e40af',border:'#bfdbfe',label:'CFSD'};if(n.indexOf('CFC')>=0)return{bg:'#dcfce7',color:'#15803d',border:'#bbf7d0',label:'CFC'};if(n.indexOf('CFO')>=0)return{bg:'#fef3c7',color:'#92400e',border:'#fde68a',label:'CFO'};if(n.indexOf('CFS')>=0)return{bg:'#ede9fe',color:'#4338ca',border:'#ddd6fe',label:'CFS'};return{bg:'#f1f5f9',color:'#475569',border:'#e2e8f0',label:'CURSO'};}

// ── ESTADO ────────────────────────────────────────────────
var _viewAtual='dashboard', _pillAtivo='', _modalAberto=false, _buscaTimer=null;
var _lastCron='', _lastAtas='', _lastCursos='', _lastAlunosHash='';

// ── MODAIS ────────────────────────────────────────────────
function abrirModalAta(){_modalAberto=true;document.getElementById('modalAta').style.display='flex';}
function fecharModalAta(){_modalAberto=false;document.getElementById('modalAta').style.display='none';}
function abrirModalCronograma(){_modalAberto=true;document.getElementById('modalCronograma').style.display='flex';}
function fecharModalCronograma(){
    _modalAberto=false;
    document.getElementById('modalCronograma').style.display='none';
    document.getElementById('cron_titulo').value='';
    document.getElementById('cron_arquivo').value='';
    document.getElementById('cron-drop-txt').textContent='Clique ou arraste o arquivo aqui';
}

// ── RENDER HELPERS ────────────────────────────────────────
function renderPills(cursos){
    var wrap=document.getElementById('alunos-pills');
    if(!wrap)return;
    var html='<button class="pill-curso pill-todos" data-curso="" onclick="selecionarPill(this)"><i class="fas fa-list" style="font-size:9px;margin-right:4px;"></i>Todos</button>';
    (cursos||[]).forEach(function(c){
        var cor=corCurso(c.nome);
        html+='<button class="pill-curso"'
            +' data-curso="'+escHtml(c.nome)+'"'
            +' data-bg="'+cor.bg+'"'
            +' data-color="'+cor.color+'"'
            +' data-bdr="'+cor.border+'"'
            +' onclick="selecionarPill(this)">'
            +'<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:'+cor.color+';margin-right:5px;vertical-align:middle;"></span>'
            +cor.label+' &middot; '+escHtml(c.nome)+'</button>';
    });
    wrap.innerHTML=html;
    if(_pillAtivo&&!(cursos||[]).some(function(c){return c.nome===_pillAtivo;}))_pillAtivo='';
    wrap.querySelectorAll('.pill-curso').forEach(function(p){
        if(!_pillAtivo&&p.dataset.curso==='')p.classList.add('pill-todos');
        else if(_pillAtivo&&p.dataset.curso===_pillAtivo){p.style.background=p.dataset.bg;p.style.color=p.dataset.color;p.style.borderColor=p.dataset.bdr;p.style.fontWeight='800';}
    });
}

function renderCronogramas(lista){
    var wrap=document.getElementById('cronogramas-wrap');if(!wrap)return;
    if(!lista||!lista.length){
        wrap.innerHTML='<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-calendar-alt" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i><div style="font-size:14px;">Nenhum cronograma disponível.</div></div>';
        return;
    }
    wrap.innerHTML=lista.map(function(c){
        var tit=escHtml(c.titulo);
        return '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border);">'
            +'<div style="display:flex;align-items:center;gap:12px;">'
            +'<div style="width:40px;height:40px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:16px;"><i class="fas fa-file-pdf"></i></div>'
            +'<div><div style="font-size:14px;font-weight:700;color:var(--text);">'+tit+'</div>'
            +'<div style="font-size:11px;color:var(--muted);">'+formatDate(c.data_upload)+'</div></div></div>'
            +'<div style="display:flex;gap:8px;">'
            +'<a href="baixar_cronograma.php?id='+c.id+'" target="_blank" class="btn-dl"><i class="fas fa-download"></i> Baixar</a>'
            +'<button onclick="deletarCronograma('+c.id+',\''+tit.replace(/\'/g,'\\\'')+'\')" class="btn-del"><i class="fas fa-trash"></i></button>'
            +'</div></div>';
    }).join('');
}

function renderAtas(lista){
    var wrap=document.getElementById('atas-wrap');if(!wrap)return;
    if(!lista||!lista.length){
        wrap.innerHTML='<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-clipboard-list" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:10px;"></i><div style="font-size:14px;">Nenhuma ata registrada.</div></div>';
        return;
    }
    var rows=lista.map(function(a){
        var nm=escHtml(a.nome_aula);
        return '<tr><td class="strong">'+nm+'</td><td>'+formatDate(a.data_criacao,true)+'</td>'
            +'<td style="text-align:right;"><div style="display:flex;gap:6px;justify-content:flex-end;">'
            +'<a href="baixar_ata.php?id='+a.id+'" target="_blank" class="btn-dl"><i class="fas fa-download"></i></a>'
            +'<button onclick="deletarAta('+a.id+',\''+nm.replace(/\'/g,'\\\'')+'\')" class="btn-del"><i class="fas fa-trash"></i></button>'
            +'</div></td></tr>';
    }).join('');
    wrap.innerHTML='<table class="dec-table"><thead><tr><th>Aula</th><th>Data</th><th style="text-align:right;">Ações</th></tr></thead><tbody>'+rows+'</tbody></table>';
}

// ── DASHBOARD ─────────────────────────────────────────────
function atualizarDashboard(){
    if(_modalAberto)return;
    var elNome=document.getElementById('dash-nome');
    var elRgpm=document.getElementById('dash-rgpm');
    var elDisc=document.getElementById('dash-discord');
    var elAvatar=document.getElementById('dash-avatar');
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=meus_dados'})
    .then(function(r){return r.json();}).then(function(d){
        if(d.force_logout){window.location.href='logout.php';return;}
        if(!d.sucesso)return;
        if(elNome&&elNome.textContent!==d.nome){elNome.textContent=d.nome;var ch=document.querySelector('.chip-name');if(ch)ch.textContent=d.nome;if(elAvatar)elAvatar.textContent=(d.nome||'').trim().charAt(0).toUpperCase();}
        if(elRgpm&&elRgpm.textContent!==(d.rgpm||'—'))elRgpm.textContent=d.rgpm||'—';
        if(elDisc&&elDisc.textContent!==(d.discord||'—'))elDisc.textContent=d.discord||'—';
    }).catch(function(){});
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=dashboard_stats'})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.sucesso)return;
        var cursosStr=JSON.stringify(d.cursos);
        if(cursosStr!==_lastCursos){
            _lastCursos=cursosStr;
            var cl=document.getElementById('dash-cursos-lista');
            if(cl){
                if(d.cursos&&d.cursos.length){
                    cl.innerHTML=d.cursos.map(function(c){
                        var cor=corCurso(c.nome),mat=parseInt(c.alunos_matriculados||0);
                        return '<div class="curso-card">'
                            +'<div class="curso-badge" style="background:'+cor.bg+';color:'+cor.color+';border:1px solid '+cor.border+';">'+cor.label+'</div>'
                            +'<div><div class="curso-nome">'+escHtml(c.nome)+'</div>'
                            +'<div class="curso-mat" style="color:'+cor.color+';"><i class="fas fa-user-graduate"></i> '+mat+' aluno'+(mat!==1?'s':'')+' matriculado'+(mat!==1?'s':'')+'</div>'
                            +'</div></div>';
                    }).join('');
                }else{cl.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">Nenhum curso aberto.</div>';}
            }
            renderPills(d.cursos);
            if(_viewAtual==='alunos')atualizarAlunos(true);
        }
        var cStr=JSON.stringify(d.cronogramas);
        if(cStr!==_lastCron){_lastCron=cStr;renderCronogramas(d.cronogramas);}
        var aStr=JSON.stringify(d.atas);
        if(aStr!==_lastAtas){_lastAtas=aStr;renderAtas(d.atas);}
    }).catch(function(){});
}

// ── DELETAR ───────────────────────────────────────────────
function deletarAta(id,nome){
    if(!confirm('Excluir a ata "'+nome+'"? Esta ação não pode ser desfeita.'))return;
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=deletar_ata&id='+id})
    .then(function(r){return r.json();}).then(function(d){if(d.sucesso){toast('✅ Ata excluída!','success');_lastAtas='';atualizarDashboard();}else toast('❌ '+(d.erro||'Falha'),'error');});
}
function deletarCronograma(id,titulo){
    if(!confirm('Excluir "'+titulo+'"? Esta ação não pode ser desfeita.'))return;
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=deletar_cronograma&id='+id})
    .then(function(r){return r.json();}).then(function(d){if(d.sucesso){toast('✅ Cronograma excluído!','success');_lastCron='';atualizarDashboard();}else toast('❌ '+(d.erro||'Falha'),'error');});
}

// ── ALUNOS ────────────────────────────────────────────────
function selecionarPill(el){
    document.querySelectorAll('.pill-curso').forEach(function(p){p.classList.remove('pill-todos');p.style.background='';p.style.color='';p.style.borderColor='';p.style.fontWeight='';});
    _pillAtivo=el.dataset.curso;
    if(!_pillAtivo)el.classList.add('pill-todos');
    else{el.style.background=el.dataset.bg||'#f1f5f9';el.style.color=el.dataset.color||'#475569';el.style.borderColor=el.dataset.bdr||'#e2e8f0';el.style.fontWeight='800';}
    atualizarAlunos(false);
}
function buscaAlunosDebounce(){if(_buscaTimer)clearTimeout(_buscaTimer);_buscaTimer=setTimeout(function(){atualizarAlunos(false);},400);}
function atualizarAlunos(silencioso){
    var busca=(document.getElementById('busca-alunos')||{value:''}).value||'';
    var wrap=document.getElementById('alunos-table-wrap');if(!wrap)return;
    if(!silencioso)wrap.innerHTML='<div style="text-align:center;padding:40px;color:var(--muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=listar_alunos&busca_curso='+encodeURIComponent(_pillAtivo)+'&busca='+encodeURIComponent(busca)})
    .then(function(r){return r.json();}).then(function(d){
        var novoHash=JSON.stringify([d.total,d.alunos]);
        if(silencioso&&novoHash===_lastAlunosHash)return;
        _lastAlunosHash=novoHash;
        var ac=document.getElementById('alunos-count');
        if(ac)ac.innerHTML='<span class="sync-dot"></span>'+d.total+' aluno(s)';
        if(!d.alunos||!d.alunos.length){wrap.innerHTML='<div style="text-align:center;padding:50px;color:var(--muted);font-size:13px;"><i class="fas fa-user-graduate" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i>Nenhum aluno encontrado.</div>';return;}
        var rows=d.alunos.map(function(a,i){
            var cor=corCurso(a.meus_cursos||'');
            var tagCurso=a.meus_cursos
                ?'<span class="badge" style="background:'+cor.bg+';color:'+cor.color+';border:1px solid '+cor.border+';">'+cor.label+'</span><div style="font-size:10px;color:var(--muted);margin-top:2px;">'+escHtml(a.meus_cursos)+'</div>'
                :'<span class="badge badge-gray">—</span>';
            var tagStatus=a.status==='Aprovado'?'<span class="badge badge-green"><i class="fas fa-check" style="font-size:9px;"></i> Aprovado</span>'
                :a.status==='Ativo'?'<span class="badge badge-blue"><i class="fas fa-circle" style="font-size:7px;"></i> Ativo</span>'
                :a.status==='Pendente'?'<span class="badge badge-yellow">Pendente</span>'
                :'<span class="badge badge-gray">'+escHtml(a.status||'—')+'</span>';
            return '<tr><td style="color:var(--muted);font-size:12px;">'+(i+1)+'</td>'
                +'<td class="strong">'+escHtml(a.nome||'—')+'</td>'
                +'<td>'+escHtml(a.rgpm||'—')+'</td>'
                +'<td>'+escHtml(a.discord||'—')+'</td>'
                +'<td>'+tagCurso+'</td><td>'+tagStatus+'</td></tr>';
        }).join('');
        wrap.innerHTML='<div style="overflow-x:auto;"><table class="dec-table"><thead><tr><th>#</th><th>Nome</th><th>RGPM</th><th>Discord</th><th>Curso</th><th>Status</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
    }).catch(function(){if(!silencioso)wrap.innerHTML='<div style="text-align:center;padding:40px;color:var(--red);font-size:13px;">Erro ao carregar alunos.</div>';});
}
function baixarAlunos(){
    var busca=(document.getElementById('busca-alunos')||{value:''}).value||'';
    fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=listar_alunos&busca_curso='+encodeURIComponent(_pillAtivo)+'&busca='+encodeURIComponent(busca)})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.sucesso||!d.alunos.length){alert('Nenhum aluno para exportar.');return;}
        var jsPDF=window.jspdf.jsPDF,doc=new jsPDF(),hoje=new Date().toLocaleDateString('pt-BR');
        doc.setFillColor(13,27,46);doc.rect(0,0,210,28,'F');doc.setTextColor(255,255,255);doc.setFontSize(14);doc.setFont(undefined,'bold');
        doc.text('LISTA DE ALUNOS - DEC PMESP',105,18,{align:'center'});
        doc.autoTable({startY:36,head:[['#','Nome','RGPM','Discord','Curso','Status']],
            body:d.alunos.map(function(a,i){return[i+1,a.nome||'—',a.rgpm||'—',a.discord||'—',a.meus_cursos||'—',a.status||'—'];}),
            headStyles:{fillColor:[13,27,46],textColor:255,fontStyle:'bold',fontSize:9},bodyStyles:{fontSize:8},
            alternateRowStyles:{fillColor:[240,247,255]},margin:{left:10,right:10}});
        var tp=doc.internal.getNumberOfPages();
        for(var p=1;p<=tp;p++){doc.setPage(p);doc.setFontSize(7);doc.setTextColor(150);doc.text('Pag. '+p+'/'+tp+' - DEC PMESP - '+hoje,105,292,{align:'center'});}
        doc.save('Alunos_'+hoje.replace(/\//g,'-')+'.pdf');
    });
}

// ── MODAL ATA ─────────────────────────────────────────────
function gerarAta(){
    var jsPDF=window.jspdf.jsPDF;
    var aula=document.getElementById('ata_aula').value.trim();
    var inst=document.getElementById('ata_instrutor').value.trim();
    var lista=document.getElementById('ata_lista').value.trim();
    var obs=document.getElementById('ata_obs').value.trim();
    var curso=document.getElementById('ata_curso').value;
    if(!aula||!lista){alert('Nome da aula e lista de presença são obrigatórios!');return;}
    var btn=document.getElementById('ata-btn-gerar');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Gerando...';
    var doc=new jsPDF();
    doc.setFillColor(13,27,46);doc.rect(0,0,210,30,'F');doc.setTextColor(255,255,255);doc.setFontSize(20);doc.setFont(undefined,'bold');
    doc.text('ATA DE REGISTRO E FREQUENCIA',105,20,{align:'center'});
    doc.setTextColor(50,50,50);doc.setFontSize(10);
    doc.text('CURSO: '+(curso||'N/A'),14,40);doc.text('AULA: '+aula.toUpperCase(),14,46);doc.text('INSTRUTOR: '+inst,14,52);
    if(obs)doc.text('OBS: '+obs,14,58);
    var ls=lista.split('\n').filter(function(l){return l.trim();});
    doc.autoTable({startY:obs?66:60,head:[['LISTAGEM DE ALUNOS PRESENTES']],body:ls.map(function(l){return[l.trim()];}),headStyles:{fillColor:[13,27,46],textColor:255,fontStyle:'bold'}});
    var pb=doc.output('blob');var pf=new File([pb],'relatorio.pdf',{type:'application/pdf'});
    var fd=new FormData();fd.append('meu_pdf',pf);fd.append('nome_aula',aula);
    fetch('salvar_ata.php',{method:'POST',body:fd})
    .then(function(r){return r.text();}).then(function(){
        btn.disabled=false;btn.innerHTML='<i class="fas fa-file-pdf"></i> Gerar Ata PDF';
        doc.save('ATA_'+aula.replace(/\s+/g,'_')+'.pdf');
        fecharModalAta();toast('✅ Ata gerada e salva!','success');
        _lastAtas='';atualizarDashboard();
    }).catch(function(e){btn.disabled=false;btn.innerHTML='<i class="fas fa-file-pdf"></i> Gerar Ata PDF';toast('❌ Erro: '+e.message,'error');});
}

// ── MODAL CRONOGRAMA ──────────────────────────────────────
function onArquivoSelecionado(input){if(input.files[0])document.getElementById('cron-drop-txt').textContent='📎 '+input.files[0].name;}
function importarCronograma(){
    var titulo=document.getElementById('cron_titulo').value.trim();
    var arquivo=document.getElementById('cron_arquivo').files[0];
    if(!titulo){toast('❌ Informe o título','error');return;}
    if(!arquivo){toast('❌ Selecione um arquivo','error');return;}
    var btn=document.getElementById('cron-btn-salvar');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Importando...';
    var fd=new FormData();fd.append('ajax_action','importar_cronograma');fd.append('titulo',titulo);fd.append('arquivo',arquivo);
    fetch('painel_instrutor.php',{method:'POST',body:fd})
    .then(function(r){return r.json();}).then(function(d){
        btn.disabled=false;btn.innerHTML='<i class="fas fa-upload"></i> Importar';
        if(d.sucesso){toast('✅ Cronograma importado!','success');fecharModalCronograma();_lastCron='';atualizarDashboard();}
        else toast('❌ '+(d.erro||'Falha'),'error');
    }).catch(function(){btn.disabled=false;btn.innerHTML='<i class="fas fa-upload"></i> Importar';toast('❌ Erro de conexão','error');});
}

// ── ROUTER ────────────────────────────────────────────────
function router(id){
    _viewAtual=id;
    document.querySelectorAll('.section-view').forEach(function(s){s.classList.remove('active');});
    var t=document.getElementById(id);if(t)t.classList.add('active');
    document.querySelectorAll('.nav-link').forEach(function(l){
        l.classList.remove('active');
        if((l.getAttribute('onclick')||'').indexOf("'"+id+"'")>-1)l.classList.add('active');
    });
    var ts={dashboard:'Dashboard',alunos:'Alunos',presenca:'Presença',escala:'Cronogramas'};
    document.getElementById('view-title').textContent=ts[id]||id;
    if(id==='alunos')atualizarAlunos(false);
    closeSidebar();
}

// ── INIT ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',function(){
    var drop=document.getElementById('cron-drop');
    if(drop){
        drop.addEventListener('dragover',function(e){e.preventDefault();drop.classList.add('over');});
        drop.addEventListener('dragleave',function(){drop.classList.remove('over');});
        drop.addEventListener('drop',function(e){e.preventDefault();drop.classList.remove('over');var f=e.dataTransfer.files[0];if(f){document.getElementById('cron_arquivo').files=e.dataTransfer.files;document.getElementById('cron-drop-txt').textContent='📎 '+f.name;}});
    }
    router('dashboard');
    atualizarDashboard();
    // Polling a cada 30s — pausa quando modal aberto
    setInterval(function(){if(!_modalAberto)atualizarDashboard();},30000);
    // Verificação de nível a cada 30s
    setInterval(function(){
        fetch('painel_instrutor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_action=verificar_nivel'})
        .then(function(r){return r.json();}).then(function(d){
            if(d.logout){
                var ov=document.createElement('div');
                ov.style.cssText='position:fixed;inset:0;z-index:999999;background:rgba(13,27,46,0.93);display:flex;align-items:center;justify-content:center;';
                ov.innerHTML='<div style="background:#fff;border-radius:16px;padding:32px 28px;max-width:380px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4);">'
                    +'<div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><i class="fas fa-user-shield" style="color:#dc2626;font-size:26px;"></i></div>'
                    +'<div style="font-size:17px;font-weight:800;color:#1e293b;margin-bottom:8px;">Seu nível foi alterado</div>'
                    +'<div style="font-size:13px;color:#64748b;margin-bottom:20px;line-height:1.6;">Seu acesso foi modificado.<br>Você será redirecionado para fazer login novamente.</div>'
                    +'<div style="color:#64748b;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Redirecionando...</div>'
                    +'</div>';
                document.body.appendChild(ov);
                setTimeout(function(){window.location.href='logout.php';},2500);
            }
        }).catch(function(){});
    },30000);
});
</script>
</body>
</html>