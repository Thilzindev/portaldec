<?php
/**
 * ══════════════════════════════════════════════════════════
 *  logs.php — Sistema de Logs Profissional · DEC PMESP
 *  Registra TUDO: quem fez, o quê, quando e de onde.
 *  Envia notificações via Webhook do Discord.
 * ══════════════════════════════════════════════════════════
 */

if (defined('_LOGS_LOADED')) return;
define('_LOGS_LOADED', true);

// ── CONFIGURAÇÃO ─────────────────────────────────────────
// Canal principal: #status (recebe tudo)
define('LOG_DISCORD_WEBHOOK', 'https://discord.com/api/webhooks/1482179520885952603/277BuDJhFzO6WoLlrFbs2zMvWCcDlP7xOxddf9FsC7E-MkdqxSQenXDGjd5OO52J4PX9');

// Canais específicos por tipo
define('LOG_WEBHOOK_ALUNOS',      'https://discord.com/api/webhooks/1482179607842127932/GUfyijJ_69tZO0ULFpP967M-at-dBfpC5WpDbQNofj_rjBi6PovT1qgV_xNqvSPBiLIO');
define('LOG_WEBHOOK_INSTRUTORES', 'https://discord.com/api/webhooks/1482179734501724253/JbbCXy9Ee2q5i3zr_4JwxUTJfo28B9Sczr12fGatlJ_vaYJLcNEB5r4QWtWLa15BRJJG');
define('LOG_WEBHOOK_ADMIN',       'https://discord.com/api/webhooks/1482179653941723286/TU_lnE8M3ufy6dtLjVyF7hZP2zIBlks2TmrWL_Cnd9BpP6pOz3cv1GQDmEf_HETe5cBC');
define('LOG_WEBHOOK_STATUS',      'https://discord.com/api/webhooks/1482179520885952603/277BuDJhFzO6WoLlrFbs2zMvWCcDlP7xOxddf9FsC7E-MkdqxSQenXDGjd5OO52J4PX9');

// ── CATEGORIAS DE LOG ─────────────────────────────────────
const LOG_AUTH       = 'AUTH';        // Login, logout, senha
const LOG_CADASTRO   = 'CADASTRO';    // Novo cadastro
const LOG_ADMIN      = 'ADMIN';       // Ações do admin
const LOG_INSTRUTOR  = 'INSTRUTOR';   // Ações de instrutores
const LOG_ALUNO      = 'ALUNO';       // Ações de alunos
const LOG_SISTEMA    = 'SISTEMA';     // Erros, manutenção, status
const LOG_BLACKLIST  = 'BLACKLIST';   // Blacklist
const LOG_SEGURANCA  = 'SEGURANCA';   // Tentativas suspeitas

// ── NÍVEIS DE SEVERIDADE ──────────────────────────────────
const SEV_INFO    = 'INFO';
const SEV_AVISO   = 'AVISO';
const SEV_ERRO    = 'ERRO';
const SEV_CRITICO = 'CRITICO';

/**
 * Registra um evento de log no banco E notifica Discord.
 *
 * @param mysqli $db         Conexão com o banco
 * @param string $categoria  Categoria (use as constantes LOG_*)
 * @param string $acao       Descrição da ação (ex: "Login bem-sucedido")
 * @param string $severidade Severidade (use SEV_*)
 * @param array  $extra      Dados extras opcionais
 */
