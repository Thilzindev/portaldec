<?php
/**
 * esqueci_senha.php — Recuperação de senha via Discord DM
 * Token do bot lido do .env (nunca hardcoded)
 */
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';

if (isset($_SESSION['usuario'])) {
    header('Location: painel.php');
    exit;
}

// Token do bot lido do .env
$discordBotToken = getenv('DISCORD_BOT_TOKEN') ?: (defined('DISCORD_BOT_TOKEN') ? DISCORD_BOT_TOKEN : '');

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rgpm = trim($_POST['rgpm'] ?? '');

    if ($rgpm === '') {
        $erro = 'Informe seu RGPM.';
    } else {
        $user = db_row($conexao,
            'SELECT id, nome, discord FROM usuarios WHERE rgpm = ? AND nivel >= 1 LIMIT 1',
            's', [$rgpm]
        );

        if (!$user) {
            $erro = 'RGPM não encontrado.';
        } elseif (empty($user['discord'])) {
            $erro = 'Sua conta não possui Discord vinculado. Contate um administrador.';
        } else {
            // Remove códigos antigos
            db_query($conexao, 'DELETE FROM reset_senha WHERE usuario_id = ?', 'i', [(int)$user['id']]);

            // Gera código de 6 chars alfanumérico
            $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $codigo = '';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $expira = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            $ok = db_query($conexao,
                'INSERT INTO reset_senha (usuario_id, codigo, expira_em) VALUES (?, ?, ?)',
                'iss', [(int)$user['id'], $codigo, $expira]
            );

            if (!$ok) {
                $erro = 'Erro interno. Tente novamente.';
            } else {
                // Envia DM via Discord Bot API
                $discordId = $user['discord'];
                $nome      = $user['nome'];
                $enviou    = false;

                if ($discordBotToken !== '') {
                    // 1. Abre o canal DM
                    $ch1 = curl_init('https://discord.com/api/v10/users/@me/channels');
                    curl_setopt_array($ch1, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bot ' . $discordBotToken,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_POSTFIELDS     => json_encode(['recipient_id' => $discordId]),
                        CURLOPT_TIMEOUT        => 10,
                    ]);
                    $resp1 = json_decode(curl_exec($ch1), true);
                    curl_close($ch1);

                    if (!empty($resp1['id'])) {
                        // 2. Envia a mensagem
                        $msg = "🔐 **Redefinição de Senha — DEC PMESP**\n\n"
                             . "Olá, **{$nome}**!\n\n"
                             . "Seu código de verificação é:\n\n"
                             . "```\n{$codigo}\n```\n\n"
                             . "⏱️ Este código expira em **5 minutos**.\n"
                             . "❌ Se não foi você que solicitou, ignore esta mensagem.";

                        $ch2 = curl_init("https://discord.com/api/v10/channels/{$resp1['id']}/messages");
                        curl_setopt_array($ch2, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_HTTPHEADER     => [
                                'Authorization: Bot ' . $discordBotToken,
                                'Content-Type: application/json',
                            ],
                            CURLOPT_POSTFIELDS => json_encode(['content' => $msg]),
                            CURLOPT_TIMEOUT    => 10,
                        ]);
                        $resp2 = json_decode(curl_exec($ch2), true);
                        curl_close($ch2);

                        if (!empty($resp2['id'])) {
                            $enviou = true;
                        }
                    }
                }

                if (!$enviou) {
                    $erro = 'Não foi possível enviar a mensagem no Discord. Verifique se o bot está configurado.';
                    // Remove o código inserido pois não foi enviado
                    db_query($conexao, 'DELETE FROM reset_senha WHERE usuario_id = ?', 'i', [(int)$user['id']]);
                } else {
                    $_SESSION['reset_usuario_id'] = (int) $user['id'];
                    $_SESSION['reset_rgpm']       = $rgpm;
                    header('Location: verificar_codigo.php');
                    exit;
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Esqueci a Senha · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0d1b2e;--blue:#1a56db;--blue2:#1e40af;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{min-height:100vh;background:var(--navy);font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow:hidden;}
body::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(26,86,219,.18) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(30,64,175,.12) 0%,transparent 50%);pointer-events:none;}
.card{background:rgba(255,255,255,.06);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:40px 36px;width:100%;max-width:420px;position:relative;z-index:1;box-shadow:0 25px 60px rgba(0,0,0,.4);}
.logo-wrap{width:66px;height:66px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;}
.logo-wrap img{width:42px;height:42px;object-fit:contain;}
.card-title{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:6px;}
.card-sub{font-size:13px;color:rgba(255,255,255,.5);text-align:center;margin-bottom:28px;line-height:1.5;}
.step-info{display:flex;align-items:center;gap:10px;background:rgba(26,86,219,.15);border:1px solid rgba(26,86,219,.3);border-radius:10px;padding:12px 14px;margin-bottom:22px;font-size:12px;color:rgba(255,255,255,.7);line-height:1.5;}
.step-info i{color:#60a5fa;font-size:16px;flex-shrink:0;}
.field-label{display:block;font-size:12px;font-weight:600;color:rgba(255,255,255,.6);margin-bottom:7px;letter-spacing:.03em;}
.field-wrap{position:relative;margin-bottom:18px;}
.field-input{width:100%;padding:13px 16px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:10px;color:#fff;font-size:15px;font-family:'Inter',sans-serif;transition:border-color .2s,box-shadow .2s;outline:none;}
.field-input::placeholder{color:rgba(255,255,255,.3);}
.field-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(26,86,219,.25);}
.btn-primary{width:100%;padding:14px;background:var(--blue);border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;transition:background .2s,transform .1s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-primary:hover{background:var(--blue2);}
.btn-primary:active{transform:scale(.98);}
.erro-box{background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.3);border-radius:10px;padding:12px 14px;color:#fca5a5;font-size:13px;display:flex;align-items:center;gap:9px;margin-bottom:18px;}
.back-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:rgba(255,255,255,.4);text-decoration:none;transition:color .2s;}
.back-link:hover{color:rgba(255,255,255,.7);}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    <img src="Imgs/images-removebg-preview.png" alt="DEC"
      onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
    <i class="fas fa-shield-alt" style="display:none;color:#93c5fd;font-size:26px;"></i>
  </div>
  <div class="card-title">Esqueci a Senha</div>
  <div class="card-sub">DEC PMESP · Diretoria de Ensino e Cultura</div>

  <div class="step-info">
    <i class="fab fa-discord"></i>
    <span>Digite seu RGPM e enviaremos um código de verificação via <strong>DM no Discord</strong>.</span>
  </div>

  <?php if ($erro): ?>
  <div class="erro-box">
    <i class="fas fa-exclamation-circle"></i>
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <label class="field-label" for="rgpm">RGPM</label>
    <div class="field-wrap">
      <input type="text" id="rgpm" name="rgpm" class="field-input"
        placeholder="Digite seu RGPM"
        value="<?= htmlspecialchars($_POST['rgpm'] ?? '') ?>"
        maxlength="20" autofocus required>
    </div>
    <button type="submit" class="btn-primary">
      <i class="fab fa-discord"></i> Enviar código pelo Discord
    </button>
  </form>

  <a href="login.php" class="back-link">
    <i class="fas fa-arrow-left" style="margin-right:5px;"></i> Voltar ao login
  </a>
</div>
</body>
</html>
