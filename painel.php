<?php
/**
 * painel.php — Roteador de painéis por nível de acesso
 */
session_start();

if (!isset($_SESSION['nivel'])) {
    header('Location: login.php');
    exit;
}

$nivel = (int) $_SESSION['nivel'];

if ($nivel === 1) {
    header('Location: painel_admin.php');
} elseif ($nivel === 2) {
    header('Location: painel_instrutor.php');
} elseif ($nivel === 3) {
    header('Location: painel_aluno.php');
} else {
    // Nível desconhecido — desloga por segurança
    session_destroy();
    header('Location: login.php');
}
exit;
