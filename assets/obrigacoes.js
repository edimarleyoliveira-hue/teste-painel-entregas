// Tela de Obrigações: lista, cadastro e edição da configuração padrão.

let obrigacoesCadastradas = [];
let obrigacaoEmEdicao = null;   // id da obrigação aberta no formulário (null = nova)
let camposRecorrenciaObrigacao = null;

const formObrigacao = document.getElementById('form-obrigacao');

function filtrosObrigacoes() {
  const parametros = new URLSearchParams();
  for (const [nome, valor] of new FormData(document.getElementById('form-filtros-obrigacoes'))) {
    if (valor.trim() !== '') {
      parametros.append(nome, valor.trim());
    }
  }
  return parametros;
}

async function carregarObrigacoes() {
  const filtros = filtrosObrigacoes().toString();
  const { ok, dados } = await requisicaoJson(`${API}/obrigacoes.php${filtros ? `?${filtros}` : ''}`);
  const corpo = document.getElementById('lista-obrigacoes');

  if (!ok) {
    mostrarAviso(dados.erro || 'Erro ao carregar as obrigações.', 'erro');
    corpo.innerHTML = '<tr><td colspan="6" class="tabela__vazio">Não foi possível carregar as obrigações.</td></tr>';
    return;
  }

  obrigacoesCadastradas = dados;

  if (dados.length === 0) {
    const mensagem = filtros ? 'Nenhuma obrigação encontrada para os filtros selecionados.' : 'Nenhuma obrigação cadastrada.';
    corpo.innerHTML = `<tr><td colspan="6" class="tabela__vazio">${mensagem}</td></tr>`;
    return;
  }

  corpo.innerHTML = dados.map((obrigacao) => `
    <tr>
      <td><span class="tabela__empresa">${escaparHtml(obrigacao.nome)}</span></td>
      <td>${escaparHtml(obrigacao.departamento)}</td>
      <td>${escaparHtml(descreverRecorrencia(obrigacao))}</td>
      <td>${obrigacao.recorrencia === 'diaria' ? '—' : escaparHtml(AJUSTES_VENCIMENTO[obrigacao.ajuste_vencimento])}</td>
      <td>${Number(obrigacao.total_empresas)}</td>
      <td><button type="button" class="botao botao--secundario" data-editar-obrigacao="${Number(obrigacao.id)}">Editar</button></td>
    </tr>
  `).join('');
}

document.getElementById('form-filtros-obrigacoes').addEventListener('submit', (evento) => {
  evento.preventDefault();
  carregarObrigacoes();
});

document.getElementById('limpar-filtros-obrigacoes').addEventListener('click', () => {
  document.getElementById('form-filtros-obrigacoes').reset();
  carregarObrigacoes();
});

function mostrarErroObrigacao(mensagem) {
  const erro = document.getElementById('erro-obrigacao');
  erro.textContent = mensagem;
  erro.hidden = !mensagem;
}

function abrirFormularioObrigacao(id) {
  const obrigacao = obrigacoesCadastradas.find((o) => o.id === id) || null;
  obrigacaoEmEdicao = obrigacao ? obrigacao.id : null;

  formObrigacao.reset();
  mostrarErroObrigacao('');
  document.getElementById('titulo-dialogo-obrigacao').textContent = obrigacao ? 'Editar obrigação' : 'Nova obrigação';
  document.getElementById('ajuda-edicao-obrigacao').hidden = !obrigacao;

  formObrigacao.elements.nome.value = obrigacao ? obrigacao.nome : '';
  formObrigacao.elements.departamento.value = obrigacao ? obrigacao.departamento : '';
  camposRecorrenciaObrigacao = criarCamposRecorrencia(document.getElementById('obrigacao-recorrencia'), obrigacao || {});

  document.getElementById('dialogo-obrigacao').showModal();
}

document.getElementById('nova-obrigacao').addEventListener('click', () => abrirFormularioObrigacao(null));

document.getElementById('lista-obrigacoes').addEventListener('click', (evento) => {
  const editar = evento.target.closest('[data-editar-obrigacao]');
  if (editar) {
    abrirFormularioObrigacao(Number(editar.dataset.editarObrigacao));
  }
});

formObrigacao.addEventListener('submit', async (evento) => {
  evento.preventDefault();

  const dados = {
    nome: formObrigacao.elements.nome.value,
    departamento: formObrigacao.elements.departamento.value,
    ...camposRecorrenciaObrigacao.ler(),
  };

  if (!dados.nome.trim() || !dados.departamento) {
    mostrarErroObrigacao('Informe o nome e o departamento.');
    return;
  }

  const botao = document.getElementById('salvar-obrigacao');
  botao.disabled = true;
  const url = obrigacaoEmEdicao ? `${API}/obrigacoes.php?id=${Number(obrigacaoEmEdicao)}` : `${API}/obrigacoes.php`;
  const { ok, dados: resultado } = await requisicaoJson(url, obrigacaoEmEdicao ? 'PUT' : 'POST', dados);
  botao.disabled = false;

  if (!ok) {
    mostrarErroObrigacao(resultado.erro || 'Não foi possível salvar a obrigação.');
    return;
  }

  document.getElementById('dialogo-obrigacao').close();
  mostrarAviso(resultado.mensagem);
  carregarObrigacoes();
  carregarOpcoesCadastro();
  carregarEntregas(); // o nome da obrigação aparece na lista de entregas
});