function registrarLog(
    mysqli $db,
    string $categoria,
    string $acao,
    string $severidade = SEV_INFO,
    array  $extra = []
): void {
    // ── Coleta contexto automaticamente ──
    $rgpm      = $_SESSION['rgpm']    ?? ($extra['rgpm']    ?? null);
    $nome      = $_SESSION['usuario'] ?? ($extra['nome']    ?? null);
    $nivel     = $_SESSION['nivel']   ?? ($extra['nivel']   ?? null);
    $ip        = $_SERVER['REMOTE_ADDR']       ?? 'desconhecido';
    $userAgent = $_SERVER['HTTP_USER_AGENT']   ?? 'desconhecido';
    $pagina    = basename($_SERVER['SCRIPT_FILENAME'] ?? 'desconhecido');
    $metodo    = $_SERVER['REQUEST_METHOD']    ?? 'desconhecido';
    $detalhes  = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

    // ── Garante que a tabela existe ──
    _garantirTabelaLogs($db);

    // ── Salva no banco ──
    $stmt = $db->prepare(
        "INSERT INTO sistema_logs
            (categoria, acao, severidade, rgpm, nome_usuario, nivel_usuario,
             ip, user_agent, pagina, metodo, detalhes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    if ($stmt) {
        $stmt->bind_param(
            "sssssssssss",
            $categoria, $acao, $severidade,
            $rgpm, $nome, $nivel,
            $ip, $userAgent, $pagina, $metodo, $detalhes
        );
        $stmt->execute();
        $stmt->close();
    }

    // ── Envia ao Discord ──
    _enviarLogDiscord($categoria, $acao, $severidade, $rgpm, $nome, $nivel, $ip, $pagina, $extra);
}

/**
 * Garante que a tabela sistema_logs existe no banco.
 */
function _garantirTabelaLogs(mysqli $db): void {
    static $verificado = false;
    if ($verificado) return;
    $verificado = true;

    $db->query("CREATE TABLE IF NOT EXISTS sistema_logs (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        categoria     VARCHAR(30)  NOT NULL,
        acao          VARCHAR(255) NOT NULL,
        severidade    ENUM('INFO','AVISO','ERRO','CRITICO') NOT NULL DEFAULT 'INFO',
        rgpm          VARCHAR(30)  DEFAULT NULL,
        nome_usuario  VARCHAR(200) DEFAULT NULL,
        nivel_usuario VARCHAR(10)  DEFAULT NULL,
        ip            VARCHAR(60)  DEFAULT NULL,
        user_agent    TEXT         DEFAULT NULL,
        pagina        VARCHAR(100) DEFAULT NULL,
        metodo        VARCHAR(10)  DEFAULT NULL,
        detalhes      JSON         DEFAULT NULL,
        criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_categoria  (categoria),
        INDEX idx_rgpm       (rgpm),
        INDEX idx_severidade (severidade),
        INDEX idx_criado_em  (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Envia o log para o canal Discord correto via Webhook.
 */
function _enviarLogDiscord(
    string  $categoria,
    string  $acao,
    string  $severidade,
    ?string $rgpm,
    ?string $nome,
    ?string $nivel,
    string  $ip,
    string  $pagina,
    array   $extra
): void {
    // Escolhe a cor do embed pelo nível de severidade
    $cores = [
        SEV_INFO    => 3447003,   // Azul
        SEV_AVISO   => 16776960,  // Amarelo
        SEV_ERRO    => 15158332,  // Vermelho
        SEV_CRITICO => 10038562,  // Roxo escuro
    ];
    $cor = $cores[$severidade] ?? 3447003;

    // Ícone por categoria
    $icones = [
        LOG_AUTH      => '🔐',
        LOG_CADASTRO  => '📋',
        LOG_ADMIN     => '⚙️',
        LOG_INSTRUTOR => '👨‍🏫',
        LOG_ALUNO     => '👤',
        LOG_SISTEMA   => '🖥️',
        LOG_BLACKLIST => '🚫',
        LOG_SEGURANCA => '🚨',
    ];
    $icone = $icones[$categoria] ?? '📌';

    // Nível em texto
    $nivelTexto = match((string)$nivel) {
        '1' => 'Admin',
        '2' => 'Instrutor',
        '3' => 'Aluno',
        default => $nivel ?? 'Sistema'
    };

    // Monta o embed
    $agora  = date('d/m/Y H:i:s');
    $fields = [
        ['name' => '📂 Categoria',  'value' => "`{$categoria}`",       'inline' => true],
        ['name' => '⚠️ Severidade', 'value' => "`{$severidade}`",      'inline' => true],
        ['name' => '📄 Página',     'value' => "`{$pagina}`",          'inline' => true],
        ['name' => '🌐 IP',         'value' => "`{$ip}`",              'inline' => true],
        ['name' => '👤 Usuário',    'value' => $nome  ? "`{$nome}`"  : '`—`', 'inline' => true],
        ['name' => '🪪 RGPM',       'value' => $rgpm  ? "`{$rgpm}`"  : '`—`', 'inline' => true],
        ['name' => '🎭 Nível',      'value' => "`{$nivelTexto}`",     'inline' => true],
    ];

    // Adiciona campos extras relevantes
    if (!empty($extra) && isset($extra['detalhes'])) {
        $fields[] = ['name' => '📝 Detalhes', 'value' => '```' . substr($extra['detalhes'], 0, 900) . '```', 'inline' => false];
    }

    $embed = [
        'title'       => "{$icone} {$acao}",
        'color'       => $cor,
        'fields'      => $fields,
        'footer'      => ['text' => "DEC PMESP · Sistema de Logs · {$agora}"],
        'timestamp'   => date('c'),
    ];

    $payload = json_encode(['embeds' => [$embed]], JSON_UNESCAPED_UNICODE);

    // Escolhe qual webhook usar por categoria
    $webhooks = [];

    // Webhook principal #status (sempre envia tudo)
    $webhooks[] = LOG_DISCORD_WEBHOOK;

    // Canal específico por categoria
    $canalEspecifico = match($categoria) {
        LOG_ALUNO                       => LOG_WEBHOOK_ALUNOS,
        LOG_INSTRUTOR                   => LOG_WEBHOOK_INSTRUTORES,
        LOG_ADMIN, LOG_BLACKLIST        => LOG_WEBHOOK_ADMIN,
        LOG_SISTEMA, LOG_SEGURANCA      => LOG_WEBHOOK_STATUS,
        LOG_AUTH, LOG_CADASTRO          => LOG_WEBHOOK_STATUS,
        default                         => null,
    };

    // Envia também ao canal específico (se diferente do principal)
    if ($canalEspecifico && $canalEspecifico !== LOG_DISCORD_WEBHOOK) {
        $webhooks[] = $canalEspecifico;
    }

    // Envia para cada webhook (sem bloquear a página)
    foreach (array_unique($webhooks) as $url) {
        _webhookPost($url, $payload);
    }
}

/**
 * Faz o POST para o webhook do Discord.
 */
function _webhookPost(string $url, string $payload): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,   // Não trava a página
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
