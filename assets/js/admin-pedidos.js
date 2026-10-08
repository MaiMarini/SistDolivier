/* =============================================================================
   Admin › Pedidos: modo Lista | Quadro, filtros automáticos, painel lateral do
   pedido, ações (avançar/voltar/cancelar/estornar) e aviso com "Desfazer".
   Sem JS a página funciona: links abrem /admin/pedidos/{id} e os formulários postam.
   ============================================================================= */
(function () {
    'use strict';
    var raiz = document.querySelector('[data-ap]');
    if (!raiz) { return; }

    var base = raiz.getAttribute('data-url-painel');          // .../admin/pedidos
    var voltar = raiz.getAttribute('data-voltar') || '';       // filtros atuais
    var csrf = raiz.getAttribute('data-csrf');
    var drawer = raiz.querySelector('[data-ap-drawer]');
    var corpo = raiz.querySelector('[data-ap-drawer-corpo]');
    var scrim = raiz.querySelector('[data-ap-scrim]');
    var toastEl = raiz.querySelector('[data-ap-toast]');
    var aberto = 0;
    var focoAntes = null;
    var guardar = function (k, v) { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
    var ler = function (k) {
        try { var v = sessionStorage.getItem(k); sessionStorage.removeItem(k); return v ? JSON.parse(v) : null; }
        catch (e) { return null; }
    };

    // --- Lista | Quadro ------------------------------------------------------
    var aplicarModo = function (modo) {
        raiz.setAttribute('data-modo', modo);
        document.querySelectorAll('[data-ap-modo]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-ap-modo') === modo ? 'true' : 'false');
        });
    };
    aplicarModo(raiz.getAttribute('data-modo') === 'quadro' ? 'quadro' : 'lista');
    document.querySelectorAll('[data-ap-modo]').forEach(function (b) {
        b.addEventListener('click', function () {
            var m = b.getAttribute('data-ap-modo');
            aplicarModo(m);
            try { localStorage.setItem('ap-modo', m); } catch (e) {}
        });
    });

    // --- Abas numa linha só: se não couberem, viram o select ----------------
    var abas = raiz.querySelector('.ap-abas');
    var medirAbas = function () {
        if (!abas) { return; }
        raiz.classList.remove('is-abas-apertadas');
        if (raiz.getAttribute('data-modo') === 'quadro' || !abas.offsetParent) { return; }
        if (abas.scrollWidth > abas.clientWidth + 1) { raiz.classList.add('is-abas-apertadas'); }
    };
    medirAbas();
    window.addEventListener('resize', medirAbas);
    document.querySelectorAll('[data-ap-modo]').forEach(function (b) {
        b.addEventListener('click', function () { setTimeout(medirAbas, 0); });
    });

    // --- Filtros: aplicam ao mudar (sem botão "Filtrar") ---------------------
    var filtros = raiz.querySelector('[data-ap-filtros]');
    var enviarFiltros = function () {
        if (filtros.requestSubmit) { filtros.requestSubmit(); } else { filtros.submit(); }
    };
    raiz.querySelectorAll('[data-ap-auto]').forEach(function (el) {
        el.addEventListener('change', enviarFiltros);
    });
    var busca = raiz.querySelector('[data-ap-busca]');
    if (busca) {
        var tBusca = null;
        busca.addEventListener('input', function () {
            clearTimeout(tBusca);
            tBusca = setTimeout(function () { guardar('ap-busca-foco', 1); enviarFiltros(); }, 600);
        });
        if (ler('ap-busca-foco')) {           // continua digitando depois de recarregar
            busca.focus();
            var n = busca.value.length;
            try { busca.setSelectionRange(n, n); } catch (e) {}
        }
    }

    // --- Aviso (toast) com "Desfazer" ------------------------------------------
    var tToast = null;
    var mostrarAviso = function (msg, desfazer) {
        toastEl.innerHTML = '';
        var span = document.createElement('span');
        span.textContent = msg;
        toastEl.appendChild(span);
        if (desfazer) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = 'Desfazer';
            b.addEventListener('click', function () {
                b.disabled = true;
                var fd = new FormData();
                fd.append('_csrf', csrf);
                fd.append('op', 'etapa');
                fd.append('id', desfazer.id);
                fd.append('de', desfazer.de);
                fd.append('para', desfazer.para);
                fd.append('desfazer', '1');
                postar(fd, desfazer.reabrir || 0);
            });
            toastEl.appendChild(b);
        }
        toastEl.hidden = false;
        clearTimeout(tToast);
        tToast = setTimeout(function () { toastEl.hidden = true; }, 5000);
    };

    var recarregar = function (abrirId) {
        var qs = voltar + (abrirId ? (voltar ? '&' : '') + 'abrir=' + abrirId : '');
        window.location.href = base + (qs ? '?' + qs : '');
    };

    // Envia uma ação. Sucesso ou conflito: recarrega (dados sempre atuais) e mostra o aviso.
    var postar = function (fd, reabrir, botao) {
        if (botao) { botao.disabled = true; }
        fetch(base, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var op = fd.get('op');
                if (d.ok || d.conflito || op === 'cancelar' || op === 'estornar') {
                    var desf = d.desfazer ? { id: d.desfazer.id, de: d.desfazer.de, para: d.desfazer.para, reabrir: reabrir } : null;
                    guardar('ap-toast', { msg: d.mensagem, desfazer: desf, erro: !d.ok });
                    recarregar(reabrir);
                    return;
                }
                mostrarAviso(d.mensagem || 'Não foi possível concluir.');
                if (botao) { botao.disabled = false; }
            })
            .catch(function () {
                mostrarAviso('Sem conexão com o servidor. Tente de novo.');
                if (botao) { botao.disabled = false; }
            });
    };

    raiz.addEventListener('submit', function (ev) {
        var form = ev.target.closest('form[data-ap-acao]');
        if (!form) { return; }
        ev.preventDefault();
        var dentroDoPainel = drawer.contains(form);
        postar(new FormData(form), dentroDoPainel ? parseInt(form.getAttribute('data-reabrir'), 10) : 0,
            form.querySelector('[type="submit"]'));
    });

    // --- Painel lateral --------------------------------------------------------
    var marcarUrl = function (id) {
        try {
            var u = new URL(window.location.href);
            if (id) { u.searchParams.set('abrir', id); } else { u.searchParams.delete('abrir'); }
            window.history.replaceState(null, '', u.toString());
        } catch (e) {}
    };
    var fechar = function () {
        if (!aberto) { return; }
        drawer.hidden = true;
        scrim.hidden = true;
        document.body.classList.remove('ap-travado');
        aberto = 0;
        marcarUrl(0);
        if (focoAntes && focoAntes.focus) { focoAntes.focus(); }
    };
    var abrir = function (id) {
        focoAntes = document.activeElement;
        aberto = id;
        corpo.innerHTML = '<p class="ap-vazio">Carregando…</p>';
        drawer.hidden = false;
        scrim.hidden = false;
        document.body.classList.add('ap-travado');
        marcarUrl(id);
        fetch(base + '/' + id + '?parcial=1&voltar=' + encodeURIComponent(voltar), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (aberto !== id) { return; }
                corpo.innerHTML = html;
                var t = corpo.querySelector('#ap-painel-titulo');
                if (t) { t.focus(); }
            })
            .catch(function () { corpo.innerHTML = '<p class="ap-vazio">Não foi possível carregar o pedido.</p>'; });
    };

    raiz.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-ap-abrir]');
        if (link && !ev.ctrlKey && !ev.metaKey && !ev.shiftKey) {
            ev.preventDefault();
            abrir(parseInt(link.getAttribute('data-ap-abrir'), 10));
            return;
        }
        if (ev.target.closest('[data-ap-fechar]')) { fechar(); return; }
        // Clique na linha da tabela (fora de botões e links) também abre.
        var tr = ev.target.closest('tr[data-ap-linha]');
        if (tr && !ev.target.closest('a, button, form, input, select, textarea')) {
            abrir(parseInt(tr.getAttribute('data-ap-linha'), 10));
        }
    });

    // Fundo: fecha só se o clique começou e terminou nele.
    var apertouNoFundo = false;
    scrim.addEventListener('pointerdown', function () { apertouNoFundo = true; });
    scrim.addEventListener('click', function () { if (apertouNoFundo) { fechar(); } apertouNoFundo = false; });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && aberto) { fechar(); }
    });

    // --- Estado inicial ---------------------------------------------------------
    var abrirId = parseInt(raiz.getAttribute('data-abrir'), 10);
    if (abrirId > 0) { abrir(abrirId); }
    var aviso = ler('ap-toast');
    if (aviso) { mostrarAviso(aviso.msg, aviso.desfazer); }
})();
