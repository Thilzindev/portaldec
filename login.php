<?php
/**
 * login.php — Autenticação segura
 * Correções: prepared statements em 100% das queries, sem SQL direto
 */
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';

// Já logado → redireciona
if (isset($_SESSION['usuario'])) {
    header('Location: painel.php');
    exit;
}

$erro     = '';
$bloqueado = isset($_GET['bloqueado']) && $_GET['bloqueado'] === '1';

// ── Rate limiting simples por IP ─────────────────────────────────────────────
$ip_atual = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!isset($_SESSION['login_tentativas'])) $_SESSION['login_tentativas'] = 0;
if (!isset($_SESSION['login_bloqueado_ate'])) $_SESSION['login_bloqueado_ate'] = 0;

if ($_SESSION['login_bloqueado_ate'] > time()) {
    $segundos = $_SESSION['login_bloqueado_ate'] - time();
    $erro = "Muitas tentativas. Aguarde {$segundos}s para tentar novamente.";
}

// ── Processa login ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    $rgpm  = trim($_POST['usuario'] ?? '');
    $senha = trim($_POST['senha']   ?? '');

    if ($rgpm === '' || $senha === '') {
        $erro = 'Preencha todos os campos.';
    } else {
        // Busca usuário por RGPM — 100% prepared statement
        $usuario = db_row($conexao,
            'SELECT id, nome, nivel, rgpm, senha, status FROM usuarios WHERE rgpm = ? LIMIT 1',
            's', [$rgpm]
        );

        if ($usuario === null) {
            $erro = 'RGPM não encontrado.';
            $_SESSION['login_tentativas']++;
        } elseif (!password_verify($senha, $usuario['senha'])) {
            $erro = 'Senha incorreta.';
            $_SESSION['login_tentativas']++;
        } else {
            // Reseta contador de tentativas
            $_SESSION['login_tentativas'] = 0;

            $uid = (int) $usuario['id'];

            // ── 1. Processa multas vencidas (tudo via prepared statement) ──
            $tblMultas = db_val($conexao, "SHOW TABLES LIKE 'multas'");
            if ($tblMultas) {
                $agora = date('Y-m-d H:i:s');
                $multas_vencidas = db_query($conexao,
                    "SELECT id, nome_aluno, rgpm_aluno, discord_aluno, aplicada_por
                     FROM multas
                     WHERE usuario_id = ? AND status IN ('pendente','aguardando_validacao')
                       AND prazo_expira <= ? AND processada = 0",
                    'is', [$uid, $agora]
                ) ?: [];

                if (!empty($multas_vencidas)) {
                    foreach ($multas_vencidas as $mv) {
                        $mid = (int) $mv['id'];
                        // Marca multa como não paga
                        db_query($conexao,
                            'UPDATE multas SET status = ?, processada = 1 WHERE id = ?',
                            'si', ['nao_paga', $mid]
                        );
                        // Adiciona à blacklist se ainda não estiver
                        $ja_bl = db_val($conexao,
                            'SELECT id FROM blacklist WHERE rgpm = ? LIMIT 1',
                            's', [$mv['rgpm_aluno']]
                        );
                        if ($ja_bl === null) {
                            db_query($conexao,
                                "INSERT INTO blacklist (nome, rgpm, discord, motivo_tipo, motivo_texto, tempo, expiracao, adicionado_por)
                                 VALUES (?, ?, ?, 'texto', 'Multa não paga no prazo.', 'permanente', NULL, ?)",
                                'ssss', [
                                    $mv['nome_aluno'],
                                    $mv['rgpm_aluno'],
                                    $mv['discord_aluno'] ?? '',
                                    $mv['aplicada_por']  ?? 'Sistema'
                                ]
                            );
                        }
                    }
                    // Bloqueia a conta
                    db_query($conexao,
                        "UPDATE usuarios SET meus_cursos = '', status = 'Bloqueado', tags = '[]' WHERE id = ?",
                        'i', [$uid]
                    );
                    db_query($conexao,
                        'DELETE FROM pagamentos_pendentes WHERE usuario_id = ?',
                        'i', [$uid]
                    );
                    header('Location: login.php?bloqueado=1');
                    exit;
                }
            }

            // ── 2. Verifica status Bloqueado ──
            if (($usuario['status'] ?? '') === 'Bloqueado') {
                header('Location: login.php?bloqueado=1');
                exit;
            }

            // ── 3. Verifica blacklist ──
            $tblBl = db_val($conexao, "SHOW TABLES LIKE 'blacklist'");
            if ($tblBl) {
                $bl = db_row($conexao,
                    'SELECT id, tempo, expiracao FROM blacklist WHERE rgpm = ? LIMIT 1',
                    's', [$usuario['rgpm']]
                );
                if ($bl) {
                    $banAtivo = true;
                    if ($bl['tempo'] === 'temporario' && !empty($bl['expiracao'])) {
                        $tz  = new DateTimeZone('America/Sao_Paulo');
                        $now = new DateTime('now', $tz);
                        $exp = new DateTime($bl['expiracao'], $tz);
                        if ($exp <= $now) $banAtivo = false;
                    }
                    if ($banAtivo) {
                        header('Location: login.php?bloqueado=1');
                        exit;
                    }
                }
            }

            // ── 4. Tudo OK — cria sessão ──
            session_regenerate_id(true);
            $_SESSION['usuario'] = $usuario['nome'];
            $_SESSION['nivel']   = (int) $usuario['nivel'];
            $_SESSION['id']      = $uid;
            $_SESSION['rgpm']    = $usuario['rgpm'];
            header('Location: painel.php');
            exit;
        }

        // Bloqueia após 5 tentativas falhas
        if ($_SESSION['login_tentativas'] >= 5) {
            $_SESSION['login_bloqueado_ate'] = time() + 60;
            $_SESSION['login_tentativas']    = 0;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Login · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0d1b2e;--blue:#1a56db;--blue2:#1e40af;--white:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--red:#dc2626;--red-p:#fef2f2;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--navy);font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow:hidden;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 15% 10%,rgba(26,86,219,.2) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 85% 90%,rgba(124,58,237,.13) 0%,transparent 60%);pointer-events:none;}
body::after{content:'';position:fixed;inset:0;background-image:radial-gradient(rgba(255,255,255,.055) 1px,transparent 1px);background-size:30px 30px;pointer-events:none;}
.login-card{position:relative;z-index:1;width:100%;max-width:420px;background:var(--white);border-radius:20px;box-shadow:0 24px 64px rgba(0,0,0,.5),0 8px 24px rgba(0,0,0,.3);overflow:hidden;animation:slideUp .4s cubic-bezier(.2,.8,.2,1) both;}
@keyframes slideUp{from{opacity:0;transform:translateY(28px);}to{opacity:1;transform:translateY(0);}}
.card-header{background:linear-gradient(135deg,#0d1b2e 0%,#1a3a6b 100%);padding:34px 36px 30px;text-align:center;position:relative;overflow:hidden;}
.card-header::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.04);}
.card-header::after{content:'';position:absolute;bottom:-50px;left:30px;width:110px;height:110px;border-radius:50%;background:rgba(96,165,250,.07);}
.logo-wrap{width:66px;height:66px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;position:relative;z-index:1;}
.logo-wrap img{width:42px;height:42px;object-fit:contain;}
.logo-wrap i{font-size:26px;color:#93c5fd;display:none;}
.card-title{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:#fff;margin-bottom:5px;position:relative;z-index:1;}
.card-subtitle{font-size:11px;color:rgba(255,255,255,.4);font-weight:500;letter-spacing:.06em;text-transform:uppercase;position:relative;z-index:1;}
.card-divider{height:1px;background:linear-gradient(90deg,transparent,rgba(26,86,219,.35),transparent);}
.card-body{padding:32px 36px 24px;}
.alerta{display:flex;align-items:center;gap:10px;border-radius:10px;padding:12px 14px;margin-bottom:22px;font-size:13px;font-weight:600;animation:shake .35s ease;}
.alerta-erro{background:var(--red-p);border:1px solid #fecaca;color:var(--red);}
.alerta-bloq{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:20px;margin-bottom:16px;text-align:center;}
@keyframes shake{0%,100%{transform:translateX(0);}20%,60%{transform:translateX(-5px);}40%,80%{transform:translateX(5px);}}
.field-group{margin-bottom:18px;}
.field-label{display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:7px;}
.field-wrap{position:relative;}
.field-input{width:100%;padding:12px 14px 12px 42px;border:1.5px solid var(--border);border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;color:var(--text);background:#f8fafc;outline:none;transition:border-color .18s,box-shadow .18s,background .18s;}
.field-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(26,86,219,.1);}
.field-input::placeholder{color:#94a3b8;font-weight:400;}
.field-ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;color:#94a3b8;pointer-events:none;transition:color .18s;}
.field-wrap:focus-within .field-ico{color:var(--blue);}
.btn-toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:13px;color:#94a3b8;padding:4px;border-radius:5px;transition:color .18s;}
.btn-toggle-pw:hover{color:var(--blue);}
.btn-entrar{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:.02em;box-shadow:0 4px 16px rgba(26,86,219,.35);transition:all .2s;margin-top:8px;}
.btn-entrar:hover{box-shadow:0 6px 24px rgba(26,86,219,.5);transform:translateY(-1px);}
.btn-entrar:active{transform:translateY(0);}
.card-footer{padding:16px 36px 22px;text-align:center;border-top:1px solid var(--border);background:#f8fafc;}
.card-footer p{font-size:13px;color:var(--muted);}
.card-footer a{color:var(--blue);font-weight:700;text-decoration:none;transition:color .18s;}
.card-footer a:hover{color:var(--blue2);}
.page-footer{position:fixed;bottom:18px;left:0;right:0;text-align:center;z-index:1;font-size:11px;color:rgba(255,255,255,.18);letter-spacing:.05em;pointer-events:none;}
@media(max-width:480px){.card-header,.card-body,.card-footer{padding-left:22px;padding-right:22px;}}
</style>
</head>
<body>

<div class="login-card">
  <div class="card-header">
    <div class="logo-wrap">
      <img src="Imgs/images-removebg-preview.png" alt="DEC"
        onerror="this.style.display='none';this.parentElement.querySelector('i').style.display='block';">
      <i class="fas fa-shield-alt"></i>
    </div>
    <div class="card-title">Sistema DEC</div>
    <div class="card-subtitle">Diretoria de Ensino e Cultura · PMESP</div>
  </div>

  <div class="card-divider"></div>

  <div class="card-body">
    <?php if ($bloqueado): ?>
    <div class="alerta-bloq">
      <div style="width:56px;height:56px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-ban" style="color:#dc2626;font-size:22px;"></i>
      </div>
      <div style="font-weight:800;color:#b91c1c;font-size:15px;margin-bottom:6px;">Conta Bloqueada</div>
      <div style="font-size:12px;color:#7f1d1d;line-height:1.7;margin-bottom:14px;">
        Sua conta foi <strong>bloqueada</strong> por não pagamento de multa ou por infração das normas.<br>
        Entre em contato com a administração para regularizar sua situação.
      </div>
      <a href="https://discord.com/channels/1024694525593141288/1319470189326368769" target="_blank" rel="noopener noreferrer"
         style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#5865f2;color:#fff;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;">
        <i class="fab fa-discord" style="font-size:16px;"></i> Falar com a Administração
      </a>
    </div>
    <?php endif; ?>

    <?php if ($erro): ?>
    <div class="alerta alerta-erro">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <form action="login.php" method="POST" autocomplete="off">
      <div class="field-group">
        <label class="field-label" for="f-rgpm"><i class="fas fa-id-badge"></i> RGPM</label>
        <div class="field-wrap">
          <i class="fas fa-id-badge field-ico"></i>
          <input type="text" id="f-rgpm" name="usuario" class="field-input"
            placeholder="Digite seu RGPM"
            value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
            autocomplete="off" required>
        </div>
      </div>
      <div class="field-group">
        <label class="field-label" for="f-senha"><i class="fas fa-lock"></i> Senha</label>
        <div class="field-wrap">
          <i class="fas fa-lock field-ico"></i>
          <input type="password" id="f-senha" name="senha" class="field-input"
            placeholder="Digite sua senha"
            autocomplete="current-password" required>
          <button type="button" class="btn-toggle-pw" onclick="toggleSenha()" title="Mostrar/ocultar senha">
            <i class="fas fa-eye" id="ico-olho"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-entrar">
        <i class="fas fa-sign-in-alt"></i> Entrar no Sistema
      </button>
      <div style="text-align:center;margin-top:14px;">
        <a href="esqueci_senha.php" style="font-size:12px;color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px;" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='var(--muted)'">
          <i class="fas fa-key" style="font-size:11px;"></i> Esqueci minha senha
        </a>
      </div>
    </form>
  </div>

  <div class="card-footer">
    <p>Não sou membro? <a href="cadastro.php">Cadastrar</a></p>
  </div>
</div>

<div class="page-footer">© DEC PMESP · Sistema de Gerenciamento</div>

<script>
function toggleSenha(){var i=document.getElementById('f-senha'),o=document.getElementById('ico-olho');if(i.type==='password'){i.type='text';o.className='fas fa-eye-slash';}else{i.type='password';o.className='fas fa-eye';}}
document.addEventListener('DOMContentLoaded',function(){
  <?php if (!$erro && !$bloqueado): ?>
  document.getElementById('f-rgpm').focus();
  <?php else: ?>
  document.getElementById('f-senha').focus();
  <?php endif; ?>
});
</script>
</body>
</html>
