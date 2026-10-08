# Repositório de Questões Escolares

Aplicação central em PHP, integrada ao SIGAP por API. O painel central é exclusivo dos administradores; professores e alunos continuam autenticando no sistema de suas escolas.

## Primeira entrega

- API autenticada por escola e usuário, com JWT HS256 de até cinco minutos.
- Cadastro de questões próprias, consulta compartilhada e edição restrita ao autor.
- Questões com disciplina, assunto, enunciado, texto associado opcional, de duas a cinco alternativas e gabarito único.
- Textos em tabela própria, compartilhados por referência e pesquisáveis por professores.
- Rascunho, publicação, arquivamento e anulação com motivo e auditoria.
- Bloqueio permanente de questão e texto associado na **primeira resolução válida de aluno**.
- Histórico individual, correção no servidor e reenvio idempotente de respostas.
- Detecção de conteúdo alterado entre consulta e resposta, sem versões de questões.
- Painel de administração com escolas, geração de credenciais, disciplinas, assuntos, consulta de questões e auditoria.

## Regras de conteúdo

A consulta e a inclusão em provas ou simulados não bloqueiam a questão. O SIGAP deve manter os identificadores centrais e consultar o conteúdo atualizado. Depois da primeira resolução, enunciado, classificação, alternativas, gabarito e vínculo com texto não podem ser alterados, inclusive por administrador. Correções exigem outro cadastro. A anulação preserva o conteúdo, as respostas e seus resultados originais.

Professores podem usar questões de qualquer escola para montar atividades, sem modificar a autoria. Não há operação de copiar/adaptar questões ou textos de outro autor. Textos existentes podem ser associados a novas questões próprias. O bloqueio de um texto não impede novos vínculos.

## Executar

Requisitos: PHP 8.2 ou superior, PDO MySQL e OpenSSL. SQLite é usado apenas em testes/desenvolvimento. Banco de produção: MySQL 8 ou MariaDB 10.11, com InnoDB.

```sh
cp .env.example .env
php bin/console.php key
# Preencher APP_KEY e os dados do banco no .env.
php bin/console.php migrate
php bin/console.php admin:create seu-email@example.com
```

O comando do administrador solicita a senha sem exibi-la. Não existe senha padrão. Sirva **somente a pasta public/**. Para desenvolvimento local, configure `SESSION_SECURE=0` e execute:

```sh
php -S 127.0.0.1:8080 -t public public/router.php
```

Acesse `/admin`. Em produção, mantenha HTTPS e `SESSION_SECURE=1`.

## Documentação

- [Instalação na Hostinger](docs/INSTALACAO.md)
- [Contrato da API e integração com SIGAP](docs/API.md)
- [OpenAPI](docs/openapi.json)
- [Modelo e decisões](docs/ARQUITETURA.md)

## Validação

```sh
php tests/run.php
python3 tests/integration_http.py
```

A suíte principal executa 45 verificações de regras de negócio. Os testes HTTP executam 22 verificações com servidor real, sessão administrativa e SQLite temporário. Para validar SQL e concorrência, use **um banco descartável vazio**:

```sh
TEST_DB_DSN='mysql:host=127.0.0.1;dbname=repository_test;charset=utf8mb4' \
TEST_DB_USER=root TEST_DB_PASSWORD='senha-do-banco-de-teste' php tests/run.php

TEST_DB_DSN='mysql:host=127.0.0.1;dbname=repository_test;charset=utf8mb4' \
TEST_DB_USER=root TEST_DB_PASSWORD='senha-do-banco-de-teste' php tests/concurrency.php
```

Há um workflow de GitHub Actions para PHP 8.2, SQLite e MySQL 8. Os testes não devem ser executados no banco de produção.

## Escopo desta entrega

A aplicação ainda precisa ser instalada na hospedagem e conectada ao SIGAP. As telas de criação de questões, montagem de provas/simulados e apresentação ao aluno pertencem ao SIGAP e não são duplicadas no painel central.

Nesta base, identidade/autoria é estável dentro de cada integração, pela combinação escola + identificador externo. Unificar a mesma pessoa entre integrações distintas exige um vínculo de identidade verificado; não é feito por nome ou e-mail. Histórico de turmas para professores, notificações automáticas de anulação, imagens, fórmulas renderizadas e classificação por ano/série ficam para próximas etapas. O conteúdo atual é texto simples UTF-8.
