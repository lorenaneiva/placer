# PLACE — Especificação unificada e instruções de implementação

**Versão:** 1.0 — 07/11/2026  
**Destino:** agente de programação no PhpStorm (JetBrains AI Assistant/Junie ou equivalente)  
**Objetivo:** implementar um MVP completo, testável e utilizável de agendamento de espaços em **Laravel + Blade**, sem Vue e sem Vuetify.

> **INSTRUÇÃO PRINCIPAL AO AGENTE:** Leia integralmente este arquivo e inspecione o código existente antes de alterar qualquer coisa. Implemente o MVP em etapas, sem substituir configurações funcionais nem destruir dados. Esta especificação consolida o levantamento de requisitos, a modelagem lógica e o fluxograma do cliente. As decisões que não estavam explícitas nos materiais originais estão identificadas como **[DECISÃO MVP]** para não serem confundidas com requisitos já aprovados. Implemente essas decisões como padrão, salvo incompatibilidade concreta com o repositório; nesse caso, relate a incompatibilidade.

---

## 1. Contexto e objetivo do produto

O PLACE é um sistema web para clientes consultarem e reservarem espaços como quadras de vôlei, futebol, piscinas, tatames e salões de festa. O objetivo é substituir controles manuais por WhatsApp, telefone e agendas, centralizar as informações e impedir conflitos de horário. Um administrador pode gerenciar categorias, espaços, reservas e indisponibilidades.

A **primeira versão (MVP)** deve incluir: cadastro e login; catálogo de categorias e espaços; consulta de horários; criação de reserva com prevenção real de conflitos; minhas reservas e cancelamento; administração de categorias, espaços, reservas, horários bloqueados e antecedência máxima. Funcionalidades futuras (notificações, avaliações, relatórios avançados, recorrência e cobrança on-line) **não** fazem parte do MVP.

### 1.1 Tecnologias definitivas

- Backend e interface: **Laravel + Blade**. Renderização no servidor; formulários e links tradicionais, sem SPA.
- Estilos: **Tailwind CSS**, podendo usar **Play CDN apenas durante prototipação/desenvolvimento**. Se o projeto já usar Vite/Tailwind compilado, manter. Para publicação definitiva, preferir build local com Vite, evitando depender do Play CDN em produção.
- Interações pontuais: JavaScript simples quando indispensável, sem Vue, Vuetify, React ou biblioteca SPA.
- Banco de dados: **preservar o banco já configurado no repositório**. **[ADEQUAÇÃO AO AMBIENTE ATUAL]** O projeto `placer` já foi utilizado com Laravel Sail e serviço `pgsql` (PostgreSQL); portanto, **se esta configuração estiver presente, usar PostgreSQL**. O texto de requisitos original menciona MySQL, mas **não migrar o banco nem recriar ambiente sem necessidade**. Caso o repositório seja outro ou use MySQL, adaptar as migrations/testes ao banco efetivo e informar a divergência.
- Ambiente: respeitar o Docker/Laravel Sail existente, se houver. Não recriar containers, volumes, projeto Laravel ou banco arbitrariamente.
- Idioma da interface: português brasileiro. Layout responsivo para celular e desktop.
- Git: mudanças pequenas, rastreáveis; não apagar nem sobrescrever trabalho existente. Não executar `migrate:fresh`, `db:wipe`, `docker compose down -v` ou comandos destrutivos sem autorização expressa.

### 1.2 Diretrizes gerais

- Preferir recursos nativos do Laravel: Eloquent, migrations, Form Requests, policies/gates ou middleware, Blade, session, CSRF, validação, hashing de senhas, rate limiting e testes.
- Não criar API REST apenas porque ela aparecia no fluxograma. **[DECISÃO MVP]** Como a interface agora é Blade, priorizar **rotas web com formulários, redirects e mensagens flash**. API JSON pode ficar para evolução futura; não é necessária para o MVP.
- Reutilizar estrutura, rotas, auth e componentes existentes antes de adicionar novos pacotes.

