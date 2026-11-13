# Entrega – Teste de Desenvolvimento

**Nome:** Edimarley Oliveira
**Tempo utilizado:**

## O que foi feito

Marque o que você concluiu e descreva brevemente como implementou.

- [x] Filtros da listagem
  - `api/entregas.php` aceita `empresa`, `departamento` e `status` na URL (ex.: `?departamento=Fiscal&status=atrasada`). Os filtros podem ser combinados e são aplicados no SQL, em `EntregaRepository::listar()`.
  - Na tela, a barra de filtros fica acima da lista, com os botões "Filtrar" e "Limpar filtros". Quando nada corresponde, a tela mostra "Nenhuma entrega encontrada para os filtros selecionados."
- [x] Cadastro de entregas
  - Formulário "Nova entrega" com empresa e obrigação (listas vindas do banco por `api/empresas.php` e `api/obrigacoes.php`), competência (`type="month"`) e vencimento (`type="date"`).
  - `POST api/entregas.php` com JSON `{empresa_id, obrigacao_id, competencia, vencimento}`. Valida tudo no servidor e grava sempre com `data_entrega` vazia. Responde 201 em caso de sucesso, 400 para dado inválido e 409 para duplicado.
  - Após salvar, a lista e o resumo são recarregados, o formulário é limpo e aparece a mensagem de sucesso. Em caso de erro, aparece a mensagem do servidor.
- [x] Marcar como entregue (uma linha)
- [x] Marcar várias como entregues (seleção)
  - Cada linha não entregue tem uma caixa de seleção e o botão "Marcar como entregue". O botão "Marcar selecionadas como entregues (N)" fica desabilitado com 0 selecionadas e mostra a quantidade.
  - Os dois botões usam o mesmo endpoint, `POST api/marcar-entregues.php`, com `{"ids": [..]}`. A data gravada é a de hoje (`CURDATE()`).
  - Depois da ação, a lista e o resumo são recarregados com os filtros que estão aplicados, a seleção é limpa e aparece "N entrega(s) marcada(s) como entregue(s)".
- [x] Bônus: selecionar todas as entregas visíveis de uma vez
  - Caixa no cabeçalho da coluna de seleção. Um clique seleciona todas as entregas não entregues que estão na lista (respeitando os filtros); outro clique desmarca. Fica "meio marcada" quando só algumas estão selecionadas e desabilitada quando não há nada para selecionar.

Correção extra: `responderErro()` devolvia a mensagem técnica da exceção (`detalhe`) para o navegador. Agora ela vai só para o log do servidor (`error_log`).

## Decisões tomadas

Escolhas que você fez e por quê (estrutura do código, validações, regras que você precisou interpretar).

- **Segurança na busca por empresa:** o termo vai para o banco por *prepared statement* (`LIKE ?`), então apóstrofo, `&` e acentos nunca se misturam com o SQL. Os caracteres `%` e `_`, que no `LIKE` funcionam como curingas, são escapados para que a busca seja pelo texto literal. Por isso, buscar `%` não retorna tudo.
- **Maiúsculas e acentos:** as tabelas usam a collation `utf8mb4_unicode_ci`, que já compara sem diferenciar maiúsculas e acentos. Assim, `d'avila` encontra "Padaria D'Ávila Ltda".
- **Status no SQL:** o filtro de status usa exatamente as mesmas condições do `resumo()`, com `CURDATE()`. Assim, filtrar por "atrasada" retorna a mesma quantidade mostrada no card "Atrasadas".
- **Validação:** departamento e status só aceitam valores de uma lista fechada (lista branca). Valor diferente → HTTP 400 com mensagem dizendo o que usar. A busca por empresa tem no máximo 150 caracteres, o tamanho da coluna. Parâmetro enviado em formato de lista (`?empresa[]=x`) é ignorado.
- **Resumo:** o resumo continua mostrando os totais do banco inteiro, sem aplicar os filtros, como pede a regra "deve estar sempre de acordo com o banco".
- **Na tela:** os parâmetros são montados com `URLSearchParams`, que codifica `&` e outros caracteres especiais, para a busca chegar intacta na API.
- **Validação do cadastro no servidor:** empresa e obrigação precisam ser números inteiros positivos e existir no banco. A competência precisa estar no formato `AAAA-MM`, com mês de 01 a 12. O vencimento precisa ser uma data que exista no calendário: `2026-02-30` é recusada, porque `DateTime::createFromFormat` é conferido de volta. Quando vários campos estão errados, todos os erros voltam juntos, cada um dizendo o que corrigir.
- **Sem duplicidade, em duas camadas:** (1) antes do INSERT, o PHP verifica se já existe entrega com a mesma empresa, obrigação e competência e, nesse caso, responde 409 com uma mensagem clara; (2) acrescentei no `database/painel_entregas.sql` o índice `UNIQUE (empresa_id, obrigacao_id, competencia)`, que barra duplicidade até em dois cadastros simultâneos. O erro 1062 também é traduzido para 409. **É preciso reimportar o SQL** para o índice existir.
- **Nasce sem data de entrega:** o INSERT grava `data_entrega` como `NULL` de forma fixa. Mesmo que alguém envie `data_entrega` no JSON, o valor é ignorado.
- **Regras que interpretei:** o ano da competência e do vencimento é aceito entre 1900 e 2099, como trava contra digitação errada. Não exigi relação entre vencimento e competência (por exemplo, vencimento depois do mês da competência), porque o README não pede isso e há obrigações anuais com prazos variados. Depois de cadastrar, a lista recarrega mantendo os filtros ativos. Se o filtro for "Entregue", a nova linha, que é pendente ou atrasada, não aparece, mas o resumo é atualizado.
- **Validação na tela (bônus):** campos obrigatórios conferidos antes do envio, para dar resposta rápida. Ela não substitui a validação do servidor.
- **Data de entrega nunca é alterada:** a proteção está no próprio SQL: `UPDATE ... SET data_entrega = CURDATE() WHERE id IN (...) AND data_entrega IS NULL`. Mesmo que alguém chame a API direto com o id de uma entrega já entregue, a linha não é atingida. O `rowCount()` informa quantas foram realmente marcadas, e a mensagem avisa quando alguma foi ignorada.
- **Validação da lista de ids:** precisa ser uma lista não vazia de inteiros positivos, com no máximo 500 itens. Ids repetidos contam uma vez só. Os marcadores do `IN (?, ?, ...)` são gerados pela quantidade de ids, e os valores vão separados do SQL.
- **"Hoje" no mesmo relógio:** a data gravada vem do `CURDATE()` do banco, o mesmo relógio que o resumo usa para separar pendentes de atrasadas.
- **Só JSON nos POSTs (proteção contra CSRF):** `lerCorpoJson()` exige `Content-Type: application/json` e responde 415 caso contrário. Um formulário HTML de outro site não consegue enviar esse cabeçalho sem a autorização do navegador, então fica difícil um site de terceiros cadastrar ou marcar entregas usando o navegador de quem está com o painel aberto. Isso vale para o cadastro e para a marcação. O projeto não tem login, então essa é uma proteção básica, não completa.
- **Tela à prova de clique duplo:** enquanto a marcação está a caminho, os botões ficam bloqueados. Depois da ação, saem da seleção só as entregas enviadas; o que o usuário marcou e ainda não enviou continua marcado. Se a API falhar, o botão da linha volta a funcionar. Quando nada é marcado (por exemplo, porque outra pessoa já tinha entregue), a mensagem aparece no estilo de alerta.
- **Seleção e filtros:** ao trocar os filtros, a seleção mantém só as entregas que continuam visíveis, para não marcar algo que o usuário não está vendo.
- **Organização:** criei `EmpresaRepository` e `ObrigacaoRepository` no mesmo padrão do `EntregaRepository`. As funções de validação reaproveitáveis (`inteiroPositivo`, `competenciaValida`, `dataValida`, `lerCorpoJson`) ficam em `src/helpers.php`.

