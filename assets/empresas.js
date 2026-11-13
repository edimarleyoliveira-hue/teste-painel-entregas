// Tela de Empresas: lista com filtros, cadastro e edição (com as obrigações de cada empresa).

const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE',
  'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

let empresaEmEdicao = null;      // id da empresa aberta no formulário (null = nova)
let catalogoObrigacoes = [];     // todas as obrigações, com a configuração padrão
let vinculosEmpresa = [];        // obrigações no formulário: { obrigacao_id, campos (recorrência), item (<li>) }

document.querySelectorAll('.select-uf').forEach((select) => {
  UFS.forEach((uf) => select.appendChild(new Option(uf, uf)));
});

// ---------- Lista ----------

function filtrosEmpresas() {
  const parametros = new URLSearchParams();
  for (const [nome, valor] of new FormData(document.getElementById('form-filtros-empresas'))) {
    if (valor.trim() !== '') {
      parametros.append(nome, valor.trim());
    }
  }
  return parametros;
}

async function carregarEmpresas() {
  const filtros = filtrosEmpresas().toString();
  const { ok, dados } = await requisicaoJson(`${API}/empresas.php${filtros ? `?${filtros}` : ''}`);
  const corpo = document.getElementById('lista-empresas');

  if (!ok) {
    mostrarAviso(dados.erro || 'Erro ao carregar as empresas.', 'erro');
    corpo.innerHTML = '<tr><td colspan="7" class="tabela__vazio">Não foi possível carregar as empresas.</td></tr>';
    return;
  }

  if (dados.length === 0) {
    const mensagem = filtros ? 'Nenhuma empresa encontrada para os filtros selecionados.' : 'Nenhuma empresa cadastrada.';
    corpo.innerHTML = `<tr><td colspan="7" class="tabela__vazio">${mensagem}</td></tr>`;
    return;
  }

  // Todo texto vindo do banco passa por escaparHtml; o id vai como número.
  corpo.innerHTML = dados.map((empresa) => `
    <tr>
      <td><strong>${escaparHtml(empresa.codigo)}</strong></td>
      <td>
        <span class="tabela__empresa">${escaparHtml(empresa.razao_social)}</span>
        <span class="tabela__cnpj">${escaparHtml(empresa.nome_fantasia || '')}</span>
      </td>
      <td>${escaparHtml(empresa.documento)} <span class="etiqueta">${escaparHtml(empresa.tipo_documento)}</span></td>
      <td>${escaparHtml(empresa.cidade)}/${escaparHtml(empresa.uf)}</td>
      <td>${formatarData(empresa.criado_em)}</td>
      <td>${Number(empresa.total_obrigacoes)}</td>
      <td><button type="button" class="botao botao--secundario" data-editar-empresa="${Number(empresa.id)}">Editar</button></td>
    </tr>
  `).join('');
}

document.getElementById('form-filtros-empresas').addEventListener('submit', (evento) => {
  evento.preventDefault();
  carregarEmpresas();
});

document.getElementById('limpar-filtros-empresas').addEventListener('click', () => {
  document.getElementById('form-filtros-empresas').reset();
  carregarEmpresas();
});

document.getElementById('lista-empresas').addEventListener('click', (evento) => {
  const botao = evento.target.closest('[data-editar-empresa]');
  if (botao) {
    abrirFormularioEmpresa(Number(botao.dataset.editarEmpresa));
  }
});

document.getElementById('nova-empresa').addEventListener('click', () => abrirFormularioEmpresa(null));

// ---------- Formulário ----------

const formEmpresa = document.getElementById('form-empresa');

function campoEmpresa(nome) {
  return formEmpresa.elements[nome];
}

function tipoDocumento() {
  return formEmpresa.querySelector('input[name="tipo_documento"]:checked').value;
}

// Ajusta rótulo, exemplo e tamanho do campo conforme CNPJ ou CPF e reaplica a máscara.
function atualizarCampoDocumento() {
  const tipo = tipoDocumento();
  const campo = campoEmpresa('documento');
  document.getElementById('rotulo-documento').textContent = tipo;
  campo.placeholder = EXEMPLOS_DOCUMENTO[tipo];
  campo.maxLength = MASCARAS[tipo].length;
  // CPF só tem números; CNPJ pode ter letras (alfanumérico).
  campo.inputMode = tipo === 'CPF' ? 'numeric' : 'text';
  campo.value = aplicarMascara(campo.value, MASCARAS[tipo]);
}

