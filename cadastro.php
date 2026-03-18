<?php
/**
 * cadastro.php — Registro de novos usuários
 * Correções: display_errors desativado, todos os erros logados apenas no servidor
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
require_once 'conexao.php';

if (isset($_SESSION['usuario'])) {
    header('Location: painel.php');
    exit;
}

$mensagem = '';
$tipo     = '';
$blMotivoExibir = '';
$blTempoExibir  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome      = trim($_POST['nome']      ?? '');
    $discord   = trim($_POST['id']        ?? '');
    $rgpm      = trim($_POST['rgpm']      ?? '');
    $senha     = trim($_POST['senha']     ?? '');
    $confirmar = trim($_POST['confirmar'] ?? '');
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';

    // Dados de fingerprint
    $fpUserAgent  = trim($_POST['fp_user_agent']  ?? $_SERVER['HTTP_USER_AGENT'] ?? '');
    $fpIdioma     = trim($_POST['fp_idioma']      ?? '');
    $fpTimezone   = trim($_POST['fp_timezone']    ?? '');
    $fpResolucao  = trim($_POST['fp_resolucao']   ?? '');
    $fpCores      = (int) ($_POST['fp_cores']     ?? 0);
    $fpPlataforma = trim($_POST['fp_plataforma']  ?? '');
    $fpPlugins    = trim($_POST['fp_plugins']     ?? '');
    $fpCanvas     = trim($_POST['fp_canvas_hash'] ?? '');
    $fpWebgl      = trim($_POST['fp_webgl_hash']  ?? '');
    $fpAudio      = trim($_POST['fp_audio_hash']  ?? '');
    $fpFonts      = trim($_POST['fp_fonts']       ?? '');
    $fpTouch      = (int) ($_POST['fp_touch']     ?? 0);
    $fpDnt        = trim($_POST['fp_dnt']         ?? '');

    // Validações básicas
    if ($nome === '' || $discord === '' || $rgpm === '' || $senha === '') {
        $mensagem = 'Preencha todos os campos.';
        $tipo     = 'erro';
    } elseif (strlen($senha) < 6) {
        $mensagem = 'A senha deve ter pelo menos 6 caracteres.';
        $tipo     = 'erro';
    } elseif ($senha !== $confirmar) {
        $mensagem = 'As senhas não coincidem.';
        $tipo     = 'erro';
    } else {
        // ── Verifica blacklist (por RGPM, Discord, IP, fingerprints) ──────────
        $blWhere  = [];
        $blTipos  = '';
        $blParams = [];

        if ($rgpm)     { $blWhere[] = 'rgpm = ?';                                      $blTipos .= 's'; $blParams[] = $rgpm; }
        if ($discord)  { $blWhere[] = 'discord = ?';                                   $blTipos .= 's'; $blParams[] = $discord; }
        if ($ip)       { $blWhere[] = 'ip_publico = ?';                                $blTipos .= 's'; $blParams[] = $ip; }
        if ($fpCanvas) { $blWhere[] = "(fp_canvas_hash = ? AND fp_canvas_hash != '')";  $blTipos .= 's'; $blParams[] = $fpCanvas; }
        if ($fpWebgl)  { $blWhere[] = "(fp_webgl_hash  = ? AND fp_webgl_hash  != '')";  $blTipos .= 's'; $blParams[] = $fpWebgl; }
        if ($fpAudio)  { $blWhere[] = "(fp_audio_hash  = ? AND fp_audio_hash  != '')";  $blTipos .= 's'; $blParams[] = $fpAudio; }

        $blBloqueado = false;

        if (!empty($blWhere)) {
            $blRow = db_row($conexao,
                'SELECT id, motivo_tipo, motivo_texto, tempo, expiracao FROM blacklist WHERE (' . implode(' OR ', $blWhere) . ') LIMIT 1',
                $blTipos,
                $blParams
            );
            if ($blRow) {
                $ativo = true;
                if ($blRow['tempo'] === 'temporario' && !empty($blRow['expiracao'])) {
                    $tz  = new DateTimeZone('America/Sao_Paulo');
                    $exp = new DateTime($blRow['expiracao'], $tz);
                    $now = new DateTime('now', $tz);
                    $ativo = $exp > $now;
                }
                if ($ativo) {
                    $blBloqueado    = true;
                    $blMotivoExibir = $blRow['motivo_tipo'] === 'texto'
                        ? htmlspecialchars($blRow['motivo_texto'] ?? 'Não informado.')
                        : 'Motivo em arquivo PDF — consulte a administração.';
                    $blTempoExibir  = $blRow['tempo'] === 'permanente'
                        ? 'Permanente'
                        : 'Temporário (até ' . date('d/m/Y', strtotime($blRow['expiracao'])) . ')';
                }
            }
        }

        if ($blBloqueado) {
            $tipo = 'blacklist';
        } else {
            // Verifica RGPM duplicado
            $rgpmExiste = db_val($conexao, 'SELECT id FROM usuarios WHERE rgpm = ? LIMIT 1', 's', [$rgpm]);
            if ($rgpmExiste !== null) {
                $mensagem = 'Este RGPM já está cadastrado.';
                $tipo     = 'erro';
            } else {
                // Verifica IP duplicado
                $ipExiste = db_val($conexao, 'SELECT id FROM usuarios WHERE ip_publico = ? LIMIT 1', 's', [$ip]);
                if ($ipExiste !== null) {
                    $mensagem = 'Já existe uma conta cadastrada neste dispositivo.';
                    $tipo     = 'erro';
                } else {
                    $senhaHash = password_hash($senha, PASSWORD_BCRYPT);

                    // Tenta inserir com fingerprint completo
                    $ok = db_query($conexao,
                        "INSERT INTO usuarios
                            (nome, rgpm, discord, senha, ip_publico, nivel,
                             fp_user_agent, fp_idioma, fp_timezone, fp_resolucao, fp_cores_tela,
                             fp_plataforma, fp_plugins, fp_canvas_hash, fp_webgl_hash,
                             fp_audio_hash, fp_fonts, fp_touch, fp_do_not_track, fp_ip_publico, fp_coletado_em)
                         VALUES (?,?,?,?,?,3,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                        'sssssssssissssssiss',
                        [
                            $nome, $rgpm, $discord, $senhaHash, $ip,
                            $fpUserAgent, $fpIdioma, $fpTimezone, $fpResolucao, $fpCores,
                            $fpPlataforma, $fpPlugins, $fpCanvas, $fpWebgl,
                            $fpAudio, $fpFonts, $fpTouch, $fpDnt, $ip
                        ]
                    );

                    if ($ok === false) {
                        // Fallback: colunas de fingerprint ainda não existem nesta instância
                        $ok = db_query($conexao,
                            'INSERT INTO usuarios (nome, rgpm, discord, senha, ip_publico, nivel) VALUES (?,?,?,?,?,3)',
                            'sssss',
                            [$nome, $rgpm, $discord, $senhaHash, $ip]
                        );
                    }

                    if ($ok) {
                        $tipo = 'sucesso';
                    } else {
                        $mensagem = 'Erro ao cadastrar. Tente novamente.';
                        $tipo     = 'erro';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Cadastro · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0d1b2e;--blue:#1a56db;--blue2:#1e40af;--white:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--red:#dc2626;--red-p:#fef2f2;--green:#15803d;--green-p:#dcfce7;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--navy);font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;padding:24px 20px;position:relative;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 15% 10%,rgba(26,86,219,.2) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 85% 90%,rgba(124,58,237,.13) 0%,transparent 60%);pointer-events:none;}
body::after{content:'';position:fixed;inset:0;background-image:radial-gradient(rgba(255,255,255,.055) 1px,transparent 1px);background-size:30px 30px;pointer-events:none;}
.card{position:relative;z-index:1;width:100%;max-width:460px;background:var(--white);border-radius:20px;box-shadow:0 24px 64px rgba(0,0,0,.5),0 8px 24px rgba(0,0,0,.3);overflow:hidden;animation:slideUp .4s cubic-bezier(.2,.8,.2,1) both;}
@keyframes slideUp{from{opacity:0;transform:translateY(28px);}to{opacity:1;transform:translateY(0);}}
.card-header{background:linear-gradient(135deg,#0d1b2e 0%,#1a3a6b 100%);padding:28px 36px 24px;text-align:center;position:relative;overflow:hidden;}
.card-header::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.04);}
.card-header::after{content:'';position:absolute;bottom:-50px;left:30px;width:110px;height:110px;border-radius:50%;background:rgba(96,165,250,.07);}
.logo-wrap{width:56px;height:56px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 13px;position:relative;z-index:1;}
.logo-wrap img{width:36px;height:36px;object-fit:contain;}
.logo-wrap i{font-size:22px;color:#93c5fd;display:none;}
.card-title{font-family:'Sora',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:4px;position:relative;z-index:1;}
.card-subtitle{font-size:11px;color:rgba(255,255,255,.4);font-weight:500;letter-spacing:.06em;text-transform:uppercase;position:relative;z-index:1;}
.card-divider{height:1px;background:linear-gradient(90deg,transparent,rgba(26,86,219,.35),transparent);}
.card-body{padding:28px 36px 24px;}
.alerta{display:flex;align-items:flex-start;gap:10px;border-radius:10px;padding:12px 14px;margin-bottom:22px;font-size:13px;font-weight:600;}
.alerta-erro{background:var(--red-p);border:1px solid #fecaca;color:var(--red);animation:shake .35s ease;}
.alerta-sucesso{background:var(--green-p);border:1px solid #bbf7d0;color:var(--green);}
@keyframes shake{0%,100%{transform:translateX(0);}20%,60%{transform:translateX(-5px);}40%,80%{transform:translateX(5px);}}
.alerta i{font-size:15px;flex-shrink:0;margin-top:1px;}
.fields-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;}
.field-full{grid-column:1/-1;}
.field-group{margin-bottom:16px;}
.field-label{display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.field-wrap{position:relative;}
.field-input{width:100%;padding:11px 13px 11px 40px;border:1.5px solid var(--border);border-radius:10px;font-family:'Inter',sans-serif;font-size:13.5px;font-weight:500;color:var(--text);background:#f8fafc;outline:none;transition:border-color .18s,box-shadow .18s,background .18s;}
.field-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(26,86,219,.1);}
.field-input::placeholder{color:#94a3b8;font-weight:400;}
.field-ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:12px;color:#94a3b8;pointer-events:none;transition:color .18s;}
.field-wrap:focus-within .field-ico{color:var(--blue);}
.btn-toggle-pw{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:12px;color:#94a3b8;padding:4px;border-radius:5px;transition:color .18s;}
.btn-toggle-pw:hover{color:var(--blue);}
.btn-cadastrar{width:100%;padding:13px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:.02em;box-shadow:0 4px 16px rgba(21,128,61,.3);transition:all .2s;margin-top:6px;}
.btn-cadastrar:hover{background:linear-gradient(135deg,#15803d,#166534);box-shadow:0 6px 24px rgba(21,128,61,.45);transform:translateY(-1px);}
.btn-cadastrar:active{transform:translateY(0);}
.form-sucesso-wrap{text-align:center;padding:10px 0 6px;}
.sucesso-icon{width:64px;height:64px;border-radius:50%;background:var(--green-p);border:2px solid #bbf7d0;display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--green);margin:0 auto 16px;}
.sucesso-titulo{font-family:'Sora',sans-serif;font-size:17px;font-weight:700;color:var(--text);margin-bottom:6px;}
.sucesso-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}
.btn-ir-login{display:inline-flex;align-items:center;gap:7px;padding:11px 24px;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(26,86,219,.35);transition:all .2s;}
.btn-ir-login:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(26,86,219,.45);}
.card-footer{padding:14px 36px 20px;text-align:center;border-top:1px solid var(--border);background:#f8fafc;}
.card-footer p{font-size:13px;color:var(--muted);}
.card-footer a{color:var(--blue);font-weight:700;text-decoration:none;transition:color .18s;}
.card-footer a:hover{color:var(--blue2);}
.page-footer{position:fixed;bottom:18px;left:0;right:0;text-align:center;z-index:1;font-size:11px;color:rgba(255,255,255,.18);letter-spacing:.05em;pointer-events:none;}
@media(max-width:500px){.fields-grid{grid-template-columns:1fr;}.field-full{grid-column:1;}.card-header,.card-body,.card-footer{padding-left:22px;padding-right:22px;}}
</style>
</head>
<body>

<?php if ($tipo === 'blacklist'): ?>
<div id="modal-blacklist" style="position:fixed;inset:0;z-index:9999;background:rgba(13,27,46,.85);display:flex;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:18px;max-width:460px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.4);overflow:hidden;animation:slideUp .4s ease;">
    <div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);padding:28px 24px 22px;text-align:center;">
      <div style="width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;border:2px solid rgba(255,255,255,.3);">
        <i class="fas fa-ban" style="color:white;font-size:28px;"></i>
      </div>
      <div style="font-size:20px;font-weight:800;color:white;margin-bottom:4px;">Acesso Bloqueado</div>
      <div style="font-size:12px;color:rgba(255,255,255,.65);">Você consta na lista de restrições da DEC PMESP</div>
    </div>
    <div style="padding:24px;">
      <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#991b1b;margin-bottom:8px;">
          <i class="fas fa-exclamation-circle" style="margin-right:5px;"></i>Motivo do Bloqueio
        </div>
        <div style="font-size:13px;color:#1e293b;line-height:1.6;"><?= $blMotivoExibir ?></div>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px;margin-bottom:18px;">
        <i class="fas fa-clock" style="color:#64748b;font-size:14px;flex-shrink:0;"></i>
        <div>
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;">Duração</div>
          <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($blTempoExibir) ?></div>
        </div>
      </div>
      <div style="font-size:12px;color:#64748b;text-align:center;line-height:1.6;margin-bottom:16px;">
        Se acredita que isto é um engano, entre em contato com a administração da DEC PMESP.
      </div>
      <a href="https://discord.com/channels/1024694525593141288/1319470189326368769" target="_blank" rel="noopener noreferrer"
         style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;background:#5865f2;color:#fff;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;">
        <i class="fab fa-discord" style="font-size:16px;"></i> Falar com a Administração
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <div class="logo-wrap">
      <img src="Imgs/images-removebg-preview.png" alt="DEC"
        onerror="this.style.display='none';this.parentElement.querySelector('i').style.display='block';">
      <i class="fas fa-shield-alt"></i>
    </div>
    <div class="card-title">Cadastro DEC</div>
    <div class="card-subtitle">Diretoria de Ensino e Cultura · PMESP</div>
  </div>

  <div class="card-divider"></div>

  <div class="card-body">
    <?php if ($mensagem && $tipo === 'erro'): ?>
    <div class="alerta alerta-erro">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($mensagem) ?>
    </div>
    <?php endif; ?>

    <?php if ($tipo === 'sucesso'): ?>
    <div class="form-sucesso-wrap">
      <div class="sucesso-icon"><i class="fas fa-check"></i></div>
      <div class="sucesso-titulo">Cadastro realizado!</div>
      <div class="sucesso-sub">Sua conta foi criada. Aguarde a aprovação do administrador.</div>
      <a href="login.php" class="btn-ir-login">
        <i class="fas fa-sign-in-alt"></i> Ir para o Login
      </a>
    </div>
    <?php else: ?>
    <form action="cadastro.php" method="POST" autocomplete="off" id="form-cadastro">
      <input type="hidden" name="fp_user_agent"  id="fp_user_agent">
      <input type="hidden" name="fp_idioma"       id="fp_idioma">
      <input type="hidden" name="fp_timezone"     id="fp_timezone">
      <input type="hidden" name="fp_resolucao"    id="fp_resolucao">
      <input type="hidden" name="fp_cores"        id="fp_cores">
      <input type="hidden" name="fp_plataforma"   id="fp_plataforma">
      <input type="hidden" name="fp_plugins"      id="fp_plugins">
      <input type="hidden" name="fp_canvas_hash"  id="fp_canvas_hash">
      <input type="hidden" name="fp_webgl_hash"   id="fp_webgl_hash">
      <input type="hidden" name="fp_audio_hash"   id="fp_audio_hash">
      <input type="hidden" name="fp_fonts"        id="fp_fonts">
      <input type="hidden" name="fp_touch"        id="fp_touch">
      <input type="hidden" name="fp_dnt"          id="fp_dnt">

      <div class="fields-grid">
        <div class="field-group field-full">
          <label class="field-label" for="f-nome"><i class="fas fa-user"></i> Nome (In-Game)</label>
          <div class="field-wrap">
            <i class="fas fa-user field-ico"></i>
            <input type="text" id="f-nome" name="nome" class="field-input"
              placeholder="Seu nome no servidor"
              value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" required>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label" for="f-rgpm"><i class="fas fa-id-badge"></i> RGPM</label>
          <div class="field-wrap">
            <i class="fas fa-id-badge field-ico"></i>
            <input type="text" id="f-rgpm" name="rgpm" class="field-input"
              placeholder="Ex: 67430"
              value="<?= htmlspecialchars($_POST['rgpm'] ?? '') ?>" required>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label" for="f-discord"><i class="fab fa-discord"></i> ID Discord</label>
          <div class="field-wrap">
            <i class="fab fa-discord field-ico"></i>
            <input type="text" id="f-discord" name="id" class="field-input"
              placeholder="ID numérico"
              value="<?= htmlspecialchars($_POST['id'] ?? '') ?>" required>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label" for="f-senha"><i class="fas fa-lock"></i> Senha</label>
          <div class="field-wrap">
            <i class="fas fa-lock field-ico"></i>
            <input type="password" id="f-senha" name="senha" class="field-input"
              placeholder="Mínimo 6 caracteres"
              autocomplete="new-password" minlength="6" required>
            <button type="button" class="btn-toggle-pw" onclick="togglePw('f-senha','ico1')">
              <i class="fas fa-eye" id="ico1"></i>
            </button>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label" for="f-confirmar"><i class="fas fa-lock"></i> Confirmar</label>
          <div class="field-wrap">
            <i class="fas fa-shield-alt field-ico" style="font-size:11px;"></i>
            <input type="password" id="f-confirmar" name="confirmar" class="field-input"
              placeholder="Repita a senha"
              autocomplete="new-password" required>
            <button type="button" class="btn-toggle-pw" onclick="togglePw('f-confirmar','ico2')">
              <i class="fas fa-eye" id="ico2"></i>
            </button>
          </div>
        </div>
      </div>

      <button type="submit" class="btn-cadastrar">
        <i class="fas fa-user-plus"></i> Criar Conta
      </button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($tipo !== 'sucesso'): ?>
  <div class="card-footer">
    <p>Já possui conta? <a href="login.php">Entrar</a></p>
  </div>
  <?php endif; ?>
</div>

<div class="page-footer">© DEC PMESP · Sistema de Gerenciamento</div>

<script>
(function(){
  document.getElementById('fp_user_agent').value = navigator.userAgent||'';
  document.getElementById('fp_idioma').value      = navigator.language||'';
  document.getElementById('fp_timezone').value    = Intl.DateTimeFormat().resolvedOptions().timeZone||'';
  document.getElementById('fp_resolucao').value   = screen.width+'x'+screen.height;
  document.getElementById('fp_cores').value       = screen.colorDepth||'';
  document.getElementById('fp_plataforma').value  = navigator.platform||'';
  document.getElementById('fp_touch').value       = ('ontouchstart' in window||navigator.maxTouchPoints>0)?'1':'0';
  document.getElementById('fp_dnt').value         = navigator.doNotTrack||'';
  try{var p=[];for(var i=0;i<navigator.plugins.length;i++)p.push(navigator.plugins[i].name);document.getElementById('fp_plugins').value=JSON.stringify(p.slice(0,20));}catch(e){}
  try{var cv=document.createElement('canvas');cv.width=240;cv.height=60;var ctx=cv.getContext('2d');ctx.textBaseline='top';ctx.font='14px Arial';ctx.fillStyle='#f60';ctx.fillRect(0,0,240,60);ctx.fillStyle='#069';ctx.fillText('DEC PMESP fp',2,15);var raw=cv.toDataURL();var h=5381;for(var j=0;j<raw.length;j++)h=((h<<5)+h)^raw.charCodeAt(j);document.getElementById('fp_canvas_hash').value=(h>>>0).toString(16);}catch(e){}
  try{var gl=document.createElement('canvas').getContext('webgl');if(gl){var inf=gl.getExtension('WEBGL_debug_renderer_info');var r=inf?gl.getParameter(inf.UNMASKED_RENDERER_WEBGL):'';var wh=5381;for(var k=0;k<r.length;k++)wh=((wh<<5)+wh)^r.charCodeAt(k);document.getElementById('fp_webgl_hash').value=(wh>>>0).toString(16);}}catch(e){}
  try{var fonts=['Arial','Verdana','Times New Roman','Courier New','Georgia','Trebuchet MS','Impact'];var cnv=document.createElement('canvas');var c2=cnv.getContext('2d');var det=[];fonts.forEach(function(f){c2.font='72px monospace';var w1=c2.measureText('Wj').width;c2.font='72px '+f+',monospace';var w2=c2.measureText('Wj').width;if(w1!==w2)det.push(f);});document.getElementById('fp_fonts').value=JSON.stringify(det);}catch(e){}
})();
function togglePw(id,iconId){var i=document.getElementById(id),o=document.getElementById(iconId);if(i.type==='password'){i.type='text';o.className='fas fa-eye-slash';}else{i.type='password';o.className='fas fa-eye';}}
document.addEventListener('DOMContentLoaded',function(){
  var s1=document.getElementById('f-senha'),s2=document.getElementById('f-confirmar');
  if(!s1||!s2)return;
  function chk(){if(s2.value&&s1.value!==s2.value){s2.style.borderColor='#dc2626';s2.style.boxShadow='0 0 0 3px rgba(220,38,38,.1)';}else{s2.style.borderColor='';s2.style.boxShadow='';}}
  s1.addEventListener('input',chk);s2.addEventListener('input',chk);
  var n=document.getElementById('f-nome');if(n)n.focus();
});
</script>
</body>
</html>
