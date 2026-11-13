// Campos de recorrência reaproveitados no cadastro de obrigação (padrão)
// e em cada obrigação dentro do cadastro de empresa (regra da empresa).

const TIPOS_RECORRENCIA = {
  diaria: 'Diária',
  semanal: 'Semanal',
  mensal: 'Mensal',
  trimestral: 'Trimestral',
  semestral: 'Semestral',
  anual: 'Anual',
};

const DIAS_SEMANA = ['', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'];

const AJUSTES_VENCIMENTO = {
  postergar: 'Adiar para o próximo dia útil',
  antecipar: 'Antecipar para o dia útil anterior',
  manter: 'Manter a data',
};

// Texto curto para mostrar em tabelas. Ex.: "Mensal, dia 20".
function descreverRecorrencia(config) {
  switch (config.recorrencia) {
    case 'diaria':
      return Number(config.somente_dias_uteis) ? 'Diária (só dias úteis)' : 'Diária (todos os dias)';
    case 'semanal':
      return `Semanal, ${(DIAS_SEMANA[config.dia_semana] || '').toLowerCase()}`;
    case 'mensal':
      return `Mensal, dia ${config.dia_mes}`;
    default:
      return `${TIPOS_RECORRENCIA[config.recorrencia] || ''}, a partir de ${formatarData(config.data_inicio)}`;
  }
}

// Cria um <label class="campo"> com o texto e o elemento dentro.
function criarCampoRotulado(texto, elemento) {
  const rotulo = document.createElement('label');
  rotulo.className = 'campo';
  rotulo.append(texto, elemento);
  return rotulo;
}

function criarSelect(opcoes) {
  const select = document.createElement('select');
  Object.entries(opcoes).forEach(([valor, texto]) => select.appendChild(new Option(texto, valor)));
  return select;
}

/**
 * Monta os campos dentro de "container" já preenchidos com "config".
 * Devolve um objeto com ler(), que devolve a configuração atual dos campos.
 */
function criarCamposRecorrencia(container, config = {}) {
  container.textContent = '';
  container.classList.add('recorrencia');

  const tipo = criarSelect(TIPOS_RECORRENCIA);
  tipo.value = config.recorrencia || 'mensal';

  const somenteUteis = document.createElement('input');
  somenteUteis.type = 'checkbox';
  somenteUteis.checked = config.somente_dias_uteis === undefined ? true : Boolean(Number(config.somente_dias_uteis));
  const rotuloUteis = document.createElement('label');
  rotuloUteis.className = 'campo campo--caixa';
  rotuloUteis.append(somenteUteis, ' Somente dias úteis');

  const diasSemana = {};
  DIAS_SEMANA.forEach((nome, numero) => { if (numero > 0) diasSemana[numero] = nome; });
  const diaSemana = criarSelect(diasSemana);
  diaSemana.value = config.dia_semana || '1';

  const diaMes = document.createElement('input');
  diaMes.type = 'number';
  diaMes.min = '1';
  diaMes.max = '31';
  diaMes.value = config.dia_mes || '';

  const dataInicio = document.createElement('input');
  dataInicio.type = 'date';
  dataInicio.value = config.data_inicio || '';

  const ajuste = criarSelect(AJUSTES_VENCIMENTO);
  ajuste.value = config.ajuste_vencimento || 'postergar';

  const campos = {
    tipo: criarCampoRotulado('Recorrência', tipo),
    uteis: rotuloUteis,
    diaSemana: criarCampoRotulado('Dia da semana', diaSemana),
    diaMes: criarCampoRotulado('Dia do vencimento', diaMes),
    dataInicio: criarCampoRotulado('Primeira cobrança', dataInicio),
    ajuste: criarCampoRotulado('Se cair em fim de semana ou feriado', ajuste),
  };
  Object.values(campos).forEach((campo) => container.appendChild(campo));

  // Mostra só os campos que fazem sentido para o tipo escolhido.
  function atualizarVisibilidade() {
    const valor = tipo.value;
    campos.uteis.hidden = valor !== 'diaria';
    campos.diaSemana.hidden = valor !== 'semanal';
    campos.diaMes.hidden = valor !== 'mensal';
    campos.dataInicio.hidden = !['trimestral', 'semestral', 'anual'].includes(valor);
    campos.ajuste.hidden = valor === 'diaria';
  }
  tipo.addEventListener('change', atualizarVisibilidade);
  atualizarVisibilidade();

  return {
    ler() {
      return {
        recorrencia: tipo.value,
        somente_dias_uteis: somenteUteis.checked,
        dia_semana: diaSemana.value,
        dia_mes: diaMes.value,
        data_inicio: dataInicio.value,
        ajuste_vencimento: ajuste.value,
      };
    },
  };
}