formEmpresa.querySelectorAll('input[name="tipo_documento"]').forEach((opcao) => {
  opcao.addEventListener('change', () => {
    campoEmpresa('documento').value = '';
    atualizarCampoDocumento();
    campoEmpresa('documento').focus();
  });
});

campoEmpresa('documento').addEventListener('input', (evento) => {
  evento.target.value = aplicarMascara(evento.target.value, MASCARAS[tipoDocumento()]);
});

campoEmpresa('cep').addEventListener('input', (evento) => {
  evento.target.value = aplicarMascara(evento.target.value, MASCARAS.CEP);
});

campoEmpresa('contato_telefone').addEventListener('input', (evento) => {
  evento.target.value = mascaraTelefone(evento.target.value);
});

function mostrarErroEmpresa(mensagem) {
  const erro = document.getElementById('erro-empresa');
  erro.textContent = mensagem;
  erro.hidden = !mensagem;
  if (mensagem) {
    erro.scrollIntoView({ block: 'nearest' });
  }
}

async function carregarCatalogoObrigacoes() {
  const { ok, dados } = await requisicaoJson(`${API}/obrigacoes.php`);
  if (!ok) {
    mostrarErroEmpresa(dados.erro || 'Erro ao carregar as obrigações.');
    return false;
  }
  catalogoObrigacoes = dados;
  preencherSelect(document.getElementById('empresa-obrigacao-nova'), dados, (o) => `${o.nome} (${o.departamento})`);
  return true;
}

// Acrescenta uma obrigação na lista do formulário, com os campos de recorrência preenchidos.
function adicionarVinculo(obrigacao, config) {
  const item = document.createElement('li');
  item.className = 'vinculo';

  const cabecalho = document.createElement('div');
  cabecalho.className = 'vinculo__cabecalho';
  const titulo = document.createElement('strong');
  titulo.textContent = `${obrigacao.nome} (${obrigacao.departamento})`;
  const remover = document.createElement('button');
  remover.type = 'button';
  remover.className = 'botao botao--secundario';
  remover.textContent = 'Retirar';
  cabecalho.append(titulo, remover);

  // Grupo com nome, para o leitor de tela saber de qual obrigação são os campos.
  const container = document.createElement('div');
  container.setAttribute('role', 'group');
  container.setAttribute('aria-label', `Regra da obrigação ${obrigacao.nome}`);
  item.append(cabecalho, container);
  document.getElementById('empresa-vinculos').appendChild(item);

  const vinculo = { obrigacao_id: Number(obrigacao.id ?? obrigacao.obrigacao_id), campos: criarCamposRecorrencia(container, config), item };
  vinculosEmpresa.push(vinculo);

  remover.addEventListener('click', () => {
    item.remove();
    vinculosEmpresa = vinculosEmpresa.filter((v) => v !== vinculo);
  });
}

document.getElementById('empresa-incluir-obrigacao').addEventListener('click', () => {
  const select = document.getElementById('empresa-obrigacao-nova');
  const obrigacao = catalogoObrigacoes.find((o) => o.id === Number(select.value));

  if (!obrigacao) {
    mostrarErroEmpresa('Escolha a obrigação para incluir.');
    return;
  }
  if (vinculosEmpresa.some((v) => v.obrigacao_id === obrigacao.id)) {
    mostrarErroEmpresa('Esta obrigação já está na empresa.');
    return;
  }

  mostrarErroEmpresa('');
  // Hierarquia: começa com o padrão da obrigação; o usuário pode ajustar só para esta empresa.
  adicionarVinculo(obrigacao, obrigacao);
  select.value = '';
});

// Conta as aberturas do formulário: se o usuário clicar de novo antes de terminar de carregar,
// só a última abertura continua (evita obrigações duplicadas ou dados da empresa errada).
let aberturaAtual = 0;

