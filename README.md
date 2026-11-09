# PLACER

Agendamento de espaços com Laravel 13, Blade, Tailwind CSS 4 e PostgreSQL. A especificação do MVP está em [AI/PLACE_especificacao_unificada_para_IA.md](AI/PLACE_especificacao_unificada_para_IA.md).

## Ambiente existente

Preserve o `.env`, os containers e os volumes já utilizados. O projeto inclui Laravel Sail com PHP 8.5 e PostgreSQL 18; o Composer exige PHP 8.3 ou superior. Não é necessário recriar o projeto ou reinstalar dependências já presentes.

Em uma instalação nova, instale as dependências com `composer install` e `npm ci`, copie `.env.example` para `.env`, preencha as credenciais do PostgreSQL e gere a chave com `php artisan key:generate --no-interaction`. Não gere outra chave em instalações existentes.

Dentro do Sail, use `DB_HOST=pgsql`; executando PHP diretamente na máquina, use o endereço e a porta publicados pelo PostgreSQL. O `.env.example` contém valores de exemplo, que não substituem as credenciais do banco existente. O Sail atende na porta `APP_PORT` (80 por padrão); ajuste `APP_URL` conforme a URL utilizada.

Com os serviços existentes em execução, aplique somente migrações pendentes e compile a interface:

```bash
./vendor/bin/sail artisan migrate --no-interaction
npm run build
```

Para desenvolvimento da interface, `npm run dev`. Para consultar migrações sem alterá-las, `./vendor/bin/sail artisan migrate:status --no-interaction`.

## Primeiro administrador

Execute o comando interativo no terminal:

```bash
./vendor/bin/sail artisan placer:admin
```

Informe nome, e-mail único, telefone e senha de pelo menos 12 caracteres. A senha é solicitada de forma oculta e armazenada como hash. O cadastro público sempre cria clientes. Não há conta administrativa ou senha padrão.

## Catálogo de demonstração (opcional)

```bash
./vendor/bin/sail artisan db:seed --class=CatalogSeeder --no-interaction
```

Adiciona cinco categorias e espaços fictícios, sem criar usuários ou reservas. Pode ser repetido: registros existentes não são sobrescritos, preços e descrições editados são preservados, categorias inativas são ignoradas. O `DatabaseSeeder` também chama esse catálogo; os dados são incluídos somente ao executar um seeder explicitamente.

## Uso

- `/`: catálogo público e filtro de categoria.
- `/espacos/{space}`: disponibilidade por data; visitantes podem entrar ou criar conta para reservar.
- `/minhas-reservas`: reservas do cliente, confirmação e cancelamento.
- `/admin`: painel administrativo.
- `/admin/categorias` e `/admin/espacos`: cadastro, edição e ativação/desativação.
- `/admin/reservas`: filtros por data, espaço e situação; detalhes, cancelamento e reagendamento com consulta de horários livres.
- `/admin/bloqueios`: criação e remoção de indisponibilidades com confirmação.

Todas as ações administrativas exigem autenticação e perfil de administrador no servidor. As rotas completas podem ser consultadas com `php artisan route:list --except-vendor`.

## Regras do MVP

Reservas de 60 minutos, em hora cheia, entre 08h e 22h, diariamente. O fuso de entrada e apresentação é `America/Belem`; os instantes são convertidos para UTC antes da persistência. A configuração está em `config/placer.php`.

A antecedência é definida por categoria: N dias a partir do momento atual; zero permite somente horários futuros de hoje. Espaços ou categorias inativos impedem novas reservas, preservando histórico e reservas confirmadas.

O preço é calculado no servidor e congelado na reserva. Reagendar para outro espaço ou horário aplica o preço atual; salvar o mesmo espaço e horário mantém o valor original. Clientes cancelam apenas reservas próprias futuras; administradores podem cancelar reservas confirmadas passadas. Cancelar preserva o registro e libera o horário.

Reservas, reagendamentos e bloqueios usam transações e locks na linha do espaço, com revalidação dos conflitos. Intervalos adjacentes são permitidos. Bloqueios podem abranger horas consecutivas no mesmo dia e não podem sobrepor reservas confirmadas ou outros bloqueios.

Pagamentos, notificações, recorrência e relatórios avançados estão fora do MVP.

## Verificação essencial

Os testes usam exclusivamente o banco PostgreSQL `testing`, configurado em `phpunit.xml`. Confirme sua existência e as credenciais antes de executar. Nunca substitua esse nome pelo banco de desenvolvimento ou produção. Prepare apenas migrações pendentes no banco de teste, definindo o banco explicitamente (o Artisan não carrega as variáveis do `phpunit.xml`):

```bash
docker compose exec -e DB_DATABASE=testing laravel.test php artisan migrate --no-interaction
./vendor/bin/sail artisan test --compact tests/Feature/PlacerTest.php
./vendor/bin/sail artisan test --compact tests/Feature/ConcurrencyTest.php
```

O teste de concorrência requer PostgreSQL e a extensão `pcntl`, e usa dois processos reais. Não substitua por SQLite para afirmar proteção contra concorrência. Os testes funcionais existentes cobrem autenticação, permissões, preços, conflitos, cancelamentos, bloqueios e reagendamento. Para formatação PHP, `vendor/bin/pint --dirty --format agent`.

Nesta sessão, a conexão local com `pgsql` não pôde ser resolvida e o acesso ao socket Docker foi negado. A validação funcional e de concorrência no PostgreSQL permanece pendente até execução no ambiente Sail acessível. Isso não foi contornado recriando banco, containers ou volumes.
