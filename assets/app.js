const API = 'api';
const TOTAL_COLUNAS = 9;

let entregas = [];

// Ids das entregas não entregues marcadas na caixa de seleção.
const selecionadas = new Set();

// true enquanto um pedido de "marcar como entregue" está a caminho da API.
let enviando = false;

const rotulosStatus = {
  entregue: 'Entregue',
  pendente: 'Pendente',
  atrasada: 'Atrasada',
};

function escaparHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}

// Recebe AAAA-MM-DD e devolve DD/MM/AAAA, sem converter fuso horário.
function formatarData(data) {
  if (!data) {
    return '—';
  }
  const [ano, mes, dia] = data.split('-');
  return `${dia}/${mes}/${ano}`;
}

// Data de hoje no formato AAAA-MM-DD, pelo relógio do computador (sem converter fuso).
function hojeIso() {
  const agora = new Date();
  const mes = String(agora.getMonth() + 1).padStart(2, '0');
  const dia = String(agora.getDate()).padStart(2, '0');
  return `${agora.getFullYear()}-${mes}-${dia}`;
}

// Recebe AAAA-MM e devolve MM/AAAA.
function formatarCompetencia(competencia) {
  const [ano, mes] = competencia.split('-');
  return `${mes}/${ano}`;
}

function mostrarAviso(mensagem, tipo = 'sucesso') {
  const aviso = document.getElementById('aviso');
  aviso.textContent = mensagem;
  aviso.className = tipo === 'erro' ? 'aviso aviso--erro' : 'aviso';
  aviso.hidden = false;
  clearTimeout(mostrarAviso.timer);
  // Mensagens de erro ficam mais tempo na tela para dar tempo de ler o que corrigir.
  mostrarAviso.timer = setTimeout(() => { aviso.hidden = true; }, tipo === 'erro' ? 8000 : 4000);
}

async function carregarResumo() {
  try {
    const resposta = await fetch(`${API}/resumo.php`);
    const resumo = await resposta.json();

    if (!resposta.ok) {
      mostrarAviso(resumo.erro || 'Erro ao carregar o resumo.', 'erro');
      return;
    }

    document.getElementById('resumo-total').textContent = resumo.total;
    document.getElementById('resumo-entregues').textContent = resumo.entregues;
    document.getElementById('resumo-pendentes').textContent = resumo.pendentes;
    document.getElementById('resumo-atrasadas').textContent = resumo.atrasadas;
  } catch (erro) {
    mostrarAviso('Não foi possível conectar à API.', 'erro');
  }
}

// Lê os campos do formulário de filtros e monta os parâmetros da URL (só os preenchidos).
// URLSearchParams codifica caracteres especiais como & e ', então a busca chega intacta na API.
function filtrosAtuais() {
  const parametros = new URLSearchParams();
  const dados = new FormData(document.getElementById('form-filtros'));

  for (const [nome, valor] of dados) {
    if (valor.trim() !== '') {
      parametros.append(nome, valor.trim());
    }
  }

  return parametros;
}

async function carregarEntregas() {
  const filtros = filtrosAtuais().toString();
  const url = filtros ? `${API}/entregas.php?${filtros}` : `${API}/entregas.php`;

  try {
    const resposta = await fetch(url);
    const dados = await resposta.json();

    if (!resposta.ok) {
      entregas = [];
      mostrarAviso(dados.erro || 'Erro ao carregar as entregas.', 'erro');
    } else {
      entregas = dados;
    }
  } catch (erro) {
    entregas = [];
    mostrarAviso('Não foi possível conectar à API.', 'erro');
  }

  // Mantém selecionadas só as que continuam visíveis e não entregues.
  const selecionaveis = new Set(idsSelecionaveis());
  selecionadas.forEach((id) => {
    if (!selecionaveis.has(id)) {
      selecionadas.delete(id);
    }
  });

  renderizarTabela();
  atualizarBotaoLote();
}

// Ids das entregas não entregues que estão aparecendo na lista agora.
function idsSelecionaveis() {
  return entregas.filter((e) => e.status !== 'entregue').map((e) => Number(e.id));
}

function atualizarBotaoLote() {
  const botao = document.getElementById('marcar-selecionadas');
  botao.textContent = `Marcar selecionadas como entregues (${selecionadas.size})`;
  botao.disabled = enviando || selecionadas.size === 0;

  // Caixa "selecionar todas": marcada se todas estão selecionadas,
  // "meio marcada" (indeterminate) se só algumas, e desabilitada se não há o que selecionar.
  const todas = document.getElementById('selecionar-todas');
  const total = idsSelecionaveis().length;
  todas.disabled = total === 0;
  todas.checked = total > 0 && selecionadas.size === total;
  todas.indeterminate = selecionadas.size > 0 && selecionadas.size < total;
}