async function abrirFormularioEmpresa(id) {
  aberturaAtual += 1;
  const minhaAbertura = aberturaAtual;
  empresaEmEdicao = id;
  formEmpresa.reset();
  mostrarErroEmpresa('');
  document.getElementById('empresa-vinculos').textContent = '';
  vinculosEmpresa = [];
  document.getElementById('titulo-dialogo-empresa').textContent = id ? 'Editar empresa' : 'Nova empresa';

  const catalogoOk = await carregarCatalogoObrigacoes();
  if (minhaAbertura !== aberturaAtual) {
    return;
  }
  if (!catalogoOk) {
    // Sem a lista de obrigações não dá para editar com segurança: não abre o formulário.
    mostrarAviso('Não foi possível carregar as obrigações. Tente novamente.', 'erro');
    return;
  }

  if (id) {
    const { ok, dados } = await requisicaoJson(`${API}/empresas.php?id=${Number(id)}`);
    if (minhaAbertura !== aberturaAtual) {
      return;
    }
    if (!ok) {
      mostrarAviso(dados.erro || 'Erro ao carregar a empresa.', 'erro');
      return;
    }

    formEmpresa.querySelector(`input[name="tipo_documento"][value="${dados.tipo_documento === 'CPF' ? 'CPF' : 'CNPJ'}"]`).checked = true;
    ['codigo', 'documento', 'razao_social', 'nome_fantasia', 'regime', 'cep', 'logradouro', 'numero', 'complemento',
      'bairro', 'cidade', 'uf', 'contato_nome', 'contato_telefone', 'contato_email'].forEach((campo) => {
      campoEmpresa(campo).value = dados[campo] ?? '';
    });
    dados.obrigacoes.forEach((vinculo) => adicionarVinculo(vinculo, vinculo));
  }

  atualizarCampoDocumento();
  document.getElementById('dialogo-empresa').showModal();
}

// Validação na tela (ajuda); o servidor confere tudo de novo.
function validarFormularioEmpresa(dados) {
  const erros = [];
  if (!documentoValido(dados.tipo_documento, dados.documento)) erros.push(`${dados.tipo_documento} inválido: confira os números.`);
  if (!dados.razao_social.trim()) erros.push('Informe a razão social.');
  if (!dados.regime) erros.push('Escolha o regime tributário.');
  if (somenteDigitos(dados.cep).length !== 8) erros.push('CEP inválido.');
  ['logradouro', 'numero', 'bairro', 'cidade'].forEach((campo) => {
    if (!dados[campo].trim()) erros.push(`Preencha o campo ${{ logradouro: 'Rua', numero: 'Número', bairro: 'Bairro', cidade: 'Cidade' }[campo]}.`);
  });
  if (!dados.uf) erros.push('Escolha o estado.');
  if (dados.contato_email && !campoEmpresa('contato_email').checkValidity()) erros.push('E-mail do contato inválido.');
  return erros;
}

formEmpresa.addEventListener('submit', async (evento) => {
  evento.preventDefault();

  const dados = Object.fromEntries(new FormData(formEmpresa));
  dados.obrigacoes = vinculosEmpresa.map((v) => ({ obrigacao_id: v.obrigacao_id, ...v.campos.ler() }));

  mostrarErroEmpresa('');
  const erros = validarFormularioEmpresa(dados);
  if (erros.length > 0) {
    mostrarErroEmpresa(erros.join(' '));
    return;
  }

  const botao = document.getElementById('salvar-empresa');
  botao.disabled = true;
  const url = empresaEmEdicao ? `${API}/empresas.php?id=${Number(empresaEmEdicao)}` : `${API}/empresas.php`;
  const { ok, dados: resultado } = await requisicaoJson(url, empresaEmEdicao ? 'PUT' : 'POST', dados);
  botao.disabled = false;

  if (!ok) {
    mostrarErroEmpresa(resultado.erro || 'Não foi possível salvar a empresa.');
    return;
  }

  document.getElementById('dialogo-empresa').close();
  mostrarAviso(`${resultado.mensagem} ID: ${resultado.codigo}.`);
  // A empresa e as entregas geradas aparecem nas outras telas também.
  carregarEmpresas();
  carregarResumo();
  carregarEntregas();
  carregarOpcoesCadastro();
});
