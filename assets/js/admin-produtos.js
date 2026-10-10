/* =============================================================================
   Admin › Produtos (listagem por categoria): filtros sem recarregar, preço na
   linha, chave Na loja/Oculto, ordem (▲ ▼), duplicar e excluir com confirmação.
   Preço, chave, ordem e duplicar vão por fetch; se o servidor recusar, a tela
   volta ao que era e mostra "Não foi possível salvar. Tente de novo."
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-pr]');
    if (!raiz) { return; }
    var URL_POST = raiz.getAttribute('data-url');
    var CSRF = raiz.getAttribute('data-csrf');
    var campoQ = raiz.querySelector('[data-pr-q]');
    var campoCat = raiz.querySelector('[data-pr-cat]');
    var campoSt = raiz.querySelector('[data-pr-st]');
    var CHAVE_FILTROS = 'pr-filtros';

    var itens = function () { return Array.prototype.slice.call(raiz.querySelectorAll('[data-pr-item]')); };
    var nomeDe = function (item) { return item.getAttribute('data-nome'); };

    // --- Avisos (embaixo, no centro; somem sozinhos) -----------------------------
    var toast = raiz.querySelector('[data-pr-toast]');
    var toastT;
    var avisar = function (msg, erro) {
        toast.textContent = msg;
        toast.classList.toggle('is-erro', !!erro);
        toast.hidden = false;
        clearTimeout(toastT);
        toastT = setTimeout(function () { toast.hidden = true; }, 3000);
    };
    var FALHOU = 'Não foi possível salvar. Tente de novo.';

    // --- POST (fetch) ------------------------------------------------------------
    var postar = function (op, dados) {
        var corpo = new URLSearchParams();
        corpo.append('op', op);
        corpo.append('_csrf', CSRF);
        Object.keys(dados).forEach(function (k) { corpo.append(k, dados[k]); });
        return fetch(URL_POST, {
            method: 'POST', credentials: 'same-origin', body: corpo,
            headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false }; }).then(function (d) {
                if (!r.ok || !d || !d.ok) { throw new Error((d && d.erro) || 'erro'); }
                return d;
            });
        });
    };

    // --- Filtros -----------------------------------------------------------------
    var estado = function () {
        return { st: campoSt.value || 'loja', cat: campoCat.value, q: campoQ.value.trim() };
    };
    var querystring = function (e) {
        var p = new URLSearchParams();
        if (e.st && e.st !== 'loja') { p.set('st', e.st); }
        if (e.cat) { p.set('cat', e.cat); }
        if (e.q) { p.set('q', e.q); }
        return p.toString();
    };

    // Setas: ▲ desligada no primeiro da categoria, ▼ no último; todas com busca ativa.
    var atualizarSetas = function () {
        var busca = campoQ.value.trim() !== '';
        raiz.querySelectorAll('.pr-itens').forEach(function (lista) {
            var linhas = lista.querySelectorAll('[data-pr-item]');
            linhas.forEach(function (item, i) {
                var up = item.querySelector('[data-pr-mover="up"]');
                var down = item.querySelector('[data-pr-mover="down"]');
                up.disabled = busca || i === 0;
                down.disabled = busca || i === linhas.length - 1;
                [up, down].forEach(function (b) {
                    if (busca) { b.title = 'Limpe a busca para reordenar'; } else { b.removeAttribute('title'); }
                });
            });
        });
    };

    var aplicarFiltros = function () {
        var e = estado();
        var q = e.q.toLocaleLowerCase('pt-BR');
        var algum = false;
        raiz.querySelectorAll('[data-pr-grupo]').forEach(function (grupo) {
            var n = 0;
            grupo.querySelectorAll('[data-pr-item]').forEach(function (item) {
                var ativo = item.getAttribute('data-ativo') === '1';
                var ok = (e.st === 'todos' || ativo === (e.st === 'loja'))
                    && (!e.cat || item.getAttribute('data-cat') === e.cat)
                    && (!q || nomeDe(item).toLocaleLowerCase('pt-BR').indexOf(q) !== -1);
                item.hidden = !ok;
                if (ok) { n++; }
            });
            grupo.hidden = n === 0;
            grupo.querySelector('[data-pr-grupo-conta]').textContent = n + (n === 1 ? ' produto' : ' produtos');
            if (n) { algum = true; }
        });
        var nada = raiz.querySelector('[data-pr-nada]');
        if (nada) { nada.hidden = algum || itens().length === 0; }

        raiz.querySelectorAll('[data-pr-aba]').forEach(function (a) {
            var k = a.getAttribute('data-pr-aba');
            if (k === e.st) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
            var qs = querystring({ st: k, cat: e.cat, q: e.q });
            a.href = URL_POST + (qs ? '?' + qs : '');
        });
        atualizarSetas();

        var qs = querystring(e);
        try { history.replaceState(null, '', URL_POST + (qs ? '?' + qs : '')); } catch (err) {}
        try { sessionStorage.setItem(CHAVE_FILTROS, qs); } catch (err) {}
    };

    // Contadores das abas e o subtítulo "X na loja · Y ocultos".
    var recontar = function () {
        var todos = itens();
        var loja = todos.filter(function (i) { return i.getAttribute('data-ativo') === '1'; }).length;
        var ocultos = todos.length - loja;
        var conta = { loja: loja, ocultos: ocultos, todos: todos.length };
        raiz.querySelectorAll('[data-pr-conta]').forEach(function (s) { s.textContent = conta[s.getAttribute('data-pr-conta')]; });
        var sub = document.querySelector('.admin-subtitulo');
        if (sub) { sub.textContent = loja + ' na loja · ' + ocultos + (ocultos === 1 ? ' oculto' : ' ocultos'); }
    };

    // "Nome repetido": mesmo nome em outro produto, sem diferenciar maiúsculas.
    var marcarRepetidos = function () {
        var conta = {};
        itens().forEach(function (i) { var k = i.getAttribute('data-chave'); conta[k] = (conta[k] || 0) + 1; });
        itens().forEach(function (i) { i.querySelector('[data-pr-repetido]').hidden = conta[i.getAttribute('data-chave')] < 2; });
    };

    raiz.querySelectorAll('[data-pr-aba]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            campoSt.value = a.getAttribute('data-pr-aba');
            aplicarFiltros();
        });
    });
    campoQ.addEventListener('input', aplicarFiltros);
    campoCat.addEventListener('change', aplicarFiltros);
    raiz.querySelector('[data-pr-filtros]').addEventListener('submit', function (ev) { ev.preventDefault(); aplicarFiltros(); });

    // Voltando da edição (link "Voltar para produtos" sem filtros): restaura os filtros da sessão.
    if (!location.search) {
        try {
            var salvo = new URLSearchParams(sessionStorage.getItem(CHAVE_FILTROS) || '');
            campoSt.value = ['loja', 'ocultos', 'todos'].indexOf(salvo.get('st')) !== -1 ? salvo.get('st') : 'loja';
            if (salvo.get('cat') && campoCat.querySelector('option[value="' + salvo.get('cat').replace(/[^\w]/g, '') + '"]')) {
                campoCat.value = salvo.get('cat');
            }
            campoQ.value = salvo.get('q') || '';
        } catch (err) {}
    }
    marcarRepetidos();
    aplicarFiltros();

    // --- Menu "⋯" ------------------------------------------------------------------
    var fecharMenus = function (exceto) {
        raiz.querySelectorAll('[data-pr-menu]').forEach(function (b) {
            if (b === exceto) { return; }
            b.setAttribute('aria-expanded', 'false');
            b.nextElementSibling.hidden = true;
        });
    };
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest('.pr-acoes')) { fecharMenus(null); }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') { return; }
        var aberto = raiz.querySelector('[data-pr-menu][aria-expanded="true"]');
        if (aberto) { fecharMenus(null); aberto.focus(); return; }
        fecharConfirmacao();
    });

    // --- Ocultar / mostrar ---------------------------------------------------------
    var pintarAtivo = function (item, ativo) {
        item.setAttribute('data-ativo', ativo ? '1' : '0');
        item.classList.toggle('is-oculto', !ativo);
        var sw = item.querySelector('[data-pr-ativo]');
        sw.setAttribute('aria-checked', ativo ? 'true' : 'false');
        sw.querySelector('[data-pr-sw-txt]').textContent = ativo ? 'Na loja' : 'Oculto';
        item.querySelector('[data-pr-alternar]').textContent = ativo ? 'Ocultar da loja' : 'Mostrar na loja';
    };
    var alternar = function (item) {
        var antes = item.getAttribute('data-ativo') === '1';
        pintarAtivo(item, !antes);
        recontar();
        return postar('lista_ativo', { id: item.getAttribute('data-id'), ativo: antes ? '0' : '1' })
            .then(function () {
                avisar('“' + nomeDe(item) + '” ' + (antes ? 'foi ocultado da loja.' : 'voltou para a loja.'));
                aplicarFiltros();
            })
            .catch(function () {
                pintarAtivo(item, antes);
                recontar();
                avisar(FALHOU, true);
            });
    };

    // --- Ordem (▲ ▼) ---------------------------------------------------------------
    var trocar = function (item, dir) {
        var vizinho = dir === 'up' ? item.previousElementSibling : item.nextElementSibling;
        if (!vizinho) { return; }
        if (dir === 'up') { item.parentNode.insertBefore(item, vizinho); } else { item.parentNode.insertBefore(vizinho, item); }
    };
    var mover = function (item, dir, botao) {
        trocar(item, dir);
        atualizarSetas();
        if (!botao.disabled) { botao.focus(); } else { item.querySelector('[data-pr-mover]:not(:disabled)') && item.querySelector('[data-pr-mover]:not(:disabled)').focus(); }
        postar('lista_mover', { id: item.getAttribute('data-id'), dir: dir })
            .then(function () { avisar('Ordem atualizada na loja.'); })
            .catch(function () {
                trocar(item, dir === 'up' ? 'down' : 'up');
                atualizarSetas();
                avisar(FALHOU, true);
            });
    };

    // --- Preço na linha ------------------------------------------------------------
    var PRECO_OK = /^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/;
    var paraCentavos = function (txt) {
        txt = txt.trim();
        if (!PRECO_OK.test(txt)) { return 0; }
        var n = txt.indexOf(',') !== -1 ? txt.replace(/\./g, '').replace(',', '.') : txt;
        return Math.round(parseFloat(n) * 100);
    };
    var salvarPreco = function (campo) {
        var item = campo.closest('[data-pr-item]');
        var antes = campo.getAttribute('data-antes');
        if (antes === null) { return; }
        campo.removeAttribute('data-antes');
        var txt = campo.value.trim();
        if (txt === antes) { return; }
        var c = paraCentavos(txt);
        if (c <= 0 || c > 99999999) { campo.value = antes; return; }   // inválido: volta, sem salvar
        postar('lista_preco', { id: item.getAttribute('data-id'), preco: txt })
            .then(function (d) {
                campo.value = d.valor;
                campo.placeholder = '';
                avisar('Preço de “' + nomeDe(item) + '” atualizado para ' + d.texto + '.');
            })
            .catch(function () { campo.value = antes; avisar(FALHOU, true); });
    };
    raiz.addEventListener('focusin', function (ev) {
        var campo = ev.target.closest('[data-pr-preco]');
        if (campo) { campo.setAttribute('data-antes', campo.value.trim()); }
    });
    raiz.addEventListener('focusout', function (ev) {
        var campo = ev.target.closest('[data-pr-preco]');
        if (campo) { salvarPreco(campo); }
    });
    raiz.addEventListener('keydown', function (ev) {
        var campo = ev.target.closest('[data-pr-preco]');
        if (!campo) { return; }
        if (ev.key === 'Enter') { ev.preventDefault(); campo.blur(); }
        if (ev.key === 'Escape') { campo.value = campo.getAttribute('data-antes') || campo.value; campo.blur(); }
    });

    // --- Duplicar ------------------------------------------------------------------
    var duplicar = function (item, botao) {
        botao.disabled = true;
        postar('lista_duplicar', { id: item.getAttribute('data-id') })
            .then(function (d) {
                var tmp = document.createElement('div');
                tmp.innerHTML = d.html.trim();
                var nova = tmp.firstElementChild;
                item.parentNode.insertBefore(nova, item.nextElementSibling);
                marcarRepetidos();
                recontar();
                aplicarFiltros();
                avisar('Cópia criada como oculta. Edite e mostre na loja quando estiver pronta.');
            })
            .catch(function () { avisar(FALHOU, true); })
            .then(function () { botao.disabled = false; });
    };

    // --- Excluir (faixa de confirmação no topo) ------------------------------------
    var caixa = raiz.querySelector('[data-pr-confirmar]');
    var fecharConfirmacao = function () { caixa.textContent = ''; };
    var confirmarExclusao = function (item) {
        var nome = nomeDe(item);
        var cat = item.getAttribute('data-cat-nome');
        var pedidos = parseInt(item.getAttribute('data-pedidos'), 10) || 0;
        var ativo = item.getAttribute('data-ativo') === '1';
        caixa.textContent = '';

        var faixa = document.createElement('div');
        faixa.className = 'pr-confirmar';
        faixa.setAttribute('role', 'alertdialog');
        faixa.setAttribute('aria-label', 'Confirmar exclusão');
        var texto = document.createElement('span');
        texto.textContent = 'Excluir “' + nome + '” (' + cat + ')? ' + (pedidos
            ? 'Ele aparece em ' + pedidos + (pedidos === 1 ? ' pedido' : ' pedidos') + '; os pedidos continuam, mas o produto some do cadastro.'
            : 'Essa ação não pode ser desfeita.');
        faixa.appendChild(texto);

        var form = document.createElement('form');
        form.method = 'post';
        form.action = URL_POST;
        [['_csrf', CSRF], ['op', 'excluir'], ['id', item.getAttribute('data-id')], ['voltar', querystring(estado())]].forEach(function (c) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = c[0]; i.value = c[1];
            form.appendChild(i);
        });
        var botao = function (rotulo, classe, tipo) {
            var b = document.createElement('button');
            b.type = tipo || 'button';
            b.className = 'ap-btn ' + classe;
            b.textContent = rotulo;
            return b;
        };
        if (pedidos && ativo) {
            var ocultar = botao('Ocultar em vez disso', 'ap-btn-perigo');
            ocultar.addEventListener('click', function () { fecharConfirmacao(); alternar(item); });
            form.appendChild(ocultar);
        }
        form.appendChild(botao('Excluir', 'ap-btn-perigo-cheio', 'submit'));
        var cancelar = botao('Cancelar', 'ap-btn-perigo');
        cancelar.addEventListener('click', function () {
            fecharConfirmacao();
            var k = item.querySelector('[data-pr-menu]');
            if (k) { k.focus(); }
        });
        form.appendChild(cancelar);
        faixa.appendChild(form);
        caixa.appendChild(faixa);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        form.querySelector('button').focus({ preventScroll: true });
    };

    // --- Cliques nas linhas --------------------------------------------------------
    raiz.addEventListener('click', function (ev) {
        var item = ev.target.closest('[data-pr-item]');
        if (!item) { return; }
        var alvo;
        if ((alvo = ev.target.closest('[data-pr-mover]'))) {
            if (!alvo.disabled) { mover(item, alvo.getAttribute('data-pr-mover'), alvo); }
            return;
        }
        if (ev.target.closest('[data-pr-ativo]')) { alternar(item); return; }
        if ((alvo = ev.target.closest('[data-pr-menu]'))) {
            var abrir = alvo.getAttribute('aria-expanded') !== 'true';
            fecharMenus(alvo);
            alvo.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            alvo.nextElementSibling.hidden = !abrir;
            if (abrir) { alvo.nextElementSibling.querySelector('button').focus(); }
            return;
        }
        if ((alvo = ev.target.closest('[data-pr-duplicar]'))) { fecharMenus(null); duplicar(item, alvo); return; }
        if (ev.target.closest('[data-pr-alternar]')) { fecharMenus(null); alternar(item); return; }
        if (ev.target.closest('[data-pr-excluir]')) { fecharMenus(null); confirmarExclusao(item); }
    });
})();
