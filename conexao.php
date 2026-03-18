<?php
/**
 * conexao.php — Conexão segura com o banco de dados
 * Lê credenciais do .env (nunca hardcoded aqui)
 */

if (defined('_CONEXAO_LOADED')) return;
define('_CONEXAO_LOADED', true);

// Bloqueia acesso direto a este arquivo
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ── Carrega .env ────────────────────────────────────────────────────────────
$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k); $_v = trim($_v);
        if (!defined($_k)) define($_k, $_v);
        putenv("$_k=$_v");
    }
}

// Fallback para constantes definidas externamente (caso não exista .env)
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'root');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'dec_db');
if (!defined('DB_PORT'))    define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ── Conecta ─────────────────────────────────────────────────────────────────
$conexao = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conexao->connect_error) {
    error_log('[DEC][BD] Falha na conexão: ' . $conexao->connect_error);
    if (!empty($_POST['ajax_action']) || !empty($_GET['ajax_action'])) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'erro' => 'Falha na conexão com o banco de dados']);
        exit;
    }
    http_response_code(503);
    exit('Serviço temporariamente indisponível.');
}

$conexao->set_charset(DB_CHARSET);
$conexao->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$conexao->query("SET time_zone = '-03:00'");

// ── Helpers de query segura ─────────────────────────────────────────────────

/**
 * Executa query com prepared statement
 * Retorna array de rows em SELECT, true em INSERT/UPDATE/DELETE, false em erro
 */
if (!function_exists('db_query')) {
    function db_query(mysqli $db, string $sql, string $tipos = '', array $params = []) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log('[DEC][DB] Prepare falhou: ' . $db->error . ' | SQL: ' . $sql);
            return false;
        }
        if ($tipos !== '' && $params) {
            $stmt->bind_param($tipos, ...$params);
        }
        if (!$stmt->execute()) {
            error_log('[DEC][DB] Execute falhou: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $stmt->close();
        if ($result instanceof mysqli_result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return true;
    }
}

/**
 * Retorna apenas a primeira linha de um SELECT
 */
if (!function_exists('db_row')) {
    function db_row(mysqli $db, string $sql, string $tipos = '', array $params = []) {
        $rows = db_query($db, $sql, $tipos, $params);
        return (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
    }
}

/**
 * Retorna o valor de uma única coluna da primeira linha
 */
if (!function_exists('db_val')) {
    function db_val(mysqli $db, string $sql, string $tipos = '', array $params = []) {
        $row = db_row($db, $sql, $tipos, $params);
        if ($row === null) return null;
        return array_values($row)[0] ?? null;
    }
}
