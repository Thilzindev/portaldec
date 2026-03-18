<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
session_start();
require "conexao.php";

if (!isset($_SESSION['id'])) {
    exit;
}

$usuario_id = intval($_SESSION['id']); // ✅ CORRIGIDO: cast para inteiro
$curso = trim($_POST['curso'] ?? '');

if ($curso === '') {
    exit;
}

// ✅ CORRIGIDO: era query direta com SQL Injection. Agora usa prepared statement.
$stmt = $conexao->prepare("SELECT meus_cursos FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

$cursos = (!empty($row['meus_cursos'])) ? explode(',', $row['meus_cursos']) : [];

if (!in_array($curso, $cursos)) {
    $cursos[] = $curso;
}

$cursos_str = implode(',', $cursos);

// ✅ CORRIGIDO: era query direta com SQL Injection. Agora usa prepared statement.
$stmt2 = $conexao->prepare("UPDATE usuarios SET meus_cursos = ? WHERE id = ?");
$stmt2->bind_param("si", $cursos_str, $usuario_id);
$stmt2->execute();
$stmt2->close();

echo "OK";
