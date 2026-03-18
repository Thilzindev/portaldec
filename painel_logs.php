<?php
/**
 * painel_logs.php — Painel de Logs · DEC PMESP
 * Acesso exclusivo para Admin (nível 1)
 */
session_start();
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');
require "conexao.php";
require "logs.php";

// Apenas admin
if (!isset($_SESSION["usuario"]) || intval($_SESSION["nivel"] ?? 99) !== 1) {
    header("Location: login.php");
    exit;
}

_garantirTabelaLogs($conexao);

// ── Filtros ───────────────────────────────────────────────
$filtroCategoria  = $_GET['categoria']  ?? '';
$filtroSeveridade = $_GET['severidade'] ?? '';
$filtroRgpm       = trim($_GET['rgpm']  ?? '');
$filtroPagina     = (int)($_GET['pagina'] ?? 1);
$porPagina        = 50;
$offset           = ($filtroPagina - 1) * $porPagina;

$where  = ['1=1'];
$tipos  = '';
$params = [];

if ($filtroCategoria)  { $where[] = 'categoria = ?';  $tipos .= 's'; $params[] = $filtroCategoria; }
if ($filtroSeveridade) { $where[] = 'severidade = ?'; $tipos .= 's'; $params[] = $filtroSeveridade; }
if ($filtroRgpm)       { $where[] = 'rgpm LIKE ?';    $tipos .= 's'; $params[] = "%$filtroRgpm%"; }

$sqlWhere = implode(' AND ', $where);

// Total para paginação
$stmtCount = $conexao->prepare("SELECT COUNT(*) FROM sistema_logs WHERE $sqlWhere");
if ($tipos && $stmtCount) { $stmtCount->bind_param($tipos, ...$params); }
$stmtCount && $stmtCount->execute();
$total = $stmtCount ? $stmtCount->get_result()->fetch_row()[0] : 0;
$totalPaginas = max(1, ceil($total / $porPagina));

// Logs da página atual
$stmtLogs = $conexao->prepare("SELECT * FROM sistema_logs WHERE $sqlWhere ORDER BY criado_em DESC LIMIT ? OFFSET ?");
$tiposLogs = $tipos . 'ii';
$paramsLogs = array_merge($params, [$porPagina, $offset]);
if ($stmtLogs) {
    $stmtLogs->bind_param($tiposLogs, ...$paramsLogs);
    $stmtLogs->execute();
    $logs = $stmtLogs->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $logs = [];
}

// Estatísticas rápidas
$stats = [];
$resStats = $conexao->query("SELECT categoria, COUNT(*) as total FROM sistema_logs GROUP BY categoria ORDER BY total DESC");
while ($row = $resStats->fetch_assoc()) { $stats[] = $row; }

$statsSev = [];
$resSev = $conexao->query("SELECT severidade, COUNT(*) as total FROM sistema_logs GROUP BY severidade");
while ($row = $resSev->fetch_assoc()) { $statsSev[$row['severidade']] = $row['total']; }

$totalGeral = $conexao->query("SELECT COUNT(*) FROM sistema_logs")->fetch_row()[0] ?? 0;
$ultimas24h = $conexao->query("SELECT COUNT(*) FROM sistema_logs WHERE criado_em >= NOW() - INTERVAL 24 HOUR")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logs do Sistema · DEC PMESP</title>
<link rel="icon" type="image/png" href="Imgs/images-removebg-preview.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --navy:    #0d1b2e;
  --navy2:   #0f2340;
  --blue:    #1a56db;
  --blue2:   #1e40af;
  --white:   #ffffff;
  --surface: #111827;
  --card:    #1a2436;
  --border:  rgba(255,255,255,.08);
  --text:    #e2e8f0;
  --muted:   #64748b;
  --green:   #22c55e;
  --yellow:  #f59e0b;
  --red:     #ef4444;
  --purple:  #8b5cf6;
  --mono:    'JetBrains Mono', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--navy);
  color: var(--text);
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
}

/* ── Header ── */
.header {
  background: var(--navy2);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  display: flex; align-items: center; justify-content: space-between;
  height: 60px; position: sticky; top: 0; z-index: 100;
}
.header-brand {
  display: flex; align-items: center; gap: 10px;
  font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 800;
  color: var(--white);
}
.header-brand img { width: 28px; height: 28px; border-radius: 6px; }
.header-right { display: flex; align-items: center; gap: 12px; }
.btn-voltar {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: 8px;
  background: rgba(255,255,255,.06); border: 1px solid var(--border);
  color: var(--text); font-size: 13px; font-weight: 500;
  text-decoration: none; transition: all .2s;
}
.btn-voltar:hover { background: rgba(255,255,255,.12); }

