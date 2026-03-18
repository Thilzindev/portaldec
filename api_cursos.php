<?php
/**
 * api_cursos.php — API de polling para atualização em tempo real dos cursos
 */
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once 'conexao.php';

ob_clean();

function iconeCurso(string $tipo): string {
    $mapa = ['Formação'=>'fa-user-shield','Oficial'=>'fa-star','Sargento'=>'fa-user-tie','Cabo'=>'fa-shield-alt'];
    foreach ($mapa as $k => $ico) {
        if (stripos($tipo, $k) !== false) return $ico;
    }
    return 'fa-graduation-cap';
}

function formataTaxa(float $val): string {
    return $val <= 0 ? 'Gratuito' : 'R$ ' . number_format($val, 0, ',', '.');
}

$todos = db_query($conexao,
    'SELECT id, nome, descricao, tipo_curso, valor_taxa, status, alunos_matriculados FROM cursos ORDER BY nome ASC'
) ?: [];

$hashData     = [];
$cursosAbertos = [];
$i = 0;

foreach ($todos as $r) {
    $hashData[] = $r['id'].'|'.$r['nome'].'|'.$r['status'].'|'.$r['alunos_matriculados'].'|'.$r['valor_taxa'];
    if ($r['status'] === 'Aberto') {
        $taxa = formataTaxa((float)($r['valor_taxa'] ?? 0));
        $cursosAbertos[] = [
            'id'                 => (int) $r['id'],
            'nome'               => $r['nome'],
            'descricao'          => $r['descricao'] ?? '',
            'tipo_curso'         => $r['tipo_curso'] ?? 'Curso',
            'icone'              => iconeCurso($r['tipo_curso'] ?? ''),
            'taxa_formatada'     => $taxa,
            'gratis'             => $taxa === 'Gratuito',
            'status'             => $r['status'],
            'alunos_matriculados'=> (int) $r['alunos_matriculados'],
        ];
        $i++;
    }
}

$hash = md5(implode(';', $hashData));

echo json_encode([
    'ok'           => true,
    'hash'         => $hash,
    'cursos'       => $cursosAbertos,
    'total_cursos' => count($cursosAbertos),
    'total_alunos' => (int) array_sum(array_column($cursosAbertos, 'alunos_matriculados')),
], JSON_UNESCAPED_UNICODE);
