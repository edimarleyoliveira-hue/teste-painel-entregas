// Máscaras de preenchimento e validação de CPF/CNPJ na tela.
// O servidor valida de novo; aqui é só para ajudar quem está digitando.

// No padrão: "0" = só dígito; "A" = letra ou dígito (CNPJ alfanumérico); o resto é fixo.
const MASCARAS = {
  CNPJ: 'AA.AAA.AAA/AAAA-00',
  CPF: '000.000.000-00',
  CEP: '00000-000',
};

// Exemplo mostrado dentro do campo vazio.
const EXEMPLOS_DOCUMENTO = {
  CNPJ: '00.000.000/0000-00',
  CPF: '000.000.000-00',
};

function somenteDigitos(valor) {
  return String(valor ?? '').replace(/\D/g, '');
}

// Encaixa o que foi digitado no padrão, ignorando o que não cabe na posição (ex.: letra onde só vai dígito).
function aplicarMascara(valor, padrao) {
  const caracteres = String(valor ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  let resultado = '';
  let posicao = 0;

  for (const caractere of padrao) {
    if (caractere !== '0' && caractere !== 'A') {
      // Caractere fixo (. / - etc.): só entra se ainda houver algo para encaixar depois dele.
      if (posicao < caracteres.length) {
        resultado += caractere;
      }
      continue;
    }

    while (caractere === '0' && posicao < caracteres.length && !/\d/.test(caracteres[posicao])) {
      posicao += 1;
    }
    if (posicao >= caracteres.length) {
      break;
    }
    resultado += caracteres[posicao];
    posicao += 1;
  }

  // Tira separador que sobrou no fim (ex.: "12.345.678/9012-" quando o resto não cabia).
  return resultado.replace(/[^A-Z0-9]+$/, '');
}

// Telefone: fixo (00) 0000-0000 ou celular (00) 00000-0000, conforme a quantidade de dígitos.
function mascaraTelefone(valor) {
  const digitos = somenteDigitos(valor).slice(0, 11);
  return aplicarMascara(digitos, digitos.length > 10 ? '(00) 00000-0000' : '(00) 0000-0000');
}

function cpfValido(valor) {
  const n = somenteDigitos(valor);
  if (n.length !== 11 || /^(\d)\1{10}$/.test(n)) {
    return false;
  }

  for (let posicao = 9; posicao < 11; posicao += 1) {
    let soma = 0;
    for (let i = 0; i < posicao; i += 1) {
      soma += Number(n[i]) * (posicao + 1 - i);
    }
    if (((soma * 10) % 11) % 10 !== Number(n[posicao])) {
      return false;
    }
  }
  return true;
}

// CNPJ numérico ou alfanumérico: cada posição vale o código do caractere - 48 ("A" = 17).
function cnpjValido(valor) {
  const n = String(valor ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (!/^[A-Z0-9]{12}\d{2}$/.test(n) || /^(.)\1{13}$/.test(n)) {
    return false;
  }

  const pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  for (let posicao = 12; posicao < 14; posicao += 1) {
    const inicioPesos = posicao === 12 ? 1 : 0;
    let soma = 0;
    for (let i = 0; i < posicao; i += 1) {
      soma += (n.charCodeAt(i) - 48) * pesos[i + inicioPesos];
    }
    const resto = soma % 11;
    if ((resto < 2 ? 0 : 11 - resto) !== Number(n[posicao])) {
      return false;
    }
  }
  return true;
}

function documentoValido(tipo, valor) {
  return tipo === 'CPF' ? cpfValido(valor) : cnpjValido(valor);
}

// Campos com a classe "maiusculo" aceitam só letras e números, sempre em maiúsculas.
document.addEventListener('input', (evento) => {
  const campo = evento.target;
  if (campo.classList && campo.classList.contains('maiusculo')) {
    campo.value = campo.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  }
});
