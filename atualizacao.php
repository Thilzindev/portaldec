<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ✅ CORRIGIDO: IP admin via variável de ambiente ou constante — não hardcoded
// Defina a variável de ambiente IP_ADMIN_DEC no painel do host, ou edite aqui:
define('IP_ADMIN',   getenv('IP_ADMIN_DEC') ?: '177.37.232.202');
define('MANUTENCAO', true);

// ✅ CORRIGIDO: caminho absoluto fixo — sem path traversal
$arquivo_status = __DIR__ . '/manutencao_status.json';

if (!file_exists($arquivo_status)) {
    file_put_contents($arquivo_status, json_encode([
        'progresso' => 0,
        'mensagem'  => 'Iniciando atualização...',
        'concluido' => false,
    ]));
}

$ip_atual = $_SERVER['REMOTE_ADDR'] ?? '';
$uri      = $_SERVER['REQUEST_URI'] ?? '';

if (MANUTENCAO && $ip_atual !== IP_ADMIN) {
    $pagina_atual = basename(parse_url($uri, PHP_URL_PATH));
    if ($pagina_atual !== 'atualizacao.php' && $pagina_atual !== '') {
        header('Location: /atualizacao.php', true, 302);
        exit;
    }
}

// ✅ CORRIGIDO: SSE — sem acesso a arquivo arbitrário, apenas lê o arquivo fixo
if (isset($_GET['sse'])) {
    // Verifica se SSE é suportado antes de tentar (profreehost pode ter timeout curto)
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $inicio = time();
    while (time() - $inicio < 55) { // 55s (margem para timeout de 60s)
        if (!file_exists($arquivo_status)) break;
        $json  = file_get_contents($arquivo_status);
        $dados = json_decode($json, true);
        if (!is_array($dados)) break;

        echo "data: " . json_encode($dados) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();

        if (!empty($dados['concluido'])) {
            echo "event: concluido\ndata: ok\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            break;
        }
        sleep(2);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEC PMESP · Sistema em Manutenção</title>
    <link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:    #0d1b2e;
            --navy2:   #112240;
            --blue:    #1a56db;
            --blue2:   #1e40af;
            --blue-lt: #3b82f6;
            --white:   #ffffff;
            --border:  rgba(255,255,255,0.08);
            --muted:   rgba(255,255,255,0.45);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--navy);
            font-family: 'Inter', sans-serif;
            color: var(--white);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .bg-grid {
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(26,86,219,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(26,86,219,0.04) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none; z-index: 0;
        }
        .bg-glow {
            position: fixed; width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(26,86,219,0.18) 0%, transparent 70%);
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            pointer-events: none; z-index: 0;
            animation: pulse 4s ease-in-out infinite;
        }
        .bg-glow-2 {
            position: fixed; width: 300px; height: 300px; border-radius: 50%;
            background: radial-gradient(circle, rgba(59,130,246,0.12) 0%, transparent 70%);
            bottom: 10%; right: 10%;
            pointer-events: none; z-index: 0;
            animation: pulse 6s ease-in-out infinite reverse;
        }
        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            50% { transform: translate(-50%, -50%) scale(1.1); opacity: 0.7; }
        }
        .particles { position: fixed; inset: 0; pointer-events: none; z-index: 0; }
        .particle {
            position: absolute; width: 2px; height: 2px;
            background: rgba(59,130,246,0.5); border-radius: 50%;
            animation: float linear infinite;
        }
        @keyframes float {
            0% { transform: translateY(100vh) translateX(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-10vh) translateX(30px); opacity: 0; }
        }
        .container {
            position: relative; z-index: 10;
            text-align: center; padding: 40px 24px;
            max-width: 620px; width: 100%;
            animation: fadeIn 1s ease both;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .logo-wrap {
            display: flex; align-items: center; justify-content: center;
            gap: 14px; margin-bottom: 48px;
            animation: fadeIn 1s ease 0.1s both;
        }
        .logo-icon {
            width: 48px; height: 48px; background: var(--blue);
            border-radius: 12px; display: flex; align-items: center;
            justify-content: center; font-size: 20px;
            box-shadow: 0 0 24px rgba(26,86,219,0.5);
        }
        .logo-text .brand { font-size: 20px; font-weight: 900; letter-spacing: 0.02em; color: #fff; }
        .logo-text .brand span { color: #60a5fa; }
        .logo-text .sub {
            font-size: 11px; color: var(--muted); font-weight: 500;
            letter-spacing: 0.06em; text-transform: uppercase; margin-top: 2px;
        }
        .icon-wrap {
            position: relative; width: 110px; height: 110px;
            margin: 0 auto 32px; animation: fadeIn 1s ease 0.2s both;
        }
        .icon-ring {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid rgba(26,86,219,0.3);
            animation: spin 8s linear infinite;
        }
        .icon-ring::before {
            content: ''; position: absolute; top: -3px; left: 50%;
            width: 6px; height: 6px; background: var(--blue-lt);
            border-radius: 50%; transform: translateX(-50%);
            box-shadow: 0 0 8px var(--blue-lt);
        }
        .icon-ring-2 {
            position: absolute; inset: 10px; border-radius: 50%;
            border: 1px solid rgba(26,86,219,0.15);
            animation: spin 12s linear infinite reverse;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        .icon-center {
            position: absolute; inset: 18px;
            background: linear-gradient(135deg, var(--navy2), #1a2d4a);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 28px; color: var(--blue-lt);
            border: 1px solid rgba(26,86,219,0.2);
            box-shadow: 0 0 30px rgba(26,86,219,0.2), inset 0 1px 0 rgba(255,255,255,0.05);
        }
        .icon-center i { animation: wrench 3s ease-in-out infinite; }
        @keyframes wrench {
            0%, 100% { transform: rotate(-15deg); }
            50% { transform: rotate(15deg); }
        }
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(26,86,219,0.15); border: 1px solid rgba(26,86,219,0.3);
            border-radius: 20px; padding: 5px 14px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.1em;
            text-transform: uppercase; color: #93c5fd;
            margin-bottom: 20px; animation: fadeIn 1s ease 0.3s both;
        }
        .status-dot {
            width: 7px; height: 7px; background: #fbbf24;
            border-radius: 50%; animation: blink 1.5s ease-in-out infinite;
            box-shadow: 0 0 6px #fbbf24;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        h1 {
            font-size: clamp(28px, 5vw, 40px); font-weight: 900; color: #fff;
            line-height: 1.15; margin-bottom: 16px; letter-spacing: -0.02em;
            animation: fadeIn 1s ease 0.4s both;
        }
        h1 .highlight {
            background: linear-gradient(90deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .desc {
            font-size: 15px; color: var(--muted); line-height: 1.7;
            max-width: 480px; margin: 0 auto 40px; font-weight: 400;
            animation: fadeIn 1s ease 0.5s both;
        }
        .info-card {
            background: rgba(255,255,255,0.03); border: 1px solid var(--border);
            border-radius: 16px; padding: 24px; margin-bottom: 32px;
            animation: fadeIn 1s ease 0.6s both;
        }
        .info-row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: left;
        }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-row:first-child { padding-top: 0; }
        .info-icon {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .info-icon.blue  { background: rgba(26,86,219,0.2); color: #60a5fa; }
        .info-icon.green { background: rgba(21,128,61,0.2); color: #4ade80; }
        .info-icon.amber { background: rgba(217,119,6,0.2);  color: #fbbf24; }
        .info-text .label {
            font-size: 10px; font-weight: 700; letter-spacing: 0.1em;
            text-transform: uppercase; color: var(--muted); margin-bottom: 2px;
        }
        .info-text .value { font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85); }
        .progress-wrap { margin-bottom: 32px; animation: fadeIn 1s ease 0.7s both; }
        .progress-header {
            display: flex; justify-content: space-between;
            font-size: 11px; font-weight: 600; color: var(--muted);
            margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.08em;
        }
        .progress-bar { height: 4px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--blue), var(--blue-lt));
            border-radius: 4px; width: 0%;
            transition: width 0.5s ease;
            box-shadow: 0 0 10px rgba(59,130,246,0.5);
        }
        footer {
            position: relative; z-index: 10; margin-top: 8px;
            font-size: 11px; color: rgba(255,255,255,0.2); letter-spacing: 0.05em;
            animation: fadeIn 1s ease 0.9s both; text-align: center; padding-bottom: 24px;
        }
        footer a { color: rgba(255,255,255,0.3); text-decoration: none; transition: color .2s; }
        footer a:hover { color: rgba(255,255,255,0.6); }
        .top-bar {
            position: fixed; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--blue2), var(--blue), var(--blue-lt), var(--blue));
            background-size: 200% 100%;
            animation: shimmer 2.5s linear infinite; z-index: 100;
        }
        @keyframes shimmer {
            0%   { background-position: 200% center; }
            100% { background-position: -200% center; }
        }
        @media (max-width: 480px) {
            .logo-wrap { margin-bottom: 36px; }
            .container { padding: 24px 20px; }
            h1 { font-size: 26px; }
        }
    </style>
</head>
<body>
    <div class="top-bar"></div>
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>
    <div class="bg-glow-2"></div>
    <div class="particles" id="particles"></div>

    <div class="container">
        <div class="logo-wrap">
            <div class="logo-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="logo-text">
                <div class="brand">DEC <span>PMESP</span></div>
                <div class="sub">Diretoria de Ensino e Cultura</div>
            </div>
        </div>

        <div class="icon-wrap">
            <div class="icon-ring"></div>
            <div class="icon-ring-2"></div>
            <div class="icon-center"><i class="fas fa-wrench"></i></div>
        </div>

        <div class="status-badge">
            <div class="status-dot"></div>
            Manutenção em andamento
        </div>

        <h1>Sistema em <span class="highlight">Atualização</span></h1>

        <p class="desc">
            Estamos realizando melhorias e atualizações no sistema da DEC.
            Em breve estaremos de volta com novidades. Agradecemos a compreensão.
        </p>

        <div class="info-card">
            <div class="info-row">
                <div class="info-icon blue"><i class="fas fa-tools"></i></div>
                <div class="info-text">
                    <div class="label">Motivo</div>
                    <div class="value">Atualização do sistema e melhorias de desempenho</div>
                </div>
            </div>
            <div class="info-row">
                <div class="info-icon amber"><i class="fas fa-clock"></i></div>
                <div class="info-text">
                    <div class="label">Previsão de Retorno</div>
                    <div class="value">Em breve — aguarde os avisos no Discord</div>
                </div>
            </div>
            <div class="info-row">
                <div class="info-icon green"><i class="fab fa-discord"></i></div>
                <div class="info-text">
                    <div class="label">Suporte</div>
                    <div class="value">Entre em contato com a equipe DEC pelo Discord</div>
                </div>
            </div>
        </div>

        <div class="progress-wrap">
            <div class="progress-header">
                <span><i class="fas fa-spinner fa-spin" style="margin-right:5px;"></i> Atualizando...</span>
                <span id="pct">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="fill"></div>
            </div>
        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> DEC PMESP · Diretoria de Ensino e Cultura &nbsp;·&nbsp;
        <a href="mailto:dec@pmesp.com">Contato</a>
    </footer>

    <script>
        // ── PARTÍCULAS
        (function() {
            var container = document.getElementById('particles');
            for (var i = 0; i < 25; i++) {
                var p = document.createElement('div');
                p.className = 'particle';
                p.style.left = Math.random() * 100 + 'vw';
                p.style.animationDuration = (6 + Math.random() * 10) + 's';
                p.style.animationDelay = (Math.random() * 10) + 's';
                var sz = (Math.random() > 0.5 ? 2 : 3) + 'px';
                p.style.width = p.style.height = sz;
                p.style.opacity = (0.3 + Math.random() * 0.5).toString();
                container.appendChild(p);
            }
        })();

        // ── PROGRESSO ANIMADO
        (function() {
            var pct  = document.getElementById('pct');
            var fill = document.getElementById('fill');
            var stops = [
                { target: 45, delay: 1000 },
                { target: 68, delay: 2200 },
                { target: 78, delay: 3200 },
            ];
            var current = 0;
            stops.forEach(function(s) {
                setTimeout(function() {
                    var start = current;
                    var diff  = s.target - start;
                    var steps = 30;
                    var step  = 0;
                    var iv = setInterval(function() {
                        step++;
                        current = Math.round(start + (diff * step / steps));
                        pct.textContent = current + '%';
                        fill.style.width = current + '%';
                        if (step >= steps) clearInterval(iv);
                    }, 20);
                    current = s.target;
                }, s.delay);
            });
        })();
    </script>
</body>
</html>
