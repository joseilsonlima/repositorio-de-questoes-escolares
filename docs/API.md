# API v1 — integração com SIGAP

A API usa JSON UTF-8 e prefixo `/api/v1`. As chamadas devem sair do **servidor do SIGAP**, que autentica o usuário e não aceita perfil/identidade arbitrários do navegador. Não exponha segredo ou JWT da integração ao aluno ou professor. A API não habilita CORS.

## Autenticação

Após cadastrar uma escola no painel central, configure `school_id` e `secret` no servidor dessa escola. Emita JWT HS256 com o segredo e os seguintes claims:

```json
{
  "iss": "sigap",
  "aud": "repositorio-escolar",
  "school_id": 1,
  "sub": "identificador-estavel-do-usuario-no-sigap",
  "role": "professor",
  "name": "Nome para autoria",
  "iat": 1700000000,
  "exp": 1700000300
}
```

Os timestamps acima são ilustrativos: use o horário atual em segundos UTC. Validade máxima: 300 segundos. Perfis: `professor` ou `aluno`. `school_id` é inteiro; `sub` tem de 1 a 128 caracteres dentre letras ASCII, números, `@`, `.`, `_`, `:`, `-`. O identificador deve continuar igual enquanto a pessoa permanecer na integração. A combinação escola + `sub` identifica o usuário. A mudança de perfil de uma identidade já cadastrada é recusada.

Envie `Authorization: Bearer TOKEN`. Para POST/PATCH, envie também `Content-Type: application/json`. Objetos JSON são obrigatórios; payload máximo: 200 KB. Conteúdo textual é texto simples, sem interpretação de HTML.

## Rotas

| Método | Rota | Perfil / regra |
|---|---|---|
| GET | `/disciplinas` | Professor e aluno |
| GET | `/assuntos?discipline_id=1` | Professor e aluno; disciplina obrigatória |
| GET | `/questoes` | Professor e aluno; publicadas |
| GET | `/questoes?mine=1` | Professor; próprias em todas as situações |
| GET | `/questoes/{id}` | Professor: qualquer situação, exceto rascunho de outro autor; aluno: publicada sem gabarito |
| POST | `/questoes` | Professor; cria rascunho |
| PATCH | `/questoes/{id}` | Professor autor; conteúdo não bloqueado |
| POST | `/questoes/{id}/situacao` | Professor autor; publicar, arquivar ou anular |
| GET | `/textos?search=trecho` | Professor; textos compartilhados |
| POST | `/textos` | Professor; cadastra texto próprio |
| PATCH | `/textos/{id}` | Professor cadastrante; texto não bloqueado |
| POST | `/resolucoes` | Aluno; registra uma tentativa |
| GET | `/resolucoes` | Aluno; seu próprio histórico |

Listas de questões, textos e resoluções usam `page`, iniciado em 1, com 20 registros por página. Questões incluem `total`. Filtros de questões: `discipline_id`, `subject_id`, `text_id`, `search` (enunciado) e `mine=1`. Sem `mine`, a busca considera somente publicadas. Não há exclusão, clone ou transferência de autoria.

## Texto associado

```json
{
  "title": "Uma viagem pelo bairro",
  "content": "Conteúdo completo do texto…",
  "source": "Fonte bibliográfica ou produção própria",
  "work_author": "Autor da obra"
}
```

Título e conteúdo são obrigatórios. `source` e `work_author` são opcionais. A resposta contém `id` para relacionar novas questões ao mesmo registro. `author_id` identifica quem cadastrou, não necessariamente o autor da obra.

## Criar e publicar questão

```json
{
  "discipline_id": 1,
  "subject_id": 1,
  "text_id": null,
  "statement": "Quanto é 2 + 3?",
  "alternatives": ["4", "5", "6", "7"],
  "correct_option": "B"
}
```

`alternatives` é uma lista de textos; o servidor atribui A, B, C, D e E pela ordem. A consulta retorna um objeto de alternativas por letra. Gabarito deve apontar a uma alternativa cadastrada. Rascunho pode ser incompleto (`{}` é aceito); publicação exige disciplina, assunto da disciplina, enunciado, de duas a cinco alternativas distintas não vazias e gabarito.

