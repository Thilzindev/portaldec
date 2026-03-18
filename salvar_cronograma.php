<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ✅ NOVO: verificação de autenticação e nível
if (!isset($_SESSION["usuario"])) {
    http_response_code(403);
    exit("Acesso negado.");
}
if (intval($_SESSION["nivel"] ?? 99) > 2) { // apenas admin e instrutor
    http_response_code(403);
    exit("Sem permissão.");
}

include 'conexao.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