function renderizarTabela() {
  const corpo = document.getElementById('lista-entregas');

  if (entregas.length === 0) {
    const mensagem = filtrosAtuais().toString()
      ? 'Nenhuma entrega encontrada para os filtros selecionados.'
      : 'Nenhuma entrega encontrada.';
    corpo.innerHTML = `<tr><td colspan="${TOTAL_COLUNAS}" class="tabela__vazio">${mensagem}</td></tr>`;
    return;
  }

  // Só linhas não entregues ganham caixa de seleção e botão. O id é numérico (Number) e vai em atributo.
  corpo.innerHTML = entregas.map((entrega) => {
    const id = Number(entrega.id);
    const naoEntregue = entrega.status !== 'entregue';
    const selecao = naoEntregue
      ? `<input type="checkbox" class="selecao" data-id="${id}" aria-label="Selecionar entrega" ${selecionadas.has(id) ? 'checked' : ''}>`
      : '';
    const acao = naoEntregue
      ? `<button type="button" class="botao botao--secundario" data-acao="marcar" data-id="${id}">Marcar como entregue</button>`
      : `<button type="button" class="botao botao--secundario" data-acao="desfazer" data-id="${id}">Desfazer</button>`;

    return `
    <tr>
      <td class="tabela__selecao">${selecao}</td>
      <td>
        <span class="tabela__empresa">${escaparHtml(entrega.razao_social)}</span>
        <span class="tabela__cnpj">${escaparHtml(entrega.empresa_codigo)} · ${escaparHtml(entrega.documento)}</span>
      </td>
      <td>${escaparHtml(entrega.obrigacao)}</td>
      <td>${escaparHtml(entrega.departamento)}</td>
      <td>${formatarCompetencia(entrega.competencia)}</td>
      <td>${formatarData(entrega.vencimento)}</td>
      <td>${formatarData(entrega.data_entrega)}</td>
      <td><span class="status status--${entrega.status}">${rotulosStatus[entrega.status]}</span></td>
      <td>
        <div class="tabela__acoes">
          ${acao}
          <button type="button" class="botao botao--secundario" data-acao="detalhes" data-id="${id}">Detalhes</button>
        </div>
      </td>
    </tr>
  `;
  }).join('');
}

// ---------- Histórico da entrega (botão "Detalhes") ----------

// "AAAA-MM-DD HH:MM:SS" -> "DD/MM/AAAA às HH:MM", sem converter fuso.
function formatarDataHora(valor) {
  const [data, hora] = String(valor).split(' ');
  return `${formatarData(data)} às ${(hora || '').slice(0, 5)}`;
}

function descreverAcao(acao) {
  return acao.acao === 'entregue'
    ? `<span class="status status--entregue">Marcou como entregue</span> em ${formatarData(acao.data_entrega)}`
    : '<span class="status status--pendente">Voltou para pendente</span>';
}

// Id da entrega cujo histórico está aberto: se a resposta de outra chegar atrasada, é ignorada.
let historicoAberto = null;

async function abrirHistorico(id) {
  historicoAberto = id;
  const entrega = entregas.find((e) => Number(e.id) === id);
  document.getElementById('titulo-dialogo-historico').textContent = entrega
    ? `Histórico — ${entrega.obrigacao} · ${entrega.razao_social} · ${formatarCompetencia(entrega.competencia)}`
    : 'Histórico da entrega';

  const corpo = document.getElementById('lista-historico');
  corpo.innerHTML = '<tr><td colspan="3" class="tabela__vazio">Carregando…</td></tr>';
  document.getElementById('dialogo-historico').showModal();

  const { ok, dados } = await requisicaoJson(`${API}/historico.php?entrega_id=${Number(id)}`);

  if (historicoAberto !== id) {
    return;
  }

  if (!ok) {
    corpo.innerHTML = '<tr><td colspan="3" class="tabela__vazio">Não foi possível carregar o histórico.</td></tr>';
    return;
  }

  if (dados.length === 0) {
    corpo.innerHTML = '<tr><td colspan="3" class="tabela__vazio">Nenhuma ação registrada para esta entrega ainda.</td></tr>';
    return;
  }

  corpo.innerHTML = dados.map((acao) => `
    <tr>
      <td>${formatarDataHora(acao.realizado_em)}</td>
      <td>${escaparHtml(acao.usuario)}</td>
      <td>${descreverAcao(acao)}</td>
    </tr>
  `).join('');
}

// Envia os ids para a API. Serve para uma linha (lista com 1 id) e para várias.
// Data que preenchemos automaticamente no campo "Data da entrega".
let dataPadrao = hojeIso();

