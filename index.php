<?php
/**
 * index.php — Página pública do Portal DEC PMESP
 */
require_once 'conexao.php';

// Busca apenas cursos ABERTOS para exibição
$cursos = db_query($conexao,
    "SELECT id, nome, descricao, tipo_curso, valor_taxa, status, alunos_matriculados
     FROM cursos WHERE status = 'Aberto' ORDER BY nome ASC"
) ?: [];

$total_cursos = count($cursos);
$total_alunos = (int) array_sum(array_column($cursos, 'alunos_matriculados'));

// Hash inicial para sincronizar polling JS
$hash_rows = db_query($conexao,
    "SELECT id, nome, status, alunos_matriculados, valor_taxa FROM cursos ORDER BY nome ASC"
) ?: [];
$hash_inicial = md5(implode(';', array_map(
    fn($r) => $r['id'].'|'.$r['nome'].'|'.$r['status'].'|'.$r['alunos_matriculados'].'|'.$r['valor_taxa'],
    $hash_rows
)));

// ── Helpers de apresentação ──────────────────────────────────────────────────
function iconeCurso(string $tipo): string {
    $mapa = ['Formação'=>'fa-user-shield','Oficial'=>'fa-star','Sargento'=>'fa-user-tie','Cabo'=>'fa-shield-alt'];
    foreach ($mapa as $k => $ico) {
        if (stripos($tipo, $k) !== false) return $ico;
    }
    return 'fa-graduation-cap';
}
function gradienteCurso(int $i): string {
    $gs = [
        'linear-gradient(135deg,#1a56db,#7c3aed)',
        'linear-gradient(135deg,#0891b2,#1a56db)',
        'linear-gradient(135deg,#7c3aed,#db2777)',
        'linear-gradient(135deg,#d97706,#dc2626)',
    ];
    return $gs[$i % count($gs)];
}
function formataTaxa(float $val): string {
    return $val <= 0 ? 'Gratuito' : 'R$ ' . number_format($val, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DEC · Diretoria de Ensino e Cultura | PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0d1b2e;--navy2:#112240;--blue:#1a56db;--blue2:#1e40af;--bg:#f1f5f9;--white:#fff;--border:#e2e8f0;--text:#1e293b;--muted:#64748b;--light:#f8fafc;--green:#15803d;--gp:#dcfce7;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Inter',sans-serif;color:var(--text);background:var(--bg);overflow-x:hidden;}
.navbar{position:sticky;top:0;z-index:200;background:var(--navy);border-bottom:1px solid rgba(255,255,255,.07);box-shadow:0 4px 20px rgba(0,0,0,.3);}
.nav-inner{max-width:1200px;margin:0 auto;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-logo{width:44px;height:44px;border-radius:11px;background:var(--blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.nav-logo img{width:28px;height:28px;object-fit:contain;}
.nav-logo i{color:#fff;font-size:18px;display:none;}
.nav-name{font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:#fff;line-height:1.1;}
.nav-name span{color:#60a5fa;}
.nav-sub{font-size:10px;color:rgba(255,255,255,.35);letter-spacing:.05em;margin-top:2px;}
.nav-links{display:flex;align-items:center;gap:4px;list-style:none;}
.nav-links a{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:500;color:rgba(255,255,255,.5);text-decoration:none;transition:all .18s;}
.nav-links a:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9);}
.nav-links a.active{color:#fff;}
.nav-links .nav-cta{background:var(--blue);color:#fff!important;font-weight:700;box-shadow:0 4px 14px rgba(26,86,219,.4);margin-left:6px;}
.nav-links .nav-cta:hover{background:var(--blue2);}
.hamburger{display:none;background:none;border:none;cursor:pointer;color:rgba(255,255,255,.7);font-size:20px;padding:6px;}
.mob-menu{display:none;flex-direction:column;background:var(--navy2);border-top:1px solid rgba(255,255,255,.07);padding:10px 16px 16px;}
.mob-menu.open{display:flex;}
.mob-menu a{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:8px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .15s;}
.mob-menu a:hover{background:rgba(255,255,255,.07);color:#fff;}
.mob-menu .nav-cta{background:var(--blue);color:#fff!important;font-weight:700;margin-top:6px;}
.hero{background:var(--navy);padding:96px 32px 80px;text-align:center;position:relative;overflow:hidden;}
.hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 60% at 15% 10%,rgba(26,86,219,.22) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 85% 90%,rgba(124,58,237,.14) 0%,transparent 60%);pointer-events:none;}
.hero::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.055) 1px,transparent 1px);background-size:30px 30px;pointer-events:none;}
.hero-inner{max-width:800px;margin:0 auto;position:relative;z-index:1;}
.hero-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(26,86,219,.2);border:1px solid rgba(26,86,219,.4);border-radius:20px;padding:5px 16px;font-size:11px;font-weight:700;color:#93c5fd;letter-spacing:.08em;text-transform:uppercase;margin-bottom:26px;animation:fadeUp .5s ease both;}
.hero-title{font-family:'Sora',sans-serif;font-size:clamp(30px,5.5vw,54px);font-weight:800;color:#fff;line-height:1.1;margin-bottom:18px;animation:fadeUp .5s .08s ease both;}
.hero-title span{color:#60a5fa;}
.hero-desc{font-size:clamp(14px,2vw,17px);color:rgba(255,255,255,.5);line-height:1.75;max-width:580px;margin:0 auto 20px;animation:fadeUp .5s .14s ease both;}
.hero-mta{display:inline-flex;align-items:center;gap:10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:10px 18px;margin-bottom:38px;animation:fadeUp .5s .18s ease both;cursor:pointer;transition:all .2s;text-decoration:none;}
.hero-mta:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);}
.hero-mta-ico{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;flex-shrink:0;}
.hero-mta-label{font-size:10px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.07em;text-align:left;}
.hero-mta-ip{font-size:13px;font-weight:700;color:#fff;font-family:'Sora',sans-serif;margin-top:1px;}
.hero-mta-copy{font-size:11px;color:rgba(255,255,255,.3);margin-left:auto;padding-left:12px;}
.hero-mta-copy.copied{color:#60a5fa;}
.hero-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;animation:fadeUp .5s .24s ease both;}
.btn-prim{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:700;text-decoration:none;box-shadow:0 4px 18px rgba(26,86,219,.4);transition:all .2s;cursor:pointer;}
.btn-prim:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(26,86,219,.5);}
.btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.14);color:rgba(255,255,255,.8);border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;font-weight:600;text-decoration:none;transition:all .2s;}
.btn-ghost:hover{background:rgba(255,255,255,.12);color:#fff;transform:translateY(-2px);}
.stats-bar{background:rgba(255,255,255,.05);border-top:1px solid rgba(255,255,255,.07);}
.stats-inner{max-width:1100px;margin:0 auto;padding:0 32px;display:flex;flex-wrap:wrap;}
.stat-item{flex:1;min-width:140px;display:flex;align-items:center;gap:12px;padding:20px 28px;border-right:1px solid rgba(255,255,255,.07);}
.stat-item:last-child{border-right:none;}
.stat-ico{width:38px;height:38px;border-radius:10px;background:rgba(26,86,219,.2);display:flex;align-items:center;justify-content:center;font-size:15px;color:#60a5fa;flex-shrink:0;}
.stat-val{font-family:'Sora',sans-serif;font-size:20px;font-weight:800;color:#fff;line-height:1;}
.stat-lbl{font-size:10px;color:rgba(255,255,255,.38);font-weight:500;margin-top:2px;text-transform:uppercase;letter-spacing:.05em;}
.sec{padding:80px 32px;}
.sec-light{background:var(--bg);}
.sec-white{background:var(--white);}
.sec-inner{max-width:1200px;margin:0 auto;}
.sec-head{text-align:center;margin-bottom:52px;}
.sec-eye{display:inline-flex;align-items:center;gap:6px;background:#dbeafe;border:1px solid #bfdbfe;border-radius:20px;padding:4px 13px;font-size:10px;font-weight:700;color:var(--blue2);letter-spacing:.1em;text-transform:uppercase;margin-bottom:12px;}
.sec-title{font-family:'Sora',sans-serif;font-size:clamp(22px,3.5vw,32px);font-weight:800;color:var(--navy);margin-bottom:10px;}
.sec-desc{font-size:15px;color:var(--muted);max-width:560px;margin:0 auto;line-height:1.65;}
.update-bar{display:flex;align-items:center;justify-content:flex-end;gap:8px;max-width:1200px;margin:0 auto 16px;font-size:11px;color:var(--muted);}
.update-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;animation:pulse-dot 2s ease-in-out infinite;}
.update-dot.erro{background:#ef4444;animation:none;}
.update-dot.carregando{background:#f59e0b;animation:pulse-dot .6s ease-in-out infinite;}
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.7);}}
.cursos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:24px;}
.curso-card{background:var(--white);border:1px solid var(--border);border-radius:18px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07);display:flex;flex-direction:column;transition:transform .25s,box-shadow .25s;}
.curso-card:hover{transform:translateY(-6px);box-shadow:0 18px 40px rgba(26,86,219,.14);}
.curso-card.novo{animation:cardEntrada .4s ease both;}
@keyframes cardEntrada{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}
.cc-head{padding:28px 28px 22px;position:relative;overflow:hidden;}
.cc-head::after{content:'';position:absolute;bottom:-24px;right:-24px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.07);}
.cc-ico-wrap{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;margin-bottom:16px;position:relative;z-index:1;}
.cc-tipo{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.55);margin-bottom:4px;position:relative;z-index:1;}
.cc-nome{font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:#fff;line-height:1.2;position:relative;z-index:1;}
.cc-body{padding:22px 28px;flex:1;display:flex;flex-direction:column;gap:14px;}
.cc-desc{font-size:13.5px;color:var(--muted);line-height:1.7;}
.cc-chips{display:flex;flex-wrap:wrap;gap:7px;}
.cc-chip{display:inline-flex;align-items:center;gap:5px;background:var(--light);border:1px solid var(--border);border-radius:20px;padding:4px 11px;font-size:11px;font-weight:600;color:var(--muted);}
.cc-chip.taxa-chip{background:#eff6ff;border-color:#bfdbfe;color:var(--blue2);}
.cc-chip.taxa-chip.gratis{background:var(--gp);border-color:#bbf7d0;color:var(--green);}
.cc-chip i{font-size:10px;}
.cc-foot{padding:14px 28px;border-top:1px solid var(--border);background:var(--light);display:flex;align-items:center;justify-content:space-between;}
.badge-status{display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:4px 11px;font-size:11px;font-weight:700;}
.badge-aberto{background:var(--gp);border:1px solid #bbf7d0;color:var(--green);}
.badge-fechado{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.badge-aberto i,.badge-fechado i{font-size:7px;}
.cursos-empty{text-align:center;padding:64px 32px;background:var(--white);border:1px solid var(--border);border-radius:18px;}
.cursos-empty i{font-size:40px;color:var(--border);margin-bottom:14px;}
.cursos-empty p{color:var(--muted);font-size:14px;}
.sobre-grid{display:grid;grid-template-columns:1fr 1fr;gap:52px;align-items:center;}
.sobre-text h3{font-family:'Sora',sans-serif;font-size:26px;font-weight:800;color:var(--navy);margin-bottom:14px;}
.sobre-text p{font-size:14px;color:var(--muted);line-height:1.75;margin-bottom:14px;}
.feats{display:flex;flex-direction:column;gap:12px;margin-top:22px;}
.feat{display:flex;align-items:flex-start;gap:12px;background:var(--light);border:1px solid var(--border);border-radius:10px;padding:13px 15px;}
.feat-ico{width:36px;height:36px;border-radius:9px;background:#dbeafe;color:var(--blue);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.feat-title{font-size:13px;font-weight:700;color:var(--text);}
.feat-desc{font-size:12px;color:var(--muted);margin-top:2px;}
.sobre-visual{background:linear-gradient(135deg,var(--navy),#1a3a6b);border-radius:20px;padding:40px 36px;position:relative;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.25);}
.sobre-visual::before{content:'';position:absolute;top:-30px;right:-30px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);}
.sv-icon{font-size:52px;color:#60a5fa;margin-bottom:18px;position:relative;z-index:1;text-align:center;}
.sv-divider{width:40px;height:3px;margin:0 auto 20px;background:linear-gradient(90deg,var(--blue),#7c3aed);border-radius:2px;position:relative;z-index:1;}
.sv-items{display:flex;flex-direction:column;gap:10px;position:relative;z-index:1;}
.sv-item{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px 14px;}
.sv-item i{font-size:13px;color:#60a5fa;flex-shrink:0;width:16px;text-align:center;}
.sv-lbl{font-size:10px;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.06em;}
.sv-val{font-size:13px;font-weight:700;color:#fff;margin-top:1px;}
.hier-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;}
.hier-card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:24px 20px;text-align:center;box-shadow:0 1px 6px rgba(0,0,0,.05);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;}
.hier-card:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(0,0,0,.1);}
.hier-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;}
.hc-gold::before{background:linear-gradient(90deg,#f59e0b,#d97706);}
.hc-blue::before{background:linear-gradient(90deg,var(--blue),var(--blue2));}
.hc-green::before{background:linear-gradient(90deg,#16a34a,#15803d);}
.hc-purple::before{background:linear-gradient(90deg,#7c3aed,#6d28d9);}
.hier-ico{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;margin:0 auto 14px;}
.ic-gold{background:#fef3c7;color:#d97706;}
.ic-blue{background:#dbeafe;color:var(--blue2);}
.ic-green{background:var(--gp);color:var(--green);}
.ic-purple{background:#f5f3ff;color:#7c3aed;}
.hier-cargo{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.hier-nome{font-family:'Sora',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin-bottom:8px;}
.hier-rgpm{display:inline-flex;align-items:center;gap:5px;background:var(--light);border:1px solid var(--border);border-radius:20px;padding:3px 11px;font-size:11px;font-weight:600;color:var(--muted);}
.cta-wrap{background:linear-gradient(135deg,var(--navy) 0%,#1a3a6b 100%);padding:80px 32px;text-align:center;position:relative;overflow:hidden;}
.cta-wrap::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;}
.cta-inner{max-width:680px;margin:0 auto;position:relative;z-index:1;}
.cta-title{font-family:'Sora',sans-serif;font-size:clamp(22px,4vw,36px);font-weight:800;color:#fff;margin-bottom:12px;}
.cta-desc{font-size:15px;color:rgba(255,255,255,.5);margin-bottom:32px;line-height:1.65;}
.cta-btns{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;}
footer{background:var(--navy);border-top:1px solid rgba(255,255,255,.06);padding:30px 32px;text-align:center;}
.foot-brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:12px;}
.foot-logo{width:34px;height:34px;border-radius:9px;background:var(--blue);display:flex;align-items:center;justify-content:center;}
.foot-logo i{color:#fff;font-size:14px;}
.foot-name{font-family:'Sora',sans-serif;font-size:15px;font-weight:800;color:#fff;}
.foot-name span{color:#60a5fa;}
footer p{font-size:12px;color:rgba(255,255,255,.22);margin-bottom:3px;}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.rev{opacity:0;transform:translateY(22px);transition:opacity .55s ease,transform .55s ease;}
.rev.vis{opacity:1;transform:translateY(0);}
@media(max-width:768px){.nav-links{display:none;}.hamburger{display:block;}.sobre-grid{grid-template-columns:1fr;}.sobre-visual{display:none;}.sec{padding:56px 20px;}.hero{padding:60px 20px;}.cta-wrap{padding:56px 20px;}.stats-inner{flex-direction:column;}.stat-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.06);padding:14px 20px;}.stat-item:last-child{border-bottom:none;}}
@media(max-width:480px){.cursos-grid{grid-template-columns:1fr;}.hier-grid{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>

<nav class="navbar">
  <div class="nav-inner">
    <a href="#inicio" class="nav-brand">
      <div class="nav-logo">
        <img src="Imgs/images-removebg-preview.png" alt="DEC"
          onerror="this.style.display='none';this.parentElement.querySelector('i').style.display='block';">
        <i class="fas fa-shield-alt"></i>
      </div>
      <div>
        <div class="nav-name">DEC <span>PMESP</span></div>
        <div class="nav-sub">Diretoria de Ensino e Cultura</div>
      </div>
    </a>
    <ul class="nav-links">
      <li><a href="#inicio">Início</a></li>
      <li><a href="#cursos">Cursos</a></li>
      <li><a href="#sobre">Sobre</a></li>
      <li><a href="#hierarquia">Hierarquia</a></li>
      <li><a href="login.php" class="nav-cta"><i class="fas fa-sign-in-alt" style="font-size:11px;"></i> Área do Aluno</a></li>
    </ul>
    <button class="hamburger" onclick="toggleMenu()" aria-label="Menu">
      <i class="fas fa-bars" id="ham-ico"></i>
    </button>
  </div>
  <div class="mob-menu" id="mob-menu">
    <a href="#inicio"     onclick="closeMenu()"><i class="fas fa-home"></i> Início</a>
    <a href="#cursos"     onclick="closeMenu()"><i class="fas fa-graduation-cap"></i> Cursos</a>
    <a href="#sobre"      onclick="closeMenu()"><i class="fas fa-info-circle"></i> Sobre</a>
    <a href="#hierarquia" onclick="closeMenu()"><i class="fas fa-sitemap"></i> Hierarquia</a>
    <a href="login.php" class="nav-cta"><i class="fas fa-sign-in-alt"></i> Área do Aluno</a>
  </div>
</nav>

<section id="inicio" class="hero">
  <div class="hero-inner">
    <div class="hero-pill"><i class="fas fa-shield-alt"></i> Polícia Militar do Estado de São Paulo</div>
    <h1 class="hero-title">Diretoria de<br><span>Ensino e Cultura</span></h1>
    <p class="hero-desc">Responsável pela formação, treinamento e capacitação dos policiais militares da PMESP. Excelência, disciplina e preparo em cada etapa da carreira.</p>
    <a class="hero-mta" onclick="copiarIP(this)" title="Clique para copiar o IP">
      <div class="hero-mta-ico"><i class="fas fa-server"></i></div>
      <div>
        <div class="hero-mta-label">Servidor MTA Oficial</div>
        <div class="hero-mta-ip">mtasa://131.196.199.187:22003</div>
      </div>
      <div class="hero-mta-copy" id="copy-label"><i class="fas fa-copy"></i> Copiar</div>
    </a>
    <div class="hero-actions">
      <a href="#cursos"   class="btn-prim"><i class="fas fa-graduation-cap"></i> Ver Cursos</a>
      <a href="login.php" class="btn-ghost"><i class="fas fa-sign-in-alt"></i> Área do Aluno</a>
    </div>
  </div>
</section>

<div class="stats-bar" style="background:var(--navy);">
  <div class="stats-inner">
    <div class="stat-item">
      <div class="stat-ico"><i class="fas fa-graduation-cap"></i></div>
      <div>
        <div class="stat-val" id="stat-total-cursos"><?= $total_cursos ?></div>
        <div class="stat-lbl">Cursos Abertos</div>
      </div>
    </div>
    <div class="stat-item">
      <div class="stat-ico"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-val" id="stat-total-alunos"><?= $total_alunos > 0 ? $total_alunos : '—' ?></div>
        <div class="stat-lbl">Alunos Matriculados</div>
      </div>
    </div>
    <div class="stat-item">
      <div class="stat-ico"><i class="fas fa-shield-alt"></i></div>
      <div>
        <div class="stat-val">DEC</div>
        <div class="stat-lbl">PMESP · Nova Capital</div>
      </div>
    </div>
  </div>
</div>

<section id="cursos" class="sec sec-light">
  <div class="sec-inner">
    <div class="sec-head rev">
      <div class="sec-eye"><i class="fas fa-graduation-cap"></i> Formação Policial</div>
      <div class="sec-title">Cursos Disponíveis</div>
      <p class="sec-desc">Todos os cursos abertos para matrícula. Acesse a área do aluno para se alistar.</p>
    </div>
    <div class="update-bar">
      <div class="update-dot carregando" id="update-dot"></div>
      <span id="update-status">Conectando...</span>
    </div>
    <div class="cursos-grid" id="cursos-container">
      <?php if (empty($cursos)): ?>
      <div class="cursos-empty rev">
        <i class="fas fa-folder-open"></i>
        <p>Nenhum curso disponível no momento.<br>Volte em breve para novas turmas.</p>
      </div>
      <?php else: ?>
      <?php foreach ($cursos as $i => $c):
        $ico   = iconeCurso($c['tipo_curso'] ?? '');
        $grad  = gradienteCurso($i);
        $taxa  = formataTaxa((float)($c['valor_taxa'] ?? 0));
        $gratis = $taxa === 'Gratuito';
      ?>
      <div class="curso-card rev" style="transition-delay:<?= round($i * 0.1, 1) ?>s" data-id="<?= (int)$c['id'] ?>">
        <div class="cc-head" style="background:<?= $grad ?>">
          <div class="cc-ico-wrap"><i class="fas <?= htmlspecialchars($ico) ?>"></i></div>
          <div class="cc-tipo"><?= htmlspecialchars($c['tipo_curso'] ?? 'Curso') ?></div>
          <div class="cc-nome"><?= htmlspecialchars($c['nome']) ?></div>
        </div>
        <div class="cc-body">
          <p class="cc-desc"><?= nl2br(htmlspecialchars($c['descricao'] ?? 'Sem descrição disponível.')) ?></p>
          <div class="cc-chips">
            <span class="cc-chip"><i class="fas fa-tag"></i> <?= htmlspecialchars($c['tipo_curso'] ?? '—') ?></span>
            <span class="cc-chip <?= $gratis ? 'taxa-chip gratis' : 'taxa-chip' ?>">
              <i class="fas <?= $gratis ? 'fa-gift' : 'fa-dollar-sign' ?>"></i> <?= $taxa ?>
            </span>
            <?php if ((int)$c['alunos_matriculados'] > 0): ?>
            <span class="cc-chip"><i class="fas fa-users"></i> <?= (int)$c['alunos_matriculados'] ?> aluno(s)</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="cc-foot">
          <span class="badge-status badge-aberto"><i class="fas fa-circle"></i> Inscrições Abertas</span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div style="text-align:center;margin-top:40px;" class="rev">
      <a href="login.php" class="btn-prim" style="padding:14px 36px;font-size:15px;">
        <i class="fas fa-user-plus"></i> Alistar-se em um Curso
      </a>
      <p style="margin-top:12px;font-size:13px;color:var(--muted);">Crie sua conta ou acesse o painel do aluno para se inscrever</p>
    </div>
  </div>
</section>

<section id="sobre" class="sec sec-white">
  <div class="sec-inner">
    <div class="sobre-grid">
      <div class="sobre-text rev">
        <div class="sec-eye" style="margin-bottom:14px;"><i class="fas fa-info-circle"></i> Sobre a DEC</div>
        <h3>Formando os policiais do futuro</h3>
        <p>A Diretoria de Ensino e Cultura (DEC) é responsável por toda a estrutura de ensino da Polícia Militar dentro do servidor MTA Nova Capital.</p>
        <p>Garantimos disciplina, conhecimento e preparo para atuação dentro da corporação, com um sistema de ensino estruturado e comprometido com a excelência.</p>
        <div class="feats">
          <div class="feat"><div class="feat-ico"><i class="fas fa-chalkboard-teacher"></i></div><div><div class="feat-title">Instrutores Especializados</div><div class="feat-desc">Equipe experiente dedicada à formação de qualidade</div></div></div>
          <div class="feat"><div class="feat-ico"><i class="fas fa-clipboard-check"></i></div><div><div class="feat-title">Avaliação Contínua</div><div class="feat-desc">Controle de presença, provas e desempenho</div></div></div>
          <div class="feat"><div class="feat-ico"><i class="fas fa-award"></i></div><div><div class="feat-title">Certificação Oficial</div><div class="feat-desc">Diplomas reconhecidos dentro da corporação</div></div></div>
        </div>
      </div>
      <div class="sobre-visual rev" style="transition-delay:.15s">
        <div class="sv-icon"><i class="fas fa-shield-alt"></i></div>
        <div class="sv-divider"></div>
        <div class="sv-items">
          <div class="sv-item"><i class="fas fa-server"></i><div><div class="sv-lbl">Servidor</div><div class="sv-val">Nova Capital (NC)</div></div></div>
          <div class="sv-item"><i class="fas fa-network-wired"></i><div><div class="sv-lbl">IP do Servidor</div><div class="sv-val" style="font-size:12px;">131.196.199.187:22003</div></div></div>
          <div class="sv-item"><i class="fas fa-gamepad"></i><div><div class="sv-lbl">Plataforma</div><div class="sv-val">MTA San Andreas</div></div></div>
          <div class="sv-item"><i class="fas fa-building"></i><div><div class="sv-lbl">Corporação</div><div class="sv-val">Polícia Militar — SP</div></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="hierarquia" class="sec sec-light">
  <div class="sec-inner">
    <div class="sec-head rev">
      <div class="sec-eye"><i class="fas fa-sitemap"></i> Comando</div>
      <div class="sec-title">Hierarquia da DEC</div>
      <p class="sec-desc">Conheça os oficiais responsáveis pela Diretoria de Ensino e Cultura.</p>
    </div>
    <div class="hier-grid">
      <div class="hier-card hc-gold rev"><div class="hier-ico ic-gold"><i class="fas fa-star"></i></div><div class="hier-cargo">Comandante Geral</div><div class="hier-nome">Coronel Banaszeski</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 525</span></div>
      <div class="hier-card hc-blue rev" style="transition-delay:.07s"><div class="hier-ico ic-blue"><i class="fas fa-user-tie"></i></div><div class="hier-cargo">Coordenador de Ensino</div><div class="hier-nome">Coronel Marlboro</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 1515</span></div>
      <div class="hier-card hc-blue rev" style="transition-delay:.14s"><div class="hier-ico ic-blue"><i class="fas fa-user"></i></div><div class="hier-cargo">Vice Coordenador</div><div class="hier-nome">Capitão Mobreck</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 41297</span></div>
      <div class="hier-card hc-green rev" style="transition-delay:.21s"><div class="hier-ico ic-green"><i class="fas fa-shield-alt"></i></div><div class="hier-cargo">Diretor da DEC</div><div class="hier-nome">2º Ten. VitorW</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 296</span></div>
      <div class="hier-card hc-green rev" style="transition-delay:.28s"><div class="hier-ico ic-green"><i class="fas fa-shield-alt"></i></div><div class="hier-cargo">Diretor da DEC</div><div class="hier-nome">Sub Ten. Junior</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 30194</span></div>
      <div class="hier-card hc-purple rev" style="transition-delay:.35s"><div class="hier-ico ic-purple"><i class="fas fa-user-cog"></i></div><div class="hier-cargo">Administrador da DEC</div><div class="hier-nome">Cabo Salvador</div><span class="hier-rgpm"><i class="fas fa-id-badge" style="font-size:10px;"></i> RGPM 340</span></div>
    </div>
  </div>
</section>

<div class="cta-wrap">
  <div class="cta-inner rev">
    <div class="cta-title">Pronto para ingressar na PMESP?</div>
    <p class="cta-desc">Acesse a área do aluno para se alistar nos cursos de formação e especialização.</p>
    <div class="cta-btns">
      <a href="login.php"    class="btn-prim"><i class="fas fa-sign-in-alt"></i> Acessar Painel</a>
      <a href="cadastro.php" class="btn-ghost"><i class="fas fa-user-plus"></i> Criar Conta</a>
    </div>
  </div>
</div>

<footer>
  <div class="foot-brand">
    <div class="foot-logo"><i class="fas fa-shield-alt"></i></div>
    <div class="foot-name">DEC <span>PMESP</span></div>
  </div>
  <p>© <?= date('Y') ?> DEC — Diretoria de Ensino e Cultura</p>
  <p>Servidor Nova Capital · mtasa://131.196.199.187:22003</p>
</footer>

<script>
function toggleMenu(){const m=document.getElementById('mob-menu'),i=document.getElementById('ham-ico');m.classList.toggle('open');i.className=m.classList.contains('open')?'fas fa-times':'fas fa-bars';}
function closeMenu(){document.getElementById('mob-menu').classList.remove('open');document.getElementById('ham-ico').className='fas fa-bars';}
function copiarIP(el){navigator.clipboard.writeText('mtasa://131.196.199.187:22003').then(()=>{const lbl=document.getElementById('copy-label');lbl.innerHTML='<i class="fas fa-check"></i> Copiado!';lbl.classList.add('copied');setTimeout(()=>{lbl.innerHTML='<i class="fas fa-copy"></i> Copiar';lbl.classList.remove('copied');},2000);});}
const obs=new IntersectionObserver(entries=>{entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('vis');obs.unobserve(e.target);}});},{threshold:0.12});
document.querySelectorAll('.rev').forEach(el=>obs.observe(el));
const secs=document.querySelectorAll('section[id]');
const links=document.querySelectorAll('.nav-links a');
window.addEventListener('scroll',()=>{let cur='';secs.forEach(s=>{if(window.scrollY>=s.offsetTop-80)cur=s.id;});links.forEach(a=>a.classList.toggle('active',a.getAttribute('href')==='#'+cur));},{passive:true});

// ── Polling de cursos ──
const POLL_INTERVAL = 5000;
const gradientes=['linear-gradient(135deg,#1a56db,#7c3aed)','linear-gradient(135deg,#0891b2,#1a56db)','linear-gradient(135deg,#7c3aed,#db2777)','linear-gradient(135deg,#d97706,#dc2626)'];
let ultimoHash = '<?= $hash_inicial ?>';
function horaAtual(){return new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
function setStatus(estado,msg){const dot=document.getElementById('update-dot');const lbl=document.getElementById('update-status');dot.className='update-dot'+(estado!=='ok'?' '+estado:'');lbl.textContent=msg;}
const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
function buildCard(c,idx){const g=gradientes[idx%gradientes.length];const gratis=c.gratis;const alunos=c.alunos_matriculados>0?`<span class="cc-chip"><i class="fas fa-users"></i> ${c.alunos_matriculados} aluno(s)</span>`:'';const badge=c.status==='Aberto'?`<span class="badge-status badge-aberto"><i class="fas fa-circle"></i> Inscrições Abertas</span>`:`<span class="badge-status badge-fechado"><i class="fas fa-circle"></i> Encerrado</span>`;return `<div class="curso-card novo vis" data-id="${c.id}"><div class="cc-head" style="background:${g}"><div class="cc-ico-wrap"><i class="fas ${esc(c.icone)}"></i></div><div class="cc-tipo">${esc(c.tipo_curso)}</div><div class="cc-nome">${esc(c.nome)}</div></div><div class="cc-body"><p class="cc-desc">${esc(c.descricao||'').replace(/\n/g,'<br>')}</p><div class="cc-chips"><span class="cc-chip"><i class="fas fa-tag"></i> ${esc(c.tipo_curso)}</span><span class="cc-chip ${gratis?'taxa-chip gratis':'taxa-chip'}"><i class="fas ${gratis?'fa-gift':'fa-dollar-sign'}"></i> ${esc(c.taxa_formatada)}</span>${alunos}</div></div><div class="cc-foot">${badge}</div></div>`;}
function renderCursos(cursos){const c=document.getElementById('cursos-container');if(!cursos.length){c.innerHTML='<div class="cursos-empty vis"><i class="fas fa-folder-open"></i><p>Nenhum curso disponível no momento.<br>Volte em breve para novas turmas.</p></div>';}else{c.innerHTML=cursos.map((c,i)=>buildCard(c,i)).join('');}}
async function fetchCursos(){try{const r=await fetch('api_cursos.php?_='+Date.now(),{cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);const data=await r.json();if(!data.ok)throw new Error(data.erro||'Erro');if(data.hash!==ultimoHash){ultimoHash=data.hash;renderCursos(data.cursos);document.getElementById('stat-total-cursos').textContent=data.total_cursos;document.getElementById('stat-total-alunos').textContent=data.total_alunos>0?data.total_alunos:'—';setStatus('ok',`Atualizado às ${horaAtual()}`);}else{setStatus('ok','Ao vivo');}}catch(e){setStatus('erro','Falha na conexão — tentando novamente...');}}
document.addEventListener('DOMContentLoaded',()=>{setStatus('carregando','Verificando atualizações...');fetchCursos();setInterval(fetchCursos,POLL_INTERVAL);});
</script>
</body>
</html>
