<?php
// REMOVER APÓS DIAGNOSTICO
session_start();
date_default_timezone_set('America/Sao_Paulo');
require "conexao.php";

// Só admin pode ver
if (!isset($_SESSION['nivel']) || intval($_SESSION['nivel']) !== 1) {
    die("Acesso negado");
}

$agora = date('Y-m-d H:i:s');
echo "<h2>Hora atual no PHP: {$agora}</h2>";

$qHora = $conexao->query("SELECT NOW() as hora_db");
echo "<h2>Hora atual no MySQL: " . $qHora->fetch_assoc()['hora_db'] . "</h2>";

echo "<h2>Multas pendentes:</h2><pre>";
$q = $conexao->query("SELECT id, usuario_id, nome_aluno, status, prazo_expira, processada, prazo_expira <= NOW() as vencida FROM multas ORDER BY id DESC LIMIT 20");
while ($r = $q->fetch_assoc()) print_r($r);
echo "</pre>";

echo "<h2>Usuários com status Bloqueado:</h2><pre>";
$q2 = $conexao->query("SELECT id, nome, rgpm, status FROM usuarios WHERE status='Bloqueado'");
while ($r = $q2->fetch_assoc()) print_r($r);
echo "</pre>";

echo "<h2>Blacklist:</h2><pre>";
$q3 = $conexao->query("SELECT id, nome, rgpm, motivo_texto, tempo, expiracao FROM blacklist ORDER BY id DESC LIMIT 10");
while ($r = $q3->fetch_assoc()) print_r($r);
echo "</pre>";