---

## 2. Perfis e permissões

### Cliente

Pode: criar conta, autenticar-se, ver categorias/espaços ativos, consultar disponibilidade, criar reservas próprias, listar suas reservas e cancelar reservas próprias conforme regras abaixo.

Não pode: acessar administração, consultar dados privados de outros usuários, mudar manualmente preço/status das reservas, cadastrar ou editar categorias e espaços ou criar bloqueios.

### Administrador

Pode: autenticar-se; cadastrar/editar/ativar/desativar categorias; cadastrar/editar/ativar/desativar espaços; configurar antecedência máxima por categoria; listar reservas de todos os clientes; cancelar ou alterar reservas; bloquear e desbloquear horários, respeitando integridade e trilha mínima de operações.

**[DECISÃO MVP]** Usar `users.is_admin` boolean, padrão `false`. **Nunca aceitar `is_admin` em cadastro público ou atualização de perfil do cliente**. O primeiro administrador deve ser criado por seeder/command documentado e seguro, sem senha fixa publicada no código. Todas as rotas administrativas exigem `auth` e verificação de administrador no servidor.

---

## 3. Modelo de dados

A modelagem original contém quatro entidades (`Usuario`, `Categoria`, `Espaco`, `Reserva`). A implementação deve manter as quatro e acrescentar a entidade **BloqueioHorario**, ausente do diagrama, mas necessária para RF12/RN08.

### 3.1 `users` (Usuário)

- `id` PK;
- `name` string obrigatório;
- `email` string único, obrigatório, normalizado;
- `phone` string obrigatório **[DECISÃO MVP]**; validar formato razoável, não usar como autenticação;
- `password` string armazenada **somente como hash**;
- `is_admin` boolean default false;
- `created_at`, `updated_at`.

Se a tabela `users` já existir, **criar migration incremental**, sem renomear a tabela ou perder dados. Não criar segunda tabela de usuários.

### 3.2 `categories` (Categoria)

- `id` PK;
- `name` string obrigatório, único **[DECISÃO MVP]**;
- `is_active` boolean default true;
- `max_advance_days` unsigned integer obrigatório (ex.: 21 dias); configuração **por categoria**, como no modelo original;
- `created_at`, `updated_at`.

Validação: `max_advance_days >= 0` e limitar a valor razoável (por exemplo, até 365) **[DECISÃO MVP]**. Antecedência máxima significa que o **início** da reserva não pode ultrapassar o momento atual + N dias.

### 3.3 `spaces` (Espaço)

- `id` PK;
- `category_id` FK obrigatória para `categories.id`;
- `identifier` string obrigatória, ex.: `001` (guardar como **string**, preservando zeros à esquerda);
- `description` text nullable;
- `hourly_price` decimal(10,2) obrigatório, >= 0;
- `capacity` integer obrigatório, > 0;
- `is_active` boolean default true;
- `created_at`, `updated_at`.

Exibição do título: concatenar nome da categoria + espaço + identificador (`Quadra 001`, `Salão de festa 001`), **sem campo de título editável**. Unicidade composta de `category_id + identifier`. **[DECISÃO MVP]** Não permitir cadastro de novos espaços em categoria inativa; espaço desativado ou pertencente a categoria inativa não aceita novas reservas. Desativar não apaga histórico.

### 3.4 `reservations` (Reserva)

- `id` PK;
- `user_id` FK obrigatória para `users.id`;
- `space_id` FK obrigatória para `spaces.id`;
- `starts_at` datetime/timestamp obrigatório;
- `ends_at` datetime/timestamp obrigatório;
- `total_price` decimal(10,2), valor congelado no momento da reserva;
- `status` string com estados controlados: `confirmed` e `cancelled` **[DECISÃO MVP]**;
- `cancelled_at` nullable;
- `created_at`, `updated_at`.

