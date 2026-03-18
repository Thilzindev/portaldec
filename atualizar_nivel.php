<?php
session_start();
require "conexao.php";

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ✅ CORRIGIDO: verificação de autenticação e nível admin antes de qualquer ação
if (!isset($_SESSION["usuario"])) {
    echo "nao_autorizado";
    exit;
}

// Apenas admin (nivel 1) pode alterar níveis
if (intval($_SESSION["nivel"] ?? 99) !== 1) {
    echo "nao_autorizado";
    exit;
}

$rgpm  = trim($_POST['rgpm']  ?? '');
$nivel = intval($_POST['nivel'] ?? 0); // ✅ CORRIGIDO: cast para inteiro

if (!$rgpm || !$nivel || $nivel < 1 || $nivel > 3) {
    echo "dados_invalidos";
    exit;
}

// ✅ CORRIGIDO: usa prepared statement (original usava variáveis diretamente)
$sql = "UPDATE usuarios SET nivel = ? WHERE rgpm = ?";
$stmt = $conexao->prepare($sql);
$stmt->bind_param("is", $nivel, $rgpm);

if ($stmt->execute()) {
    $stmt->close();

    // Verifica se alterou o próprio usuário
    if (isset($_SESSION['rgpm']) && $_SESSION['rgpm'] == $rgpm) {
        $_SESSION['nivel'] = $nivel;

        if ($nivel == 1) {
            echo "painel_admin.php";
        } elseif ($nivel == 2) {
            echo "painel_instrutor.php";
        } else {
            echo "painel_aluno.php";
        }
    } else {
        echo "sucesso";
    }
} else {
    $stmt->close();
    echo "erro";
}
