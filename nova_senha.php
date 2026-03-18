<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once "conexao.php";

if (isset($_SESSION["usuario"])) { header("Location: painel.php"); exit; }

// Precisa ter verificado o código
if (empty($_SESSION['reset_usuario_id']) || empty($_SESSION['reset_verificado'])) {
    header("Location: esqueci_senha.php");
    exit;
}

$usuario_id = (int) $_SESSION['reset_usuario_id'];
$erro       = '';
$sucesso    = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nova    = $_POST["nova_senha"]    ?? '';
    $confirma = $_POST["confirma_senha"] ?? '';

    if (strlen($nova) < 6) {
        $erro = "A senha deve ter pelo menos 6 caracteres.";
    } elseif ($nova !== $confirma) {
        $erro = "As senhas não coincidem.";
    } else {
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        $upd  = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
        $upd->bind_param("si", $hash, $usuario_id);

        if ($upd->execute() && $upd->affected_rows > 0) {
            // Limpa sessão de reset
            unset($_SESSION['reset_usuario_id'], $_SESSION['reset_rgpm'], $_SESSION['reset_verificado']);
            $sucesso = true;
        } else {
            $erro = "Erro ao atualizar a senha. Tente novamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Nova Senha · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --navy:  #0d1b2e;
  --blue:  #1a56db;
  --blue2: #1e40af;
  --green: #16a34a;
  --white: #ffffff;
  --red:   #dc2626;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  min-height: 100vh;
  background: var(--navy);
  font-family: 'Inter', sans-serif;
  display: flex; align-items: center; justify-content: center;
  padding: 20px; position: relative; overflow: hidden;
}
body::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 20% 50%, rgba(26,86,219,.18) 0%, transparent 60%),
              radial-gradient(ellipse at 80% 20%, rgba(30,64,175,.12) 0%, transparent 50%);
  pointer-events: none;
}
.card {
  background: rgba(255,255,255,.06);
  backdrop-filter: blur(20px);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 20px; padding: 40px 36px;
  width: 100%; max-width: 420px;
  position: relative; z-index: 1;
  box-shadow: 0 25px 60px rgba(0,0,0,.4);
}
.logo-wrap {
  width: 66px; height: 66px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.18);
  border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px;
}
.logo-wrap img { width: 42px; height: 42px; object-fit: contain; }
.card-title {
  font-family: 'Sora', sans-serif;
  font-size: 22px; font-weight: 800; color: #fff;
  text-align: center; margin-bottom: 6px;
}
.card-sub {
  font-size: 13px; color: rgba(255,255,255,.5);
  text-align: center; margin-bottom: 28px; line-height: 1.5;
}
.field-label {
  display: block; font-size: 12px; font-weight: 600;
  color: rgba(255,255,255,.6); margin-bottom: 7px; letter-spacing: .03em;
}
.field-wrap { position: relative; margin-bottom: 18px; }
.field-input {
  width: 100%; padding: 13px 44px 13px 16px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 10px; color: #fff;
  font-size: 15px; font-family: 'Inter', sans-serif;
  transition: border-color .2s, box-shadow .2s; outline: none;
}
.field-input::placeholder { color: rgba(255,255,255,.3); }
.field-input:focus {
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(26,86,219,.25);
}
.toggle-pw {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  color: rgba(255,255,255,.4); font-size: 15px; padding: 4px;
  transition: color .2s;
}
.toggle-pw:hover { color: rgba(255,255,255,.7); }
.strength-bar {
  height: 4px; border-radius: 4px;
  background: rgba(255,255,255,.1); margin-top: 8px; overflow: hidden;
}
.strength-fill { height: 100%; border-radius: 4px; transition: width .3s, background .3s; width: 0; }
.strength-text { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 5px; }
.btn-primary {
  width: 100%; padding: 14px;
  background: var(--blue); border: none; border-radius: 10px;
  color: #fff; font-size: 15px; font-weight: 700;
  font-family: 'Inter', sans-serif; cursor: pointer;
  transition: background .2s, transform .1s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-primary:hover { background: var(--blue2); }
.btn-primary:active { transform: scale(.98); }
.erro-box {
  background: rgba(220,38,38,.12);
  border: 1px solid rgba(220,38,38,.3);
  border-radius: 10px; padding: 12px 14px;
  color: #fca5a5; font-size: 13px;
  display: flex; align-items: center; gap: 9px;
  margin-bottom: 18px;
}
.sucesso-box {
  text-align: center; padding: 10px 0;
}
.sucesso-icon {
  width: 70px; height: 70px;
  background: rgba(22,163,74,.15);
  border: 1px solid rgba(22,163,74,.3);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
  font-size: 28px; color: #4ade80;
}
.sucesso-title {
  font-family: 'Sora', sans-serif;
  font-size: 20px; font-weight: 800; color: #4ade80; margin-bottom: 8px;
}
.sucesso-text {
  font-size: 13px; color: rgba(255,255,255,.5); margin-bottom: 24px; line-height: 1.6;
}
.btn-login {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 28px;
  background: var(--blue); border: none; border-radius: 10px;
  color: #fff; font-size: 14px; font-weight: 700;
  text-decoration: none; transition: background .2s;
}
.btn-login:hover { background: var(--blue2); }
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <img src="Imgs/images-removebg-preview.png" alt="DEC" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
    <i class="fas fa-shield-alt" style="display:none"></i>
  </div>

  <?php if ($sucesso): ?>
  <div class="sucesso-box">
    <div class="sucesso-icon"><i class="fas fa-check"></i></div>
    <div class="sucesso-title">Senha atualizada!</div>
    <div class="sucesso-text">Sua senha foi redefinida com sucesso.<br>Você já pode fazer login normalmente.</div>
    <a href="login.php" class="btn-login">
      <i class="fas fa-sign-in-alt"></i> Ir para o login
    </a>
  </div>
  <?php else: ?>
  <div class="card-title">Nova Senha</div>
  <div class="card-sub">DEC PMESP · Diretoria de Ensino e Cultura</div>

  <?php if ($erro): ?>
  <div class="erro-box">
    <i class="fas fa-exclamation-circle"></i>
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <label class="field-label" for="nova_senha">NOVA SENHA</label>
    <div class="field-wrap">
      <input type="password" id="nova_senha" name="nova_senha" class="field-input"
        placeholder="Mínimo 6 caracteres" required minlength="6"
        oninput="verificarForca(this.value)">
      <button type="button" class="toggle-pw" onclick="toggleVer('nova_senha',this)">
        <i class="fas fa-eye"></i>
      </button>
    </div>
    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
    <div class="strength-text" id="strength-text"></div>

    <label class="field-label" style="margin-top:16px;" for="confirma_senha">CONFIRMAR SENHA</label>
    <div class="field-wrap">
      <input type="password" id="confirma_senha" name="confirma_senha" class="field-input"
        placeholder="Repita a nova senha" required minlength="6">
      <button type="button" class="toggle-pw" onclick="toggleVer('confirma_senha',this)">
        <i class="fas fa-eye"></i>
      </button>
    </div>

    <button type="submit" class="btn-primary" style="margin-top:8px;">
      <i class="fas fa-lock"></i> Salvar nova senha
    </button>
  </form>
  <?php endif; ?>
</div>

<script>
function toggleVer(id, btn) {
  var inp = document.getElementById(id);
  var ico = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    ico.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    ico.className = 'fas fa-eye';
  }
}
function verificarForca(v) {
  var fill = document.getElementById('strength-fill');
  var text = document.getElementById('strength-text');
  var score = 0;
  if (v.length >= 6)  score++;
  if (v.length >= 10) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  var pct   = ['0%','25%','50%','75%','100%'][score] || '0%';
  var color = ['#ef4444','#f97316','#eab308','#22c55e','#16a34a'][score] || '#ef4444';
  var label = ['','Muito fraca','Fraca','Média','Forte','Muito forte'][score] || '';
  fill.style.width = pct;
  fill.style.background = color;
  text.textContent = label;
  text.style.color = color;
}
</script>
</body>
</html>