Regras: `ends_at > starts_at`; preço é calculado **no servidor** e não confiado ao navegador; cancelamento muda status, grava `cancelled_at` e **não exclui o registro**. Reservas canceladas não ocupam horários.

### 3.5 `schedule_blocks` (Bloqueio de horário) — entidade adicionada

- `id` PK;
- `space_id` FK obrigatória para `spaces.id`;
- `starts_at`, `ends_at` obrigatórios;
- `reason` string/text nullable (ex.: manutenção, evento);
- `created_by` FK nullable para `users.id` (administrador);
- `created_at`, `updated_at`.

Bloqueios ocupam intervalos da mesma forma que reservas confirmadas. Administrador pode listar, cadastrar e remover bloqueios. **[DECISÃO MVP]** Não permitir novo bloqueio sobre reserva confirmada, nem reserva sobre bloqueio. Em vez de apagar um bloqueio sem querer, exigir confirmação na interface. Guardar identificador de quem o criou.

### 3.6 Integridade e índices

- FKs reais e índices para consultas por `space_id, starts_at, ends_at, status`, `user_id, starts_at`, e blocos por `space_id, starts_at, ends_at`.
- Não permitir exclusão física de usuários/espaços/categorias que quebre histórico; preferir desativação e integridade referencial.
- Nomes de tabelas/colunas podem ser adaptados à estrutura existente, **mantendo o significado** e consistência de ponta a ponta.
- Não usar `float` para preços. Armazenar decimal e formatar em BRL (`R$`) na UI.

---

## 4. Definição objetiva de horários e preços

A documentação original não fixa duração dos horários nem horário de funcionamento. Para evitar implementações incompatíveis, adotar:

1. **[DECISÃO MVP]** Reservas em **slots fixos de 60 minutos**, com início sempre em hora cheia; **uma reserva = um slot**. Ex.: 14:00–15:00. Reservas de várias horas são evolução futura, não presumir.
2. **[DECISÃO MVP]** Funcionamento inicialmente **08:00–22:00 todos os dias**, no fuso `America/Belem`; último slot inicia às 21:00 e termina às 22:00. Centralizar esses parâmetros em configuração de aplicação, **não espalhar números mágicos** no código. Ajuste via painel pode ser futuro; no MVP, basta configuração centralizada.
3. **[DECISÃO MVP]** Não permitir reserva retroativa. O slot deve começar no futuro. A validação de antecedência usa o `max_advance_days` da categoria do espaço; `0` significa apenas horários ainda futuros no dia atual.
4. **[DECISÃO MVP]** O preço de um slot é igual a `spaces.hourly_price`; calcular e congelar em `reservations.total_price`. Nenhum pagamento/checkout será implementado.
5. A UI de disponibilidade deve mostrar horários livres e ocupados de forma clara; **só os livres podem ser selecionados**. A verificação visual não substitui a verificação do servidor na criação.
6. **[DECISÃO MVP]** Mudança de preço afeta somente novas reservas; não recalcular valores antigos.
7. **[DECISÃO MVP]** Cliente pode cancelar somente reserva própria `confirmed` cujo início ainda esteja no futuro. Administrador pode cancelar reserva confirmada mesmo após início, com confirmação explícita. Cancelamentos liberam o horário imediatamente para novas reservas válidas.

### 4.1 Detecção de sobreposição (obrigatória)

Tratar intervalos como **[início, fim)**, ou seja, fim exclusivo. Há conflito quando:

`existing.starts_at < requested.ends_at AND existing.ends_at > requested.starts_at`.

Isso permite slots adjacentes: 14:00–15:00 e 15:00–16:00 não conflitam. Consultar **somente reservas com status `confirmed`** e todos os bloqueios ativos do espaço.

### 4.2 Concorrência: prevenção real de reserva duplicada

**Requisito crítico:** duas requisições simultâneas para o mesmo espaço/horário não podem ambas confirmar. Não basta um `exists()` antes de inserir e não basta desabilitar o botão no frontend.

