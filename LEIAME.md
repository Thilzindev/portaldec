# Portal DEC PMESP — Versão 2.0 Corrigida

## 🚀 Configuração Inicial

### 1. Configure o arquivo `.env`
Abra o arquivo `.env` e preencha com suas credenciais:

```
DB_HOST=seu_host_mysql
DB_USER=seu_usuario
DB_PASS=sua_senha
DB_NAME=seu_banco
DB_PORT=3306
DB_CHARSET=utf8mb4
DISCORD_BOT_TOKEN=seu_token_do_bot
```

### 2. Importe o banco de dados
Importe o arquivo `ezyro_41335663_DEC_DB.sql` no seu MySQL/MariaDB.

### 3. Permissões de pastas
As pastas abaixo precisam ter permissão de escrita (755 ou 775):
```
chmod 755 cronogramas/
chmod 755 atas_pdfs/
```

### 4. Renomeie o .htaccess
O arquivo `.htaccess` já está pronto. Certifique-se de que o mod_rewrite está ativo no Apache.

---

## 🔒 Melhorias de Segurança Aplicadas

| Item | Status |
|------|--------|
| Credenciais no `.env` (fora do código) | ✅ |
| `display_errors = 0` em produção | ✅ |
| 100% Prepared Statements (sem SQL injection) | ✅ |
| CSRF Token em formulários admin | ✅ |
| Rate limiting no login (5 tentativas → bloqueio 60s) | ✅ |
| `session_regenerate_id()` no login | ✅ |
| `password_hash(PASSWORD_BCRYPT)` nas senhas | ✅ |
| `.htaccess` bloqueando acesso a `.env`, `.sql` | ✅ |
| Headers de segurança (X-Frame-Options, X-XSS-Protection) | ✅ |
| Token Discord no `.env` (não hardcoded) | ✅ |
| Tabela `blacklist` com ENGINE=InnoDB corrigida (no SQL) | ✅ |

---

## 📁 Estrutura do Projeto

```
portal/
├── .env                  ← Credenciais (NÃO commitar no Git!)
├── .htaccess             ← Segurança e redirects
├── conexao.php           ← Conexão segura ao banco
├── index.php             ← Página pública
├── login.php             ← Autenticação
├── cadastro.php          ← Registro
├── logout.php            ← Encerramento de sessão
├── painel.php            ← Roteador de painéis
├── painel_admin.php      ← Painel do Administrador
├── painel_instrutor.php  ← Painel do Instrutor
├── painel_aluno.php      ← Painel do Aluno
├── api_cursos.php        ← API de polling de cursos
├── esqueci_senha.php     ← Recuperação de senha
├── verificar_codigo.php  ← Verificação do código Discord
├── nova_senha.php        ← Redefinição de senha
├── fpdf/                 ← Biblioteca de geração de PDF
├── cronogramas/          ← PDFs de cronogramas
└── atas_pdfs/            ← PDFs de atas
```

---

## 👥 Níveis de Acesso

| Nível | Perfil | Painel |
|-------|--------|--------|
| 1 | Administrador | painel_admin.php |
| 2 | Instrutor | painel_instrutor.php |
| 3 | Aluno | painel_aluno.php |