// Se o dia virou com a página aberta, o campo que ainda está com a data automática
// passa para o novo "hoje" (e o limite máximo também), para não gravar ontem sem querer.
function atualizarCampoData() {
  const campo = document.getElementById('data-entrega');
  const hoje = hojeIso();

  if (campo.value === dataPadrao) {
    campo.value = hoje;
  }
  dataPadrao = hoje;
  campo.max = hoje;
}

// Devolve true se deu certo.
async function marcarComoEntregues(ids) {
  atualizarCampoData();
  const dataEntrega = document.getElementById('data-entrega').value;

  // Validação na tela; o servidor confere de novo.
  if (!dataEntrega) {
    mostrarAviso('Informe a data da entrega.', 'erro');
    return false;
  }
  if (dataEntrega > hojeIso()) {
    mostrarAviso('A data de entrega não pode ser uma data futura.', 'erro');
    return false;
  }

  enviando = true;
  atualizarBotaoLote();

  try {
    const resposta = await fetch(`${API}/marcar-entregues.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, data_entrega: dataEntrega }),
    });
    const resultado = await resposta.json();

    if (!resposta.ok) {
      mostrarAviso(resultado.erro || 'Não foi possível marcar as entregas.', 'erro');
      return false;
    }

    // Tira da seleção só o que foi enviado; o resto do que o usuário marcou continua marcado.
    ids.forEach((id) => selecionadas.delete(id));
    // Uma data escolhida vale só para esta marcação: o campo volta para hoje.
    document.getElementById('data-entrega').value = dataPadrao;
    mostrarAviso(resultado.mensagem, resultado.marcadas > 0 ? 'sucesso' : 'erro');
    // Recarrega com os filtros que estão nos campos, então eles continuam valendo.
    await Promise.all([carregarResumo(), carregarEntregas()]);
    return true;
  } catch (erro) {
    mostrarAviso('Não foi possível conectar à API.', 'erro');
    return false;
  } finally {
    enviando = false;
    atualizarBotaoLote();
  }
}

// Um único "ouvinte" na tabela trata as caixas e os botões de todas as linhas.
document.getElementById('lista-entregas').addEventListener('change', (evento) => {
  const caixa = evento.target;
  if (!caixa.classList.contains('selecao')) {
    return;
  }

  const id = Number(caixa.dataset.id);
  if (caixa.checked) {
    selecionadas.add(id);
  } else {
    selecionadas.delete(id);
  }
  atualizarBotaoLote();
});

// Bônus: desfaz uma entrega marcada por engano (a data de entrega volta a ficar vazia).
async function desfazerEntrega(id) {
  try {
    const resposta = await fetch(`${API}/desfazer-entrega.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const resultado = await resposta.json();

    if (!resposta.ok) {
      mostrarAviso(resultado.erro || 'Não foi possível desfazer a entrega.', 'erro');
      return false;
    }

    mostrarAviso(resultado.mensagem);
    await Promise.all([carregarResumo(), carregarEntregas()]);
    return true;
  } catch (erro) {
    mostrarAviso('Não foi possível conectar à API.', 'erro');
    return false;
  }
}

document.getElementById('lista-entregas').addEventListener('click', async (evento) => {
  const botaoDetalhes = evento.target.closest('button[data-acao="detalhes"]');
  if (botaoDetalhes) {
    abrirHistorico(Number(botaoDetalhes.dataset.id));
    return;
  }

  const botaoDesfazer = evento.target.closest('button[data-acao="desfazer"]');
  if (botaoDesfazer) {
    if (!window.confirm('Desfazer esta entrega? Ela voltará a ficar sem data de entrega.')) {
      return;
    }
    botaoDesfazer.disabled = true;
    if (!(await desfazerEntrega(Number(botaoDesfazer.dataset.id)))) {
      botaoDesfazer.disabled = false;
    }
    return;
  }

  const botao = evento.target.closest('button[data-acao="marcar"]');
  if (!botao || enviando) {
    return;
  }

  botao.disabled = true;
  const deuCerto = await marcarComoEntregues([Number(botao.dataset.id)]);
  if (!deuCerto) {
    botao.disabled = false; // em caso de erro, o usuário pode tentar de novo
  }
});

// Seleciona (ou desmarca) de uma vez todas as entregas não entregues visíveis na lista.
document.getElementById('selecionar-todas').addEventListener('change', (evento) => {
  if (evento.target.checked) {
    idsSelecionaveis().forEach((id) => selecionadas.add(id));
  } else {
    selecionadas.clear();
  }

  document.querySelectorAll('#lista-entregas .selecao').forEach((caixa) => {
    caixa.checked = selecionadas.has(Number(caixa.dataset.id));
  });
  atualizarBotaoLote();
});

document.getElementById('marcar-selecionadas').addEventListener('click', () => {
  if (!enviando && selecionadas.size > 0) {
    marcarComoEntregues([...selecionadas]);
  }
});

// Envia/recebe JSON. Devolve { ok, dados }; em falha de rede, ok = false e dados.erro explica.
async function requisicaoJson(url, metodo = 'GET', corpo = undefined) {
  const opcoes = { method: metodo };
  if (corpo !== undefined) {
    opcoes.headers = { 'Content-Type': 'application/json' };
    opcoes.body = JSON.stringify(corpo);
  }

  try {
    const resposta = await fetch(url, opcoes);
    const dados = await resposta.json();
    return { ok: resposta.ok, dados };
  } catch (erro) {
    return { ok: false, dados: { erro: 'Não foi possível conectar à API.' } };
  }
}

// Preenche um <select> com opções vindas da API. new Option() trata o texto como texto puro.
// Mantém só a primeira opção ("Selecione…") e troca as demais, para poder recarregar.
function preencherSelect(select, itens, rotulo) {
  const escolhido = select.value;
  select.length = 1;
  itens.forEach((item) => {
    select.appendChild(new Option(rotulo(item), item.id));
  });
  // Mantém a escolha que já estava feita, se ela ainda existir.
  select.value = escolhido;
  if (select.value !== escolhido) {
    select.value = '';
  }
}

async function carregarOpcoesCadastro() {
  try {
    const [respostaEmpresas, respostaObrigacoes] = await Promise.all([
      fetch(`${API}/empresas.php`),
      fetch(`${API}/obrigacoes.php`),
    ]);

    if (!respostaEmpresas.ok || !respostaObrigacoes.ok) {
      mostrarAviso('Erro ao carregar empresas e obrigações para o cadastro.', 'erro');
      return;
    }

    const empresas = await respostaEmpresas.json();
    const obrigacoes = await respostaObrigacoes.json();

    preencherSelect(document.getElementById('cadastro-empresa'), empresas, (e) => e.razao_social);
    preencherSelect(document.getElementById('cadastro-obrigacao'), obrigacoes, (o) => `${o.nome} (${o.departamento})`);
  } catch (erro) {
    mostrarAviso('Não foi possível conectar à API.', 'erro');
  }
}

// Validação na tela (ajuda o usuário). A validação que vale é a do servidor.
function validarCadastro(dados) {
  const erros = [];

  if (!dados.empresa_id) erros.push('Selecione a empresa.');
  if (!dados.obrigacao_id) erros.push('Selecione a obrigação.');
  if (!dados.competencia) {
    erros.push('Informe a competência (mês e ano).');
  } else if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(dados.competencia)) {
    erros.push('Competência inválida: use AAAA-MM (ex.: 2026-11).');
  }

  if (!dados.vencimento) {
    erros.push('Informe o vencimento.');
  } else if (!/^\d{4}-\d{2}-\d{2}$/.test(dados.vencimento)) {
    erros.push('Vencimento inválido: informe uma data válida.');
  }

  return erros;
}