- Executar criação de reserva dentro de **transação no banco**.
- **Bloquear a linha do espaço (`SELECT ... FOR UPDATE` / Eloquent `lockForUpdate`) no início da transação**, depois reconsultar os conflitos de reservas/bloqueios e só então inserir. Isso serializa a tentativa por espaço em bancos transacionais compatíveis, incluindo PostgreSQL/MySQL InnoDB.
- Para inserção de bloqueios e **edição/reagendamento de reservas**, usar **a mesma convenção de lock de espaço** antes de checar conflito. Se uma operação envolver dois espaços, obter locks em ordem estável pelo ID para reduzir deadlocks.
- Fazer todas as validações decisivas **novamente dentro da transação**, inclusive disponibilidade, intervalo, ativações e antecedência. Tratamento gracioso de erro de concorrência/deadlock com retry limitado quando apropriado.
- Opcionalmente, no PostgreSQL, avaliar constraint de exclusão de intervalos para proteção adicional, mas **não exigir extensão/migração arriscada sem verificar o banco**. O bloqueio transacional é o mínimo obrigatório do MVP.
- Se horário foi ocupado no intervalo entre consulta e clique, **não criar reserva**, retornar mensagem clara de conflito e opção de escolher novo horário.

---

## 5. Regras de negócio consolidadas (RN)

**RN01** Um espaço não pode ter duas reservas `confirmed` sobrepostas.  
**RN02** Usuário só pode selecionar horário disponível.  
**RN03** Ao cancelar reserva, horário volta a ficar disponível, desde que não haja bloqueio.  
**RN04** Só administrador cadastra/edita categorias e espaços.  
**RN05** Cliente precisa estar logado para reservar.  
**RN06** Início não pode ultrapassar antecedência máxima configurada na categoria.  
**RN07** Espaço desativado (ou sua categoria desativada) não aceita novas reservas.  
**RN08** Intervalos bloqueados pelo administrador não aceitam reservas.  
**RN09** Título do espaço é calculado da categoria + identificador, sem campo editável.  
**RN10 [DECISÃO MVP]** Reservas de 60 minutos, slots cheios, entre 08:00 e 22:00.  
**RN11 [DECISÃO MVP]** Não criar reserva no passado; fuso de apresentação `America/Belem`.  
**RN12 [DECISÃO MVP]** Preço calculado no servidor e congelado em `total_price`.  
**RN13 [DECISÃO MVP]** Cliente só cancela sua reserva futura; admin pode cancelar qualquer reserva.  
**RN14 [DECISÃO MVP]** Bloqueios e edições de reserva seguem mesma proteção contra concorrência.  
**RN15 [DECISÃO MVP]** Dados de terceiros e rotas administrativas protegidos no servidor.  
**RN16 [DECISÃO MVP]** A desativação impede novas reservas, mas preserva reservas já confirmadas e histórico; a UI administrativa deve sinalizar reservas futuras afetadas, sem cancelá-las automaticamente.

---

## 6. Requisitos funcionais e telas

### RF01 Cadastro

Tela Blade de cadastro com nome, e-mail, telefone, senha, confirmação de senha; erros por campo; senha com hash; conta criada como cliente comum. Impedir duplicidade de e-mail. Ao concluir, autenticar ou redirecionar para login com mensagem clara.

### RF02 Login/logout

Login com e-mail e senha; logout por POST com CSRF; sessão segura; redirecionamento correto conforme perfil. Limitar tentativas de login.

### RF03 Listagem de espaços

Página pública com categorias e espaços ativos, exibindo título gerado, descrição, capacidade e preço/hora; filtro por categoria. Espaços/categorias inativos não aparecem como reserváveis.

### RF04 Disponibilidade

Página do espaço com seletor de data, horários entre 08:00 e 22:00, indicação de livres/ocupados/bloqueados, respeito à antecedência máxima e aos slots passados. A consulta pode ser GET com parâmetros na mesma página Blade, sem API JSON obrigatória.

