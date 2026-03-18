<?php
// salvar_ata.php — com suporte a duracao_aula, turno_aula e auxiliares
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: text/plain; charset=utf-8');
mb_internal_encoding('UTF-8');

if (!isset($_SESSION["usuario"])) {
    ob_end_clean(); echo "erro: não autorizado"; exit;
}

$tokenPost   = $_POST['csrf_token']   ?? '';
$tokenSessao = $_SESSION['csrf_token'] ?? '';
if (!$tokenSessao || !hash_equals($tokenSessao, $tokenPost)) {
    ob_end_clean(); echo "erro: token CSRF inválido"; exit;
}

require "conexao.php";
$conexao->set_charset("utf8mb4");

$nomeAula    = trim($_POST['nome_aula']      ?? '');
$listaRaw    = trim($_POST['lista_presenca'] ?? '');
if (strpos($listaRaw, ',') !== false) {
    $listaItens = preg_split('/[\n,]/', $listaRaw);
} else {
    $listaItens = explode("\n", $listaRaw);
}
$listaTexto  = implode("\n", array_values(array_filter(array_map('trim', $listaItens))));
$instrutor   = trim($_POST['instrutor']      ?? '');
$observacoes = trim($_POST['observacoes']    ?? '');
$curso       = trim($_POST['curso']          ?? '');
$duracaoAula = trim($_POST['duracao_aula']   ?? '');
$turnoAula   = trim($_POST['turno_aula']     ?? '');
$auxiliares  = trim($_POST['auxiliares']     ?? '');
$arquivo     = $_FILES['meu_pdf']            ?? null;

if (!$nomeAula) { ob_end_clean(); echo "erro: nome da aula vazio"; exit; }
if (!$arquivo || $arquivo['error'] !== UPLOAD_ERR_OK) {
    ob_end_clean(); echo "erro: falha no upload do PDF"; exit;
}

$pdfData = file_get_contents($arquivo['tmp_name']);
if ($pdfData === false) { ob_end_clean(); echo "erro: nao foi possivel ler o arquivo"; exit; }

// Detecta colunas disponíveis e cria as novas automaticamente se necessário
$colsExist = [];
$colInfo = $conexao->query("SHOW COLUMNS FROM registros_presenca");
if ($colInfo) { while ($c = $colInfo->fetch_assoc()) $colsExist[] = $c['Field']; }

$criarSe = [
    'duracao_aula' => "ALTER TABLE registros_presenca ADD COLUMN duracao_aula VARCHAR(100) NOT NULL DEFAULT ''",
    'turno_aula'   => "ALTER TABLE registros_presenca ADD COLUMN turno_aula VARCHAR(100) NOT NULL DEFAULT ''",
    'auxiliares'   => "ALTER TABLE registros_presenca ADD COLUMN auxiliares TEXT NOT NULL DEFAULT ''",
];
foreach ($criarSe as $col => $sql) {
    if (!in_array($col, $colsExist)) {
        $conexao->query($sql);
        $colsExist[] = $col;
    }
}

// Monta colunas e valores dinamicamente
$cols  = ['nome_referencia', 'arquivo_pdf'];
$tipos = 'ss';
$vals  = [$nomeAula, $pdfData];

if (in_array('instrutor',      $colsExist)) { $cols[] = 'instrutor';     $tipos .= 's'; $vals[] = $instrutor; }
if (in_array('observacoes',    $colsExist)) { $cols[] = 'observacoes';   $tipos .= 's'; $vals[] = $observacoes; }
if (in_array('lista_presenca', $colsExist)) { $cols[] = 'lista_presenca';$tipos .= 's'; $vals[] = $listaTexto; }
if (in_array('curso',          $colsExist)) { $cols[] = 'curso';         $tipos .= 's'; $vals[] = $curso; }
if (in_array('duracao_aula',   $colsExist)) { $cols[] = 'duracao_aula';  $tipos .= 's'; $vals[] = $duracaoAula; }
if (in_array('turno_aula',     $colsExist)) { $cols[] = 'turno_aula';    $tipos .= 's'; $vals[] = $turnoAula; }
if (in_array('auxiliares',     $colsExist)) { $cols[] = 'auxiliares';    $tipos .= 's'; $vals[] = $auxiliares; }

$placeholders = implode(', ', array_fill(0, count($cols), '?'));
$colNames     = implode(', ', $cols);

$stmt = $conexao->prepare("INSERT INTO registros_presenca ({$colNames}) VALUES ({$placeholders})");
if (!$stmt) { ob_end_clean(); echo "erro: " . $conexao->error; exit; }

$stmt->bind_param($tipos, ...$vals);
ob_end_clean();
echo $stmt->execute() ? "sucesso" : "erro: " . $stmt->error;