async function cadastrarEntrega(evento) {
  evento.preventDefault();

  const formulario = evento.target;
  const dados = Object.fromEntries(new FormData(formulario));
  const erros = validarCadastro(dados);

  if (erros.length > 0) {
    mostrarAviso(erros.join(' '), 'erro');
    return;
  }

  const botao = document.getElementById('cadastro-salvar');
  botao.disabled = true;

  try {
    const resposta = await fetch(`${API}/entregas.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(dados),
    });
    const resultado = await resposta.json();

    if (!resposta.ok) {
      mostrarAviso(resultado.erro || 'Não foi possível cadastrar a entrega.', 'erro');
      return;
    }

    formulario.reset();
    mostrarAviso(resultado.mensagem || 'Entrega cadastrada com sucesso.');
    carregarResumo();
    carregarEntregas();
  } catch (erro) {
    mostrarAviso('Não foi possível conectar à API.', 'erro');
  } finally {
    botao.disabled = false;
  }
}

document.getElementById('form-cadastro').addEventListener('submit', cadastrarEntrega);

document.getElementById('form-filtros').addEventListener('submit', (evento) => {
  evento.preventDefault();
  carregarEntregas();
});

document.getElementById('limpar-filtros').addEventListener('click', () => {
  document.getElementById('form-filtros').reset();
  carregarEntregas();
});

// Campo "Data da entrega": começa em hoje e não deixa escolher data futura.
document.getElementById('data-entrega').value = dataPadrao;
atualizarCampoData();
window.addEventListener('focus', atualizarCampoData);

carregarResumo();
carregarEntregas();
carregarOpcoesCadastro();