### RF05 Criar reserva

Cliente autenticado escolhe espaço, data e slot; formulário POST; backend valida tudo e calcula preço; cria reserva com status `confirmed`; exibe confirmação com código, local, data, horário e valor; reserva aparece em Minhas Reservas.

### RF06 Prevenir duplicidade

Revalidação transacional com `lockForUpdate` e checagem de sobreposição conforme seção 4.2. Conflito deve ser legível para o cliente e não criar reserva parcial.

### RF07 Minhas Reservas

Listar só reservas do usuário autenticado, com espaço, data/hora, valor, status e ação de cancelar quando permitida; ordenação por data e destaque de futuras/passadas/canceladas.

### RF08 Cancelamento pelo cliente

POST/DELETE web com CSRF, autorização de propriedade, validação de estado/prazo e confirmação; atualizar `status=cancelled` e `cancelled_at` sem excluir a linha.

### RF09 Categorias (admin)

CRUD lógico de categorias: listar, adicionar, editar, ativar/desativar, ajustar `max_advance_days`, mensagens de sucesso/erro. Evitar hard delete quando houver espaços relacionados.

### RF10 Espaços (admin)

CRUD lógico de espaços: categoria, identificador, descrição, preço/hora, capacidade, ativo/inativo. Mostrar título gerado em preview ou na listagem. Validar identificador único dentro da categoria.

### RF11 Reservas (admin)

Listar/filtrar reservas por data, espaço e status; visualizar detalhes; cancelar; alterar espaço ou horário com **mesmas verificações e transação** de nova reserva e recálculo de preço apenas quando houver reagendamento **[DECISÃO MVP]**. Não editar livremente valor/status por formulário genérico.

### RF12 Bloquear horários (admin)

Criar/listar/remover bloqueios com espaço, início, fim e motivo. **[DECISÃO MVP]** Os bloqueios usam slots de uma hora e horários da grade do MVP; o administrador pode selecionar um ou mais slots consecutivos por bloqueio. Validar não sobreposição com reservas confirmadas nem com bloqueios existentes. Impedir conflitos de forma transacional.

### RF13 Configurar antecedência

Campo `max_advance_days` no formulário de categoria; refletir em consulta de disponibilidade e validação no backend. Exemplo: 21 dias.

### 6.1 Páginas mínimas (Blade)

**Públicas:** página inicial/catálogo; detalhe do espaço com disponibilidade; cadastro; login.  
**Cliente autenticado:** confirmação da reserva; minhas reservas.  
**Administrador:** painel inicial simples; categorias (lista/formulário); espaços (lista/formulário); reservas (lista/detalhe/edição controlada); bloqueios (lista/formulário).  
**Comuns:** layout com cabeçalho, navegação contextual, flash messages e erros, footer opcional e estados vazios.

### 6.2 Interface e usabilidade

- Mobile first; formulários com labels, validação legível, foco visível e botões adequados para toque.
- Mostrar preço em `R$`, datas e horários em pt-BR, sem ambiguidade de fuso.
- Confirmação antes de cancelar/remover bloqueio; feedback após a operação.
- Não depender apenas de cor para diferenciar livre/ocupado; usar rótulos textuais.
- Não exibir links administrativos para clientes, **mas também proteger no backend**.

---

## 7. Fluxograma revisado — cliente e servidor

O fluxograma original usava chamadas de API (`POST /api/login`, `/api/register`, `GET /api/spaces`, `POST /api/reserva`) e tela Vue/Vuetify. **Essas referências foram substituídas pelo fluxo web Blade**:

