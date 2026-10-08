# Instalação na Hostinger

## Requisitos e disposição dos arquivos

Use PHP 8.2+ com `pdo_mysql`, `openssl` e sessões habilitadas; MySQL 8 ou MariaDB 10.11+ com InnoDB; HTTPS; acesso a PHP CLI para instalar o banco e criar o administrador. Nenhum Composer, Node, cron ou worker é necessário para esta base.

O domínio deve servir exclusivamente `public/`. As pastas `app/`, `bin/`, `tests/`, `.git/` e o `.env` ficam fora da raiz pública.

Se o domínio utilizar uma pasta fixa `public_html/`, coloque `app/`, `bin/` e `.env` no diretório imediatamente acima de `public_html/`, e copie **o conteúdo de public/** para `public_html/`. O caminho relativo de `index.php` continuará encontrando `../app/bootstrap.php`. Não copie a aplicação inteira para `public_html/`.

As regras `.htaccess` são para Apache/LiteSpeed com reescrita habilitada. A regra na raiz privada é defesa adicional; não substitui a separação de pastas. Não instale em subdiretório nesta entrega: os links do painel partem da raiz do domínio.

## Configurar

1. Crie um banco e um usuário de banco dedicado no painel de hospedagem.
2. Envie os arquivos da branch da aplicação para uma instalação de homologação.
3. Copie `.env.example` para `.env`, fora da raiz pública.
4. Preencha `DB_DSN`, `DB_USER` e `DB_PASSWORD`. Exemplo de DSN: `mysql:host=localhost;dbname=NOME_DO_BANCO;charset=utf8mb4`.
5. Execute `php bin/console.php key` e copie a chave para `APP_KEY`. Guarde uma cópia segura dessa chave: ela protege as credenciais das escolas.
6. Mantenha `SESSION_SECURE=1` e use HTTPS.
7. Execute `php bin/console.php migrate`.
8. Execute `php bin/console.php admin:create EMAIL` e informe uma senha de pelo menos 12 caracteres. A senha não deve ser passada na linha de comando.
9. Acesse `/admin`, cadastre disciplinas e assuntos e integre uma escola.
10. Copie o `school_id` e o segredo exibido uma única vez para o servidor do SIGAP dessa escola.

O comando de migração inicial pode ser repetido sem apagar registros. Ele cria as tabelas e os índices da base inicial; futuras alterações de esquema deverão receber migrações próprias.

## Validar a instalação

- `/admin/entrar` deve abrir por HTTPS e permitir login.
- Uma chamada à API sem token deve retornar HTTP 401.
- `.env` e `app/` não devem ser acessíveis por URL.
- Faça um cadastro de teste pelo SIGAP, publique e responda como aluno de teste.
- Confirme que a tentativa foi salva e a edição passou a retornar `content_locked`.
- Confirme que a sessão do PHP está sendo gravada e que os registros de erro ficam fora da raiz pública.

## Operação

Mantenha backups do banco e da `APP_KEY`. Não envie `.env`, banco local, credenciais ou dados de alunos ao GitHub. A aplicação não possui dados ou senhas padrão. As senhas dos administradores são armazenadas com `password_hash`; os segredos das escolas são criptografados com AES-256-GCM.

Para suspender uma integração, desative a escola no painel. Isso bloqueia novas chamadas assinadas por ela, sem apagar autoria ou histórico. Nesta entrega, a rotação de um segredo exige uma operação administrativa assistida no banco, preservando o mesmo `school_id`; a interface de rotação será acrescentada posteriormente.

Nenhuma publicação automática na Hostinger é configurada por esta entrega. Não coloque o diretório `.git` na raiz pública nem o inclua no pacote de publicação.