## Como testar

Passos para quem for avaliar conferir cada funcionalidade.

**Filtros (tela):** abrir http://localhost/painel-entregas e:
1. Empresa `café & prosa` → só "Café & Prosa ME".
2. Empresa `d'avila` → só "Padaria D'Ávila Ltda".
3. Departamento "Contábil" + Status "Atrasada" → só as linhas que atendem aos dois filtros.
4. Status "Atrasada" sozinho → a quantidade de linhas é igual ao número do card "Atrasadas".
5. Empresa `xyz` → aparece a mensagem de nenhum resultado.
6. "Limpar filtros" → os campos esvaziam e a lista completa volta.

**Filtros (API direto):**
- `api/entregas.php?empresa=%25` → `[]` (o `%` é tratado como texto).
- `api/entregas.php?status=xyz` → HTTP 400 `{"erro":"Status inválido. Use pendente, atrasada ou entregue."}`
- `api/entregas.php?departamento=RH` → HTTP 400.

**Cadastro (tela):**
1. Preencher todos os campos e clicar em "Cadastrar" → aparece "Entrega cadastrada com sucesso.", o Total do resumo aumenta 1 e a nova linha aparece como Pendente ou Atrasada, conforme o vencimento.
2. Repetir exatamente o mesmo cadastro → aparece "Já existe uma entrega desta obrigação para esta empresa nesta competência."
3. Clicar em "Cadastrar" com o formulário vazio → aparece a mensagem com tudo o que falta preencher.

**Cadastro (API direto):**
- `POST api/entregas.php` com `{"empresa_id":1,"obrigacao_id":2,"competencia":"2026-13","vencimento":"2026-02-30"}` → 400 explicando a competência e o vencimento inválidos.
- `{"empresa_id":999, ...}` → 400 "A empresa selecionada não está cadastrada."
- Corpo que não é JSON → 400.

**Marcar como entregue (tela):**
1. Filtrar Status "Atrasada" e clicar em "Marcar como entregue" numa linha → aparece "1 entrega marcada como entregue.", a linha some da lista (o filtro continua aplicado) e os cards Entregues e Atrasadas mudam.
2. Marcar 2 caixas → o botão em lote mostra "(2)". Clicar nele → aparece "2 entregas marcadas como entregues.".
3. Linhas entregues não têm caixa nem botão.

**Selecionar todas (bônus):**
1. Filtrar Status "Pendente" e clicar na caixa do cabeçalho → todas as linhas da lista ficam marcadas e o botão mostra a quantidade.
2. Desmarcar uma linha → a caixa do cabeçalho fica "meio marcada".
3. Clicar em "Marcar selecionadas como entregues" → todas são marcadas de uma vez.

**Marcar como entregue (API direto):**
- `POST api/marcar-entregues.php` com `{"ids":[1]}` (o id 1 já está entregue na carga inicial) → `marcadas: 0` e a data no banco não muda.
- `{"ids":[]}`, `{"ids":["abc"]}` ou `{"ids":[0]}` → 400.

## O que ficou faltando ou você faria com mais tempo