1. Visitante abre o PLACE (GET catálogo / espaços).
2. Navega por categorias e espaços ativos e vê detalhes.
3. Escolhe data e consulta slots disponíveis (GET página do espaço com data).
4. Ao tentar reservar, deve estar autenticado; se não, vai a login/cadastro e retorna ao fluxo.
5. Escolhe um único slot disponível de 60 minutos.
6. Envia formulário de reserva com POST protegido por CSRF.
7. Backend valida usuário, espaço/categoria ativos, data, antecedência, grade, preço e permissões.
8. **Dentro de transação, bloqueia linha do espaço, revalida reservas confirmadas e bloqueios e registra ou rejeita**.
9. **Sucesso:** salvar reserva, redirecionar para confirmação; exibir em Minhas Reservas.
10. **Conflito:** não salvar; mensagem de horário indisponível; voltar à consulta atualizada.
11. **Entrada inválida:** não salvar; indicar erros no formulário. Erros de autorização retornam 403 ou redirecionamento apropriado.

**Diferença importante sobre os códigos HTTP:** no fluxograma antigo havia `201`, `409` e `422` como respostas de API. Em formulários Blade, o padrão é **POST → redirect com mensagem de sessão (sucesso/conflito)** e erros de validação associados ao formulário (Laravel geralmente redireciona em pedidos web). `201/409/422` são adequados a endpoints JSON, **não devem ser exigidos como resultado visual de um formulário Blade**. A semântica da operação deve ser preservada.

---

## 8. Rotas recomendadas (web)

Usar nomes de rota estáveis, convenções Laravel e `route()` nas views. Exemplos (podem ser ajustados se o projeto já tiver rotas equivalentes):

| Método | Caminho sugerido | Função | Acesso |
|---|---|---|---|
| GET | `/` | catálogo/início | público |
| GET | `/espacos/{space}` | detalhe e slots | público |
| GET/POST | `/register` | cadastro | visitante |
| GET/POST | `/login` | login | visitante |
| POST | `/logout` | sair | autenticado |
| POST | `/reservas` | criar reserva | autenticado |
| GET | `/minhas-reservas` | reservas próprias | autenticado |
| GET | `/minhas-reservas/{reservation}` | confirmação/detalhe próprio | dono/admin |
| POST ou DELETE | `/minhas-reservas/{reservation}/cancelar` | cancelar reserva própria | dono |
| GET | `/admin` | painel | admin |
| resource | `/admin/categorias` | gerenciar categorias | admin |
| resource | `/admin/espacos` | gerenciar espaços | admin |
| GET | `/admin/reservas` | listar todas | admin |
| GET | `/admin/reservas/{reservation}` | detalhes | admin |
| GET/PUT | `/admin/reservas/{reservation}/editar` | reagendar | admin |
| POST | `/admin/reservas/{reservation}/cancelar` | cancelar | admin |
| resource | `/admin/bloqueios` | criar/listar/remover bloqueios | admin |

Na implementação, diferenciar `Route::resource` de rotas específicas para ativar/desativar; preferir métodos HTTP convencionais via `@method` e `@csrf` nos formulários. Evitar rotas de `destroy` para exclusão física de categorias/espaços.

---

## 9. Segurança, privacidade e consistência

1. Validação exclusivamente no servidor para toda ação mutável; JS só melhora experiência.
2. Usar middleware `auth` e `admin`, policies/gates para acesso às reservas próprias e ações sensíveis. **Nunca confiar em `user_id` vindo do formulário**: usar `auth()->id()`.
3. Nunca aceitar `total_price`, `status` ou `is_admin` enviados pelo cliente como verdade de negócio.
4. CSRF em todos os formulários de escrita; autenticação/sessões nativas; hash seguro de senha; rate limit de login.
5. Escapar conteúdo dinâmico nas views Blade (`{{ }}`); não renderizar HTML fornecido por usuários como confiável.
6. Mensagens de erro claras sem vazar stack traces, informações privadas ou consultas SQL.
7. `APP_DEBUG=false` em produção; segredos só no `.env`; não versionar credenciais.
8. Operações de reserva e bloqueio precisam de transação e locks; não misturar validação prévia com inserção desprotegida.
9. Usar datas com timezone controlado: **[DECISÃO MVP]** apresentar e receber horários em `America/Belem`, persistir instantes de forma consistente (preferencialmente UTC internamente) e documentar a conversão. Não comparar strings formatadas de datas.
10. Registrar ao menos `created_at`, `updated_at`, `cancelled_at` e criador do bloqueio para rastreabilidade básica.

