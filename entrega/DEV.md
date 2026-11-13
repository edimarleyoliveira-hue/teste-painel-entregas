# Entrega – Teste de Desenvolvimento

**Nome:** Edimarley Oliveira
**Tempo utilizado:**

## O que foi feito

Marque o que você concluiu e descreva brevemente como implementou.

- [x] Filtros da listagem
  - `api/entregas.php` aceita `empresa`, `departamento` e `status` na URL (ex.: `?departamento=Fiscal&status=atrasada`). Os filtros podem ser combinados e são aplicados no SQL, em `EntregaRepository::listar()`.
  - Na tela, a barra de filtros fica acima da lista, com os botões "Filtrar" e "Limpar filtros". Quando nada corresponde, a tela mostra "Nenhuma entrega encontrada para os filtros selecionados."
- [ ] Cadastro de entregas
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

## O que ficou faltando ou você faria com mais tempo
