<?php
session_start();
mb_internal_encoding('UTF-8');

// ✅ Verificação de autenticação — qualquer nível autenticado pode acessar
if (!isset($_SESSION["usuario"])) {
    http_response_code(403);
    exit("Acesso negado.");
}

include 'conexao.php';

// ── DOWNLOAD (GET) — qualquer nível autenticado pode baixar ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id    = intval($_GET['id'] ?? 0);
    $pasta = trim($_GET['pasta'] ?? '');
    if (!$id) { http_response_code(400); exit("ID inválido."); }

    if ($pasta === 'presenca_arquivos') {
        $stmt = $conexao->prepare("SELECT titulo, arquivo, IFNULL(mime_type,'application/octet-stream') as mime_type FROM presenca_arquivos WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || empty($row['arquivo'])) { http_response_code(404); exit("Arquivo não encontrado."); }

        $ext = extensaoPorMime($row['mime_type']);
        $nomeArquivo = 'Presenca_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['titulo']) . $ext;
        header('Content-Type: ' . $row['mime_type']);
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($row['arquivo']));
        echo $row['arquivo'];
        exit;
    }

    // Download de cronograma — tenta buscar mime_type se a coluna existir
    $colCheck = $conexao->query("SHOW COLUMNS FROM cronogramas LIKE 'mime_type'");
    $temMime  = $colCheck && $colCheck->num_rows > 0;
    $selectMime = $temMime ? ", IFNULL(mime_type,'application/octet-stream') as mime_type" : "";

    $stmt = $conexao->prepare("SELECT titulo, arquivo_pdf{$selectMime} FROM cronogramas WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['arquivo_pdf'])) { http_response_code(404); exit("Arquivo não encontrado."); }

    // Detecta mime real do conteúdo binário via finfo
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->buffer($row['arquivo_pdf']);
    if (!$mimeReal) $mimeReal = $row['mime_type'] ?? 'application/octet-stream';

    $ext         = extensaoPorMime($mimeReal);
    $nomeArquivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['titulo']) . $ext;
    header('Content-Type: ' . $mimeReal);
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . strlen($row['arquivo_pdf']));
    echo $row['arquivo_pdf'];
    exit;
}

function extensaoPorMime($mime) {
    $map = [
        'application/pdf' => '.pdf',
        'image/jpeg'      => '.jpg',
        'image/png'       => '.png',
        'image/gif'       => '.gif',
        'image/webp'      => '.webp',
    ];
    return $map[$mime] ?? '.bin';
}

// ── UPLOAD (POST) — apenas admin (nível 1) e instrutor (nível 2) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (intval($_SESSION["nivel"] ?? 99) > 2) {
        http_response_code(403);
        exit("Sem permissão para enviar arquivos.");
    }
    $titulo = trim($_POST['titulo'] ?? '');

    if (empty($titulo)) {
        exit("Erro: título vazio.");
    }

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        exit("Erro no upload: " . ($_FILES['arquivo']['error'] ?? 'Arquivo não enviado'));
    }

    $arquivo = $_FILES['arquivo'];

    // ✅ NOVO: valida tipo do arquivo (só PDF, PNG, JPG, GIF)
    $tiposPermitidos = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($arquivo['tmp_name']);
    if (!in_array($mimeReal, $tiposPermitidos)) {
        exit("Erro: tipo de arquivo não permitido.");
    }

    // ✅ NOVO: limita tamanho a 10MB
    if ($arquivo['size'] > 10 * 1024 * 1024) {
        exit("Erro: arquivo muito grande (máx. 10MB).");
    }

    $conteudoArquivo = file_get_contents($arquivo['tmp_name']);

    $stmt = $conexao->prepare("INSERT INTO cronogramas (titulo, arquivo_pdf, data_envio) VALUES (?, ?, NOW())");
    $null = NULL;
    $stmt->bind_param("sb", $titulo, $null);
    $stmt->send_long_data(1, $conteudoArquivo);

    if ($stmt->execute()) {
        echo "sucesso";
    } else {
        echo "Erro no banco: " . $conexao->error;
    }
    $stmt->close();
}