---

## 10. Critérios de aceite e testes obrigatórios

O projeto só está concluído quando estes cenários passarem, idealmente como **Feature Tests** do Laravel, além de validação manual da interface:

**Autenticação/permissões**
- Cliente consegue cadastrar, entrar e sair; senha não é salva em texto puro.
- Cadastro público não consegue definir `is_admin=true`.
- Cliente recebe 403 ao acessar rotas administrativas e não vê/edita reservas de terceiros.
- Usuário não autenticado não consegue criar reserva.

**Categorias e espaços**
- `Quadra` + `001` aparece como `Quadra 001` e `001` preserva zeros.
- Dois espaços de uma mesma categoria não podem compartilhar `identifier`; categorias distintas podem.
- Categoria ou espaço inativos não permitem nova reserva.
- Mudança no preço do espaço não altera `total_price` de reserva já confirmada.

**Disponibilidade e reservas**
- Para 14:00–15:00 confirmado, outro cliente não reserva 14:00–15:00.
- Intervalos adjacentes 14:00–15:00 e 15:00–16:00 são permitidos.
- Horário passado, fora de 08:00–22:00, fora da grade de 60 minutos ou além de `max_advance_days` é rejeitado no servidor.
- Reservas canceladas liberam seus horários, se não houver bloqueio.
- Consulta e criação usam exatamente a mesma regra de conflito.
- Preço da reserva é calculado pelo servidor, ignorando valores adulterados no POST.
- **Teste de concorrência real:** disparar duas tentativas simultâneas de reserva do mesmo espaço/slot usando o banco de desenvolvimento/testes compatível com locks; **somente uma confirma**. Não afirmar concorrência validada apenas com SQLite em memória ou testes sequenciais.

**Bloqueios e administração**
- Bloqueio impede criar reserva no mesmo intervalo.
- Reserva confirmada impede criar bloqueio conflitante.
- Bloqueios sobrepostos são rejeitados.
- Remover bloqueio libera slot quando não há outra ocupação.
- Reagendamento administrativo respeita conflitos/antecedência/ativação, sem deixar status/preço inconsistentes.
- Desativar espaço preserva reservas históricas e informa possíveis reservas futuras existentes.

**UX**
- Páginas funcionam no celular e desktop; formulários mostram erros e feedback.
- Após confirmar reserva, ela aparece em Minhas Reservas.
- Conflito entre consulta e envio não cria registro e mostra aviso compreensível.

**Testes e ferramentas:** executar `php artisan test` (ou `./vendor/bin/sail artisan test` no ambiente Sail), `php artisan route:list`, migrações em banco de teste, e conferir logs/erros de renderização Blade. Documentar qualquer teste que não tenha sido executado e o motivo.

---

## 11. Ordem de implementação obrigatória para o agente

**ETAPA 0 — Auditoria sem alterações:** inspecionar versão Laravel, estrutura de pastas, banco `.env`/compose, migrations existentes, autenticação, layout, testes, rotas e estado do Git. Listar diferenças reais em relação a este documento. Nunca sobrescrever trabalho atual.

**ETAPA 1 — Fundação e dados:** migrations incrementais; models, casts, relações, índices; validações e configuração centralizada de horário/fuso. Garantir que migrações são reversíveis e não destroem dados.

**ETAPA 2 — Autenticação e autorização:** cadastro/login/logout, `is_admin`, middleware/policies, criação segura do primeiro admin e testes de autorização.

