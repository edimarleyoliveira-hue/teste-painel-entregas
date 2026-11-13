// Menu lateral: mostra uma tela por vez conforme o endereço (#entregas, #empresas, #obrigacoes).

const TELAS = {
  entregas: null,
  empresas: carregarEmpresas,
  obrigacoes: carregarObrigacoes,
};

function mostrarTela() {
  const nome = location.hash.replace('#', '');
  const tela = Object.hasOwn(TELAS, nome) ? nome : 'entregas';

  Object.keys(TELAS).forEach((chave) => {
    document.getElementById(`tela-${chave}`).hidden = chave !== tela;
  });

  document.querySelectorAll('.menu__item').forEach((item) => {
    if (item.dataset.tela === tela) {
      item.setAttribute('aria-current', 'page');
    } else {
      item.removeAttribute('aria-current');
    }
  });

  // Recarrega a lista ao entrar na tela, para mostrar dados atualizados.
  if (TELAS[tela]) {
    TELAS[tela]();
  }
}

window.addEventListener('hashchange', mostrarTela);
mostrarTela();

// Botões "Cancelar" fecham o formulário (dialog) em que estão.
document.querySelectorAll('[data-fechar]').forEach((botao) => {
  botao.addEventListener('click', () => botao.closest('dialog').close());
});
