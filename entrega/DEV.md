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
- [ ] Marcar como entregue (uma linha)
- [ ] Marcar várias como entregues (seleção)
- [ ] Bônus:

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

## O que ficou faltando ou você faria com mais tempo