/* ── Layout ── */
.container { max-width: 1400px; margin: 0 auto; padding: 28px; }

/* ── Stats Cards ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px; margin-bottom: 28px;
}
.stat-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 14px; padding: 18px 20px;
}
.stat-card .label {
  font-size: 11px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--muted); margin-bottom: 8px;
}
.stat-card .value {
  font-family: 'Sora', sans-serif; font-size: 26px;
  font-weight: 800; color: var(--white);
}
.stat-card .sub {
  font-size: 11px; color: var(--muted); margin-top: 4px;
}
.stat-card.info   { border-left: 3px solid var(--blue); }
.stat-card.aviso  { border-left: 3px solid var(--yellow); }
.stat-card.erro   { border-left: 3px solid var(--red); }
.stat-card.ok     { border-left: 3px solid var(--green); }

/* ── Filtros ── */
.filtros-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 14px; padding: 20px 24px; margin-bottom: 20px;
}
.filtros-card h3 {
  font-size: 13px; font-weight: 700; color: var(--muted);
  text-transform: uppercase; letter-spacing: .08em; margin-bottom: 16px;
}
.filtros-row {
  display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
}
.filtro-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
.filtro-group label { font-size: 11px; font-weight: 600; color: var(--muted); }
.filtro-group select,
.filtro-group input {
  padding: 8px 12px;
  background: rgba(255,255,255,.05);
  border: 1px solid var(--border);
  border-radius: 8px; color: var(--text);
  font-size: 13px; font-family: 'Inter', sans-serif;
  outline: none; transition: border-color .2s;
}
.filtro-group select:focus,
.filtro-group input:focus { border-color: var(--blue); }
.filtro-group select option { background: #1a2436; }
.btn-filtrar {
  padding: 8px 20px;
  background: var(--blue); border: none; border-radius: 8px;
  color: #fff; font-size: 13px; font-weight: 700;
  cursor: pointer; transition: all .2s; align-self: flex-end;
}
.btn-filtrar:hover { background: var(--blue2); }
.btn-limpar {
  padding: 8px 16px;
  background: transparent; border: 1px solid var(--border); border-radius: 8px;
  color: var(--muted); font-size: 13px; font-weight: 500;
  cursor: pointer; transition: all .2s; text-decoration: none; align-self: flex-end;
  display: inline-flex; align-items: center; gap: 5px;
}
.btn-limpar:hover { border-color: rgba(255,255,255,.2); color: var(--text); }

/* ── Tabela de Logs ── */
.table-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 14px; overflow: hidden;
}
.table-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.table-header h2 {
  font-size: 15px; font-weight: 700; color: var(--white);
  display: flex; align-items: center; gap: 8px;
}
.badge-total {
  font-size: 11px; padding: 3px 8px;
  background: rgba(255,255,255,.08); border-radius: 20px;
  color: var(--muted); font-weight: 600;
}
.table-wrap { overflow-x: auto; }
table {
  width: 100%; border-collapse: collapse;
  font-size: 12.5px;
}
thead th {
  padding: 12px 14px;
  background: rgba(255,255,255,.04);
  text-align: left; font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
tbody tr {
  border-bottom: 1px solid rgba(255,255,255,.04);
  transition: background .15s;
}
tbody tr:hover { background: rgba(255,255,255,.03); }
tbody td {
  padding: 11px 14px; vertical-align: middle;
  color: var(--text);
}
.td-mono { font-family: var(--mono); font-size: 11.5px; }

/* ── Badges ── */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 20px;
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .05em; white-space: nowrap;
}
.badge-INFO    { background: rgba(26,86,219,.2);  color: #93c5fd; }
.badge-AVISO   { background: rgba(245,158,11,.2); color: #fcd34d; }
.badge-ERRO    { background: rgba(239,68,68,.2);  color: #fca5a5; }
.badge-CRITICO { background: rgba(139,92,246,.2); color: #c4b5fd; }

.badge-AUTH      { background: rgba(26,86,219,.15);  color: #93c5fd; }
.badge-CADASTRO  { background: rgba(34,197,94,.15);  color: #86efac; }
.badge-ADMIN     { background: rgba(139,92,246,.15); color: #c4b5fd; }
.badge-INSTRUTOR { background: rgba(245,158,11,.15); color: #fcd34d; }
.badge-ALUNO     { background: rgba(14,165,233,.15); color: #7dd3fc; }
.badge-SISTEMA   { background: rgba(100,116,139,.15);color: #94a3b8; }
.badge-BLACKLIST { background: rgba(239,68,68,.15);  color: #fca5a5; }
.badge-SEGURANCA { background: rgba(251,146,60,.15); color: #fdba74; }

/* ── Detalhes expandível ── */
.btn-detalhe {
  padding: 4px 8px; border-radius: 6px;
  background: rgba(255,255,255,.06); border: 1px solid var(--border);
  color: var(--muted); font-size: 11px; cursor: pointer;
  transition: all .2s;
}
.btn-detalhe:hover { background: rgba(255,255,255,.12); color: var(--text); }
.row-detalhe td {
  padding: 0 !important;
}
.detalhe-inner {
  padding: 12px 14px;
  background: rgba(0,0,0,.25);
  font-family: var(--mono); font-size: 11px; color: #94a3b8;
  border-top: 1px solid var(--border);
  white-space: pre-wrap; word-break: break-all;
}

/* ── Paginação ── */
.paginacao {
  padding: 16px 20px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
}
.pag-info { font-size: 12px; color: var(--muted); }
.pag-links { display: flex; gap: 6px; }
.pag-links a, .pag-links span {
  padding: 6px 12px; border-radius: 8px;
  font-size: 12px; font-weight: 600; text-decoration: none;
  border: 1px solid var(--border);
  color: var(--text); transition: all .2s;
}
.pag-links a:hover { background: rgba(255,255,255,.08); }
.pag-links span.ativo {
  background: var(--blue); border-color: var(--blue); color: #fff;
}

/* ── Empty state ── */
.empty { text-align: center; padding: 60px 20px; color: var(--muted); }
.empty i { font-size: 40px; margin-bottom: 12px; opacity: .4; }
.empty p { font-size: 14px; }

@media (max-width: 768px) {
  .container { padding: 16px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  table { font-size: 11px; }
  thead th, tbody td { padding: 10px 10px; }
}
</style>
</head>
<body>

<div class="header">
  <div class="header-brand">
    <img src="Imgs/images-removebg-preview.png" alt="DEC"
         onerror="this.style.display='none'">
    <span>Logs · DEC PMESP</span>
  </div>
  <div class="header-right">
    <a href="painel.php" class="btn-voltar">
      <i class="fas fa-arrow-left"></i> Painel
    </a>
  </div>
</div>

<div class="container">

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card ok">
      <div class="label"><i class="fas fa-database"></i> Total de Logs</div>
      <div class="value"><?= number_format($totalGeral) ?></div>
      <div class="sub">Todos os registros</div>
    </div>
    <div class="stat-card info">
      <div class="label"><i class="fas fa-clock"></i> Últimas 24h</div>
      <div class="value"><?= number_format($ultimas24h) ?></div>
      <div class="sub">Eventos recentes</div>
    </div>
    <div class="stat-card aviso">
      <div class="label"><i class="fas fa-exclamation-triangle"></i> Avisos</div>
      <div class="value"><?= number_format($statsSev['AVISO'] ?? 0) ?></div>
      <div class="sub">Nível AVISO</div>
    </div>
    <div class="stat-card erro">
      <div class="label"><i class="fas fa-times-circle"></i> Erros/Críticos</div>
      <div class="value"><?= number_format(($statsSev['ERRO'] ?? 0) + ($statsSev['CRITICO'] ?? 0)) ?></div>
      <div class="sub">Requerem atenção</div>
    </div>
    <?php foreach ($stats as $s): ?>
    <div class="stat-card">
      <div class="label"><?= htmlspecialchars($s['categoria']) ?></div>
      <div class="value" style="font-size:20px"><?= number_format($s['total']) ?></div>
      <div class="sub">eventos</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filtros -->
  <div class="filtros-card">
    <h3><i class="fas fa-filter"></i> Filtros</h3>
    <form method="GET" class="filtros-row">
      <div class="filtro-group">
        <label>Categoria</label>
        <select name="categoria">
          <option value="">Todas</option>
          <?php foreach (['AUTH','CADASTRO','ADMIN','INSTRUTOR','ALUNO','SISTEMA','BLACKLIST','SEGURANCA'] as $cat): ?>
          <option value="<?= $cat ?>" <?= $filtroCategoria === $cat ? 'selected' : '' ?>><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filtro-group">
        <label>Severidade</label>
        <select name="severidade">
          <option value="">Todas</option>
          <?php foreach (['INFO','AVISO','ERRO','CRITICO'] as $sev): ?>
          <option value="<?= $sev ?>" <?= $filtroSeveridade === $sev ? 'selected' : '' ?>><?= $sev ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filtro-group">
        <label>RGPM</label>
        <input type="text" name="rgpm" value="<?= htmlspecialchars($filtroRgpm) ?>" placeholder="Ex: 67430">
      </div>
      <button type="submit" class="btn-filtrar">
        <i class="fas fa-search"></i> Filtrar
      </button>
      <a href="painel_logs.php" class="btn-limpar">
        <i class="fas fa-times"></i> Limpar
      </a>
    </form>
  </div>

  <!-- Tabela -->
  <div class="table-card">
    <div class="table-header">
      <h2>
        <i class="fas fa-list-alt" style="color:var(--blue)"></i>
        Registro de Eventos
      </h2>
      <span class="badge-total"><?= number_format($total) ?> resultado(s)</span>
    </div>

    <div class="table-wrap">
      <?php if (empty($logs)): ?>
      <div class="empty">
        <i class="fas fa-inbox"></i>
        <p>Nenhum log encontrado com os filtros atuais.</p>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Data/Hora</th>
            <th>Categoria</th>
            <th>Severidade</th>
            <th>Ação</th>
            <th>Usuário</th>
            <th>RGPM</th>
            <th>IP</th>
            <th>Página</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $i => $log): ?>
          <tr>
            <td class="td-mono" style="color:var(--muted)"><?= $log['id'] ?></td>
            <td class="td-mono" style="white-space:nowrap;color:var(--muted)">
              <?= date('d/m/y H:i:s', strtotime($log['criado_em'])) ?>
            </td>
            <td>
              <span class="badge badge-<?= $log['categoria'] ?>">
                <?= htmlspecialchars($log['categoria']) ?>
              </span>
            </td>
            <td>
              <span class="badge badge-<?= $log['severidade'] ?>">
                <?= htmlspecialchars($log['severidade']) ?>
              </span>
            </td>
            <td style="max-width:280px"><?= htmlspecialchars($log['acao']) ?></td>
            <td><?= htmlspecialchars($log['nome_usuario'] ?? '—') ?></td>
            <td class="td-mono"><?= htmlspecialchars($log['rgpm'] ?? '—') ?></td>
            <td class="td-mono" style="color:var(--muted)"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
            <td class="td-mono" style="color:var(--muted)"><?= htmlspecialchars($log['pagina'] ?? '—') ?></td>
            <td>
              <?php if ($log['detalhes']): ?>
              <button class="btn-detalhe" onclick="toggleDetalhe(<?= $log['id'] ?>)">
                <i class="fas fa-eye"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($log['detalhes']): ?>
          <tr class="row-detalhe" id="detalhe-<?= $log['id'] ?>" style="display:none">
            <td colspan="10">
              <div class="detalhe-inner"><?= htmlspecialchars(json_encode(json_decode($log['detalhes']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Paginação -->
    <?php if ($totalPaginas > 1): ?>
    <div class="paginacao">
      <span class="pag-info">
        Página <?= $filtroPagina ?> de <?= $totalPaginas ?>
        (<?= number_format($total) ?> registros)
      </span>
      <div class="pag-links">
        <?php
        $queryBase = http_build_query(['categoria' => $filtroCategoria, 'severidade' => $filtroSeveridade, 'rgpm' => $filtroRgpm]);
        for ($p = max(1, $filtroPagina - 2); $p <= min($totalPaginas, $filtroPagina + 2); $p++):
        ?>
          <?php if ($p === $filtroPagina): ?>
            <span class="ativo"><?= $p ?></span>
          <?php else: ?>
            <a href="?<?= $queryBase ?>&pagina=<?= $p ?>"><?= $p ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
function toggleDetalhe(id) {
  const row = document.getElementById('detalhe-' + id);
  if (row) {
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
  }
}
</script>
</body>
</html>
