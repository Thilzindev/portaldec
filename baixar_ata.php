<?php
// baixar_ata.php — com suporte a duracao_aula, turno_aula e auxiliares
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

if (!isset($_SESSION["usuario"])) {
    header("Location: login.php"); exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo "ID inválido."; exit; }

require "conexao.php";
$conexao->set_charset("utf8mb4");

// Detecta colunas disponíveis
$colsExist = [];
$colInfo = $conexao->query("SHOW COLUMNS FROM registros_presenca");
if ($colInfo) { while ($c = $colInfo->fetch_assoc()) $colsExist[] = $c['Field']; }

$temInstrutor     = in_array('instrutor',      $colsExist);
$temObs           = in_array('observacoes',    $colsExist);
$temListaPresenca = in_array('lista_presenca', $colsExist);
$temDuracao       = in_array('duracao_aula',   $colsExist);
$temTurno         = in_array('turno_aula',     $colsExist);
$temAuxiliares    = in_array('auxiliares',     $colsExist);

$selectCols = 'id, nome_referencia, data_registro';
if ($temInstrutor)     $selectCols .= ', instrutor';
if ($temObs)           $selectCols .= ', observacoes';
if ($temListaPresenca) $selectCols .= ', lista_presenca';
if ($temDuracao)       $selectCols .= ', duracao_aula';
if ($temTurno)         $selectCols .= ', turno_aula';
if ($temAuxiliares)    $selectCols .= ', auxiliares';
$selectCols .= ', arquivo_pdf';

$stmt = $conexao->prepare("SELECT {$selectCols} FROM registros_presenca WHERE id=? LIMIT 1");
if (!$stmt) { http_response_code(500); echo "Erro DB."; exit; }
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) { http_response_code(404); echo "Ata não encontrada."; exit; }

$nomeAula    = $row['nome_referencia'] ?? 'Aula';
$instrutor   = $row['instrutor']      ?? '';
$obs         = $row['observacoes']    ?? '';
$listaTexto  = $row['lista_presenca'] ?? '';
$duracao     = $row['duracao_aula']   ?? '';
$turno       = $row['turno_aula']     ?? '';
$auxiliares  = $row['auxiliares']     ?? '';
$dataRegistro = isset($row['data_registro'])
    ? date('d/m/Y \à\s H:i', strtotime($row['data_registro']))
    : date('d/m/Y');

// Se não tem lista_presenca, serve blob original
if (!$temListaPresenca || trim($listaTexto) === '') {
    if (!empty($row['arquivo_pdf'])) {
        $nomeArquivo = 'ATA_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomeAula) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($row['arquivo_pdf']));
        echo $row['arquivo_pdf'];
        exit;
    }
    http_response_code(404); echo "PDF não disponível."; exit;
}

// Tenta usar FPDF para regenerar PDF
$fpdfPath = null;
foreach ([__DIR__ . '/fpdf/fpdf.php', __DIR__ . '/vendor/fpdf/fpdf.php', __DIR__ . '/libs/fpdf.php'] as $p) {
    if (file_exists($p)) { $fpdfPath = $p; break; }
}

if ($fpdfPath) {
    require $fpdfPath;
    gerarComFPDF($nomeAula, $instrutor, $obs, $listaTexto, $dataRegistro, '', $duracao, $turno, $auxiliares);
} else {
    if (!empty($row['arquivo_pdf'])) {
        $nomeArquivo = 'ATA_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomeAula) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($row['arquivo_pdf']));
        echo $row['arquivo_pdf'];
        exit;
    }
    http_response_code(500); echo "FPDF não encontrado e PDF original indisponível."; exit;
}

function conv($str) {
    return iconv('UTF-8', 'CP1252//TRANSLIT', $str);
}

function normalizarListaServidor($texto) {
    if (strpos($texto, ',') !== false) {
        $itens = preg_split('/[\n,]/', $texto);
    } else {
        $itens = explode("\n", $texto);
    }
    return array_values(array_filter(array_map('trim', $itens)));
}

function gerarComFPDF($nomeAula, $instrutor, $obs, $listaTexto, $dataRegistro, $curso = '', $duracao = '', $turno = '', $auxiliares = '') {
    $alunos      = normalizarListaServidor($listaTexto);
    $nomeArquivo = 'ATA_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomeAula) . '.pdf';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    // Cabeçalho azul marinho
    $pdf->SetFillColor(13, 27, 46);
    $pdf->Rect(0, 0, 210, 28, 'F');
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(0, 7);
    $pdf->Cell(210, 10, conv('ATA DE REGISTRO E FREQUÊNCIA'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(0, 19);
    $pdf->Cell(210, 6, conv('DEC · PMESP · ' . $dataRegistro), 0, 1, 'C');

    // Dados da aula
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetXY(14, 33);

    if ($curso)      $pdf->Cell(0, 6, conv('Curso: ' . $curso), 0, 1);
    $pdf->Cell(0, 6, conv('Aula: ' . $nomeAula), 0, 1);
    if ($instrutor)  $pdf->Cell(0, 6, conv('Instrutor: ' . $instrutor), 0, 1);
    if ($duracao)    $pdf->Cell(0, 6, conv('Duração: ' . $duracao), 0, 1);
    if ($turno)      $pdf->Cell(0, 6, conv('Turno: ' . $turno), 0, 1);
    if ($auxiliares) $pdf->Cell(0, 6, conv('Auxiliar(es): ' . $auxiliares), 0, 1);
    if ($obs)        $pdf->Cell(0, 6, conv('Observações: ' . $obs), 0, 1);

    $pdf->Ln(3);

    // Divisor
    $pdf->SetDrawColor(13, 27, 46);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(14, $pdf->GetY(), 196, $pdf->GetY());
    $pdf->Ln(4);

    // Título da lista
    $pdf->SetFillColor(13, 27, 46);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 8, conv(' LISTAGEM DE ALUNOS (' . count($alunos) . ' presentes)'), 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Ln(1);

    // Linhas dos alunos
    $fill = false;
    foreach ($alunos as $i => $aluno) {
        if ($pdf->GetY() > 272) {
            $pdf->AddPage();
            $pdf->SetFillColor(13, 27, 46);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 8, conv(' LISTAGEM DE ALUNOS (continuação)'), 0, 1, 'L', true);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->Ln(1);
        }
        $bgR = $fill ? 242 : 250; $bgG = $fill ? 246 : 251; $bgB = $fill ? 255 : 253;
        $pdf->SetFillColor($bgR, $bgG, $bgB);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(130, 140, 155);
        $pdf->Cell(12, 7, ($i + 1) . '.', 0, 0, 'R', true);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 7, conv('  ' . $aluno), 0, 1, 'L', true);
        $fill = !$fill;
    }

    // Rodapé
    $pdf->Ln(4);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(160, 160, 160);
    $pdf->Cell(0, 5, conv('DEC · PMESP · ' . date('d/m/Y H:i')), 0, 0, 'C');

    $pdf->Output('D', $nomeArquivo);
    exit;
}