**ETAPA 3 — Área admin de cadastros:** categorias/espaços com Blade + Tailwind, regras de ativação, título calculado e testes.

**ETAPA 4 — Catálogo e disponibilidade:** catálogo, detalhes, consultas por data e geração de slots com bloqueios, antecedência e status.

**ETAPA 5 — Reserva robusta:** transação/lock, validações, cálculo de preço, confirmação e Minhas Reservas; testes de conflito, cancelamento e concorrência.

**ETAPA 6 — Administração operacional:** bloqueios, gestão de reservas, cancelamento e reagendamento seguros; testes.

**ETAPA 7 — Acabamento e entrega:** responsividade, tratamento de erros, seeds opcionais com dados fictícios, README de instalação/uso, resumo de rotas e comandos de teste, relatório do que foi executado.

**Após cada etapa:** mostrar arquivos criados/alterados, comportamento implementado, testes realizados, riscos ou pendências. Não declarar “pronto” se não testou. Se uma decisão arquitetural impedir cumprimento do requisito, explicar antes de improvisar.

---

## 12. Entregáveis

O agente deve deixar no repositório:

- Sistema Laravel Blade funcional, sem componentes Vue/Vuetify novos.
- Migrations, models, controllers/services/Form Requests/middleware ou policies conforme necessário.
- Views Blade responsivas com Tailwind.
- Rotas web organizadas, seguras e nomeadas.
- Seeders/factories úteis para desenvolvimento e testes (sem credenciais fixas expostas).
- Testes automatizados dos cenários críticos.
- README com instalação, banco/ambiente, criação do admin, migrações, testes e principais regras de negócio.
- Resumo final de implementado, não implementado, ajustes feitos sobre o projeto existente e limitações verificadas.

---

## 13. Pendências reconhecidas / fora do escopo

Os documentos originais **não** determinavam: horário de funcionamento; duração dos slots; prazo limite de cancelamento; comportamento de preço após alteração; modo seguro de bloquear intervalos; nem modelo de concorrência. As escolhas para o MVP foram expressamente marcadas **[DECISÃO MVP]**. Alterá-las depois pode exigir mudanças em UI, banco ou lógica de disponibilidade.

Não implementar nesta versão: pagamento online, multas, políticas financeiras de reembolso, notificações automáticas, reservas de múltiplas horas, recorrência, calendário semanal distinto por dia, feriados, múltiplas unidades/estabelecimentos, integração WhatsApp, avaliações e relatórios avançados. Não inventar esses recursos como se estivessem pedidos.

**Condição de conclusão:** uma reserva válida pode ser feita por cliente autenticado e aparece em Minhas Reservas; horários ocupados/bloqueados/fora de regra são rejeitados inclusive sob concorrência; administrador gerencia categorias, espaços, reservas e bloqueios; e os testes críticos passam no banco real usado pelo projeto.

---

## 14. PROMPT DE EXECUÇÃO — copie para o agente do PhpStorm

> Leia o arquivo `PLACE_especificacao_unificada_para_IA.md` na íntegra. Ele é a fonte principal de requisitos para implementar o MVP do PLACE com Laravel + Blade + Tailwind, sem Vue/Vuetify. Primeiro inspecione o projeto existente e preserve o banco/ambiente configurados (especialmente se já utiliza Laravel Sail + PostgreSQL). Faça uma auditoria curta das divergências; depois implemente as Etapas 1 a 7 em ordem, realizando testes a cada etapa e sem executar operações destrutivas. Respeite todas as regras críticas de autorização, cálculo de preço e prevenção de reservas simultâneas por transações e lock da linha do espaço. Use as **[DECISÃO MVP]** explícitas deste arquivo para resolver ambiguidades, sem inventar funcionalidades extras. Ao final, informe arquivos modificados, comandos rodados, testes executados, pendências e instruções para rodar o sistema. Se houver bloqueio técnico que impeça avançar, apresente-o precisamente; não declare uma etapa concluída sem evidências.
