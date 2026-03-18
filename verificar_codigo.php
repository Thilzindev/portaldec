<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once "conexao.php";

if (isset($_SESSION["usuario"])) { header("Location: painel.php"); exit; }

// Precisa ter passado pela tela anterior
if (empty($_SESSION['reset_usuario_id'])) {
    header("Location: esqueci_senha.php");
    exit;
}

$usuario_id = (int) $_SESSION['reset_usuario_id'];
$erro       = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $codigo = strtoupper(trim($_POST["codigo"] ?? ''));

    if (!$codigo || strlen($codigo) !== 6) {
        $erro = "Digite o código de 6 caracteres.";
    } else {
        // Busca código válido
        $row = db_row($conexao,
            "SELECT id, tentativas FROM reset_senha
             WHERE usuario_id = ? AND codigo = ? AND usado = 0 AND expira_em > NOW()
             LIMIT 1",
            "is", [$usuario_id, $codigo]
        );

        if (!$row) {
            // Incrementa tentativas em todos os códigos ativos deste usuário
            $upd = $conexao->prepare("UPDATE reset_senha SET tentativas = tentativas + 1 WHERE usuario_id = ? AND usado = 0");
            $upd->bind_param("i", $usuario_id);
            $upd->execute();

            // Verifica se esgotou tentativas
            $chk = db_row($conexao,
                "SELECT tentativas FROM reset_senha WHERE usuario_id = ? AND usado = 0 ORDER BY criado_em DESC LIMIT 1",
                "i", [$usuario_id]
            );
            if ($chk && $chk['tentativas'] >= 3) {
                // Invalida o código
                db_query($conexao, "UPDATE reset_senha SET usado = 1 WHERE usuario_id = ? AND usado = 0", "i", [$usuario_id]);
                unset($_SESSION['reset_usuario_id'], $_SESSION['reset_rgpm']);
                $erro = "Muitas tentativas incorretas. Solicite um novo código.";
            } else {
                $restantes = $chk ? max(0, 3 - $chk['tentativas']) : 0;
                $erro = "Código incorreto ou expirado. Tentativas restantes: {$restantes}.";
            }
        } else {
            // Código correto — marca como usado e avança
            db_query($conexao, "UPDATE reset_senha SET usado = 1 WHERE id = ?", "i", [$row['id']]);

            $_SESSION['reset_verificado'] = true;
            header("Location: nova_senha.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Verificar Código · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --navy:  #0d1b2e;
  --blue:  #1a56db;
  --blue2: #1e40af;
  --white: #ffffff;
  --border:#e2e8f0;
  --text:  #1e293b;
  --muted: #64748b;
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
.step-info {
  display: flex; align-items: center; gap: 10px;
  background: rgba(26,86,219,.15);
  border: 1px solid rgba(26,86,219,.3);
  border-radius: 10px; padding: 12px 14px;
  margin-bottom: 22px;
  font-size: 12px; color: rgba(255,255,255,.7); line-height: 1.5;
}
.step-info i { color: #60a5fa; font-size: 16px; flex-shrink: 0; }
.field-label {
  display: block; font-size: 12px; font-weight: 600;
  color: rgba(255,255,255,.6); margin-bottom: 7px; letter-spacing: .03em;
}
.field-wrap { position: relative; margin-bottom: 18px; }
.code-input {
  width: 100%; padding: 16px;
  background: rgba(255,255,255,.07);
  border: 1px solid rgba(255,255,255,.15);
  border-radius: 10px; color: #fff;
  font-size: 28px; font-weight: 800;
  font-family: 'Sora', monospace;
  text-align: center; letter-spacing: 10px;
  transition: border-color .2s, box-shadow .2s;
  outline: none; text-transform: uppercase;
}
.code-input::placeholder { color: rgba(255,255,255,.2); letter-spacing: 8px; font-size: 22px; }
.code-input:focus {
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(26,86,219,.25);
}
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
.timer {
  text-align: center; font-size: 12px;
  color: rgba(255,255,255,.4); margin-top: 14px;
}
.timer span { color: #60a5fa; font-weight: 700; }
.back-link {
  display: block; text-align: center;
  margin-top: 16px; font-size: 13px;
  color: rgba(255,255,255,.4); text-decoration: none;
  transition: color .2s;
}
.back-link:hover { color: rgba(255,255,255,.7); }
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <img src="Imgs/images-removebg-preview.png" alt="DEC" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
    <i class="fas fa-shield-alt" style="display:none"></i>
  </div>
  <div class="card-title">Verificar Código</div>
  <div class="card-sub">DEC PMESP · Diretoria de Ensino e Cultura</div>

  <div class="step-info">
    <i class="fas fa-key"></i>
    <span>Digite o código de <strong>6 caracteres</strong> enviado na sua <strong>DM do Discord</strong>. Válido por 5 minutos.</span>
  </div>

  <?php if ($erro): ?>
  <div class="erro-box">
    <i class="fas fa-exclamation-circle"></i>
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <label class="field-label" for="codigo">CÓDIGO DE VERIFICAÇÃO</label>
    <div class="field-wrap">
      <input
        type="text"
        id="codigo"
        name="codigo"
        class="code-input"
        placeholder="······"
        maxlength="6"
        autofocus
        required
        oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')"
      >
    </div>
    <button type="submit" class="btn-primary">
      <i class="fas fa-check-circle"></i> Verificar código
    </button>
  </form>

  <div class="timer">Código expira em <span id="countdown">5:00</span></div>

  <a href="esqueci_senha.php" class="back-link">
    <i class="fas fa-redo" style="margin-right:5px;"></i> Solicitar novo código
  </a>
</div>

<script>
// Contador regressivo de 5 minutos
var secs = 300;
var el   = document.getElementById('countdown');
var iv   = setInterval(function(){
  secs--;
  if (secs <= 0) {
    clearInterval(iv);
    el.textContent = '0:00';
    el.style.color = '#f87171';
    el.closest('.timer').textContent = 'Código expirado. ';
    var a = document.createElement('a');
    a.href = 'esqueci_senha.php';
    a.textContent = 'Solicitar novo';
    a.style.cssText = 'color:#60a5fa;font-weight:700;';
    el.closest('.timer').appendChild(a);
    return;
  }
  var m = Math.floor(secs/60), s = secs%60;
  el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
  if (secs <= 60) el.style.color = '#f87171';
}, 1000);
</script>
</body>
</html>
