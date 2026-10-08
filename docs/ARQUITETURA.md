# Modelo e decisões

## Responsabilidades

O repositório central mantém conteúdo, autoria, situações, resoluções e auditoria. O SIGAP mantém login escolar, vínculos com turmas, provas, simulados, agenda de aplicação e cálculo das notas das atividades. Administradores acessam o painel central; coordenadores não recebem um papel na API central.

Cada escola possui uma credencial própria. Seu servidor emite tokens assinados para usuários já autenticados e chama a API. A integração é uma autoridade confiável para as identidades da própria escola. Nomes/e-mails não são usados para unir identidades de escolas diferentes. A assinatura não deve ser delegada ao navegador.

## Banco

| Tabela | Conteúdo |
|---|---|
| schools | Escola, situação e segredo criptografado |
| users | Identificador externo, escola, perfil e nome |
| admins | Contas exclusivas do painel central |
| disciplines / subjects | Catálogo central; assunto pertence a uma disciplina |
| associated_texts | Texto, cadastrante, autor da obra, fonte e bloqueio |
| questions | Conteúdo, autor, escola de origem, classificação, vínculo opcional com texto e situação |
| alternatives | De zero a cinco alternativas em rascunho; de duas a cinco na publicação |
| resolutions | Cada tentativa, atividade externa, alternativa, resultado original, hash e data |
| audit_log | Ações sobre conteúdo, situação, escolas e catálogo |
| login_limits | Limitação temporária de tentativas de login administrativo |

Uma questão tem zero ou um texto; um texto pode ser relacionado a várias questões. Não há tabela de versões, cópias ou snapshots de questão. O hash SHA-256 detecta mudanças do conteúdo corrente e não identifica uma versão armazenada. Exclusão física de questão, texto ou resolução não é exposta na API.

## Bloqueio e concorrência

- Consulta não bloqueia. Prova/simulado no SIGAP usa o identificador central e consulta o conteúdo corrente.
- A primeira **resolução válida salva** bloqueia a questão e seu texto; não basta abrir a questão ou enviar uma tentativa inválida.
- A verificação do hash, a gravação da tentativa e os bloqueios são feitos na mesma transação.
- Edição e resolução travam textos em ordem crescente, depois a questão. O registro de aluno é travado antes de conferir reenvios.
- Leituras críticas do conteúdo usam locks atuais no MySQL, evitando aceitar texto antigo por snapshot enquanto outra conexão termina uma edição.
- Mudança entre consulta e resposta retorna HTTP 409 `content_changed`. O SIGAP deve recarregar, informar o aluno e solicitar nova escolha.
- Uma tentativa repetida com os mesmos dados retorna a resolução anterior; o mesmo identificador com dados diferentes é recusado.
- Arquivar/republicar não remove `locked_at`. Administrador não possui uma rota para modificar conteúdo bloqueado.

Anulada é uma situação final nesta entrega. Anular exige motivo, registra data/responsável e preserva respostas e resultados originais. O SIGAP escolhe a política de pontuação. O histórico e a consulta por ID de professor informam a situação atual; busca compartilhada exclui anuladas e arquivadas.

## Compartilhamento e privacidade

Questões publicadas são consultáveis por usuários autenticados das escolas. Textos são pesquisáveis por professores; alunos recebem o texto pelo vínculo da questão. Professores editam apenas sua própria produção. Não há operação de clonar questão/texto de outro autor. Um texto pode ser editado pelo cadastrante até alguma questão que o utiliza receber uma resolução.

O histórico retornado pela API é apenas do próprio aluno autenticado. Não há endpoint de histórico geral por escola ou turma nesta base. Para acrescentá-lo, será necessário validar o vínculo do professor com a turma no SIGAP. A consulta de aluno não contém gabarito; os resultados das tentativas voltam ao servidor SIGAP, que controla sua divulgação na interface e a política de avaliação.

## Próximas entregas

- Integração efetiva e telas no SIGAP.
- Vínculo verificado de uma pessoa entre escolas, se necessário.
- Histórico de turma com autorização específica.
- Consulta de anulações por atividade e notificações entre servidores.
- Rotação de credenciais no painel.
- Imagens e fórmulas, metadados de etapa/ano e filtros adicionais.
- Paginação/consulta de auditoria mais extensa e monitoramento operacional.