POST retorna HTTP 201 com `id` e `status: rascunho`. Para publicar, envie ao endpoint de situação:

```json
{"status": "publicada"}
```

Para editar, PATCH envia somente os campos alterados. Se enviar `alternatives`, a lista inteira será substituída. `text_id: null` remove o vínculo antes do bloqueio. Questão publicada deve continuar completa após a edição. A API define autoria pela identidade autenticada e recusa `author_id` enviado no corpo.

Para anular:

```json
{"status": "anulada", "reason": "Motivo da anulação"}
```

A anulação é definitiva nesta base. `arquivada` retira a questão das novas consultas; `publicada` permite republicar uma arquivada válida. Nenhuma mudança de situação desbloqueia conteúdo já respondido.

## Responder

O SIGAP consulta a questão imediatamente antes de apresentá-la e mantém o `content_hash` retornado. O gabarito não acompanha a consulta feita com perfil de aluno. Envie:

```json
{
  "question_id": 12,
  "selected_option": "B",
  "content_hash": "HASH_SHA256_RECEBIDO_NA_CONSULTA",
  "attempt_id": "uuid-da-tentativa",
  "activity_id": "identificador-da-prova-no-sigap"
}
```

`activity_id` é opcional. `attempt_id` é obrigatório, até 128 caracteres, único para cada tentativa do aluno na escola. Guarde-o antes de enviar e reutilize-o quando repetir a requisição por falha de conexão. Novas tentativas usam novos IDs. A alternativa refere-se à letra central, mesmo que o SIGAP altere a ordem visual das opções.

HTTP 201 indica nova resolução. HTTP 200 com `replayed: true` indica reenvio idempotente. A resposta inclui resolução, alternativa selecionada, `correct` (0 ou 1), horário UTC, situação atual da questão e motivo/data da anulação quando houver. Não envie `correct` ou gabarito: a API calcula o resultado.

A primeira resolução válida bloqueia conteúdo da questão e do texto associado. Todas as questões relacionadas a esse texto passam a preservar o mesmo texto; questões ainda não respondidas continuam com seus próprios campos editáveis.

Se receber `content_changed`, recarregue a questão, informe a mudança ao aluno e solicite nova escolha antes de enviar novamente. A tentativa rejeitada não cria histórico nem bloqueia conteúdo. Não recalcule ou envie automaticamente a escolha antiga.

Em avaliações, o servidor SIGAP recebe o resultado, mas pode adiar sua exibição ao aluno. As notas da prova e os ajustes decorrentes de anulação são responsabilidade do SIGAP. Para detectar anulações nesta base, consulte o histórico individual ou as questões por ID com perfil de professor; notificações e sincronização de atividades completas são uma etapa posterior.

## Erros

```json
{"error":{"code":"content_locked","message":"Questão já respondida por aluno. Cadastre uma nova questão para corrigir o conteúdo."}}
```

| HTTP | Código comum | Tratamento |
|---|---|---|
| 400 | `invalid_json` | Corrigir o JSON |
| 401 | `unauthenticated` | Verificar assinatura, relógio, expiração e escola ativa |
| 403 | `not_owner`, `forbidden` | Operação não autorizada |
| 404 | `not_found` | Questão ausente ou indisponível ao usuário |
| 409 | `content_locked` | Cadastrar nova questão para corrigir conteúdo |
| 409 | `content_changed` | Recarregar e solicitar nova resposta |
| 409 | `attempt_conflict` | ID da tentativa já utilizado com outros dados |
| 409 | `concurrent_change` | Reconsultar e repetir a operação |
| 409 | `unavailable`, `annulled` | Questão indisponível ou anulação definitiva |
| 413 / 415 | `payload_too_large`, `json_required` | Corrigir tamanho ou Content-Type |
| 422 | `invalid_subject`, `incomplete_question`, outros | Corrigir campos |
| 500 | `internal_error` | Registrar a referência do incidente e tentar novamente com o mesmo attempt_id |
