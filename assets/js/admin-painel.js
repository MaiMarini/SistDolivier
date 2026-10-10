/* =============================================================================
   Admin › Painel: gráfico de vendas (SVG puro) e seletor Hoje | 7 dias | 14 dias.
   Sem JS a página funciona: os botões de período são links (?per=) e os
   números por dia estão na tabela "Ver em tabela".
   ============================================================================= */
(function () {
    'use strict';

    var card = document.querySelector('[data-pn-vendas]');
    if (!card) { return; }
    var caixa = card.querySelector('[data-pn-grafico]');
    var dados = [];
    try { dados = JSON.parse(card.querySelector('[data-pn-dados]').textContent) || []; } catch (e) { dados = []; }
    var DIAS = { hoje: 1, '7': 7, '14': 14 };
    var per = DIAS[card.getAttribute('data-per')] ? card.getAttribute('data-per') : '7';
    var NS = 'http://www.w3.org/2000/svg';

    var brl = function (centavos) {
        return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    };

    // Passo "redondo" do eixo (1, 2, 2,5 ou 5 × 10^n), com no máximo 3 faixas acima do zero.
    var passoBonito = function (x) {
        if (x <= 0) { return 50; }
        var e = Math.pow(10, Math.floor(Math.log10(x)));
        var f = x / e;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
    };

    var el = function (nome, attrs, texto) {
        var n = document.createElementNS(NS, nome);
        Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (texto !== undefined) { n.textContent = texto; }
        return n;
    };

    var dica = document.createElement('div');
    dica.className = 'pn-dica';
    dica.hidden = true;

    var desenhar = function () {
        if (!caixa || !dados.length) { return; }
        var W = Math.max(280, Math.round(caixa.clientWidth || 600));
        var H = 200, padL = 58, padB = 26, padT = 10;
        var altura = H - padT - padB;
        var max = Math.max.apply(null, dados.map(function (d) { return d.v / 100; }));
        var passo = passoBonito(max / 3);
        var topo = Math.max(passo * 3, Math.ceil(max / passo) * passo);
        var largura = (W - padL) / dados.length;
        var nPer = DIAS[per];

        var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, role: 'img',
            'aria-label': 'Vendas pagas por dia nos últimos 14 dias' });

        for (var v = 0; v <= topo + 1e-9; v += passo) {
            var y = padT + altura * (1 - v / topo);
            svg.appendChild(el('line', { 'class': 'pn-gl', x1: padL, x2: W, y1: y, y2: y }));
            svg.appendChild(el('text', { 'class': 'pn-tick', x: padL - 8, y: y + 4, 'text-anchor': 'end' },
                v ? 'R$ ' + v.toLocaleString('pt-BR') : '0'));
        }

        var cadaRotulo = W >= 560 ? 2 : 3;
        dados.forEach(function (d, i) {
            var h = altura * ((d.v / 100) / topo);
            var x = padL + i * largura + Math.min(4, largura * 0.15);
            var w = largura - 2 * Math.min(4, largura * 0.15);
            var base = H - padB;
            var noPeriodo = i >= dados.length - nPer;
            if (h > 0) {
                var r = Math.min(4, w / 2, h);
                svg.appendChild(el('path', {
                    'class': 'pn-b' + (noPeriodo ? ' is-per' : ''),
                    d: 'M' + x + ',' + base + ' V' + (base - h + r) + ' q0,-' + r + ' ' + r + ',-' + r
                        + ' h' + (w - 2 * r) + ' q' + r + ',0 ' + r + ',' + r + ' V' + base + ' Z'
                }));
            }
            // Rótulo do dia: um a cada 2 (ou 3, em tela estreita), contando a partir de hoje.
            if ((dados.length - 1 - i) % cadaRotulo === 0) {
                svg.appendChild(el('text', { 'class': 'pn-tick', x: x + w / 2, y: H - 8, 'text-anchor': 'middle' }, d.rot));
            }
            svg.appendChild(el('rect', { 'class': 'pn-alvo', x: padL + i * largura, y: padT, width: largura, height: altura, 'data-i': i }));
        });

        caixa.textContent = '';
        caixa.appendChild(svg);
        caixa.appendChild(dica);
    };

    caixa.addEventListener('mousemove', function (ev) {
        var alvo = ev.target.closest ? ev.target.closest('.pn-alvo') : null;
        if (!alvo) { dica.hidden = true; return; }
        var d = dados[+alvo.getAttribute('data-i')];
        var box = caixa.getBoundingClientRect();
        var rb = alvo.getBoundingClientRect();
        dica.innerHTML = '';
        var b = document.createElement('b');
        b.textContent = d.sem + ' ' + d.rot;
        dica.appendChild(b);
        dica.appendChild(document.createTextNode(' · ' + brl(d.v) + ' · ' + d.n + ' pedido' + (d.n === 1 ? '' : 's')));
        dica.style.left = Math.min(Math.max(rb.left - box.left + rb.width / 2, 80), box.width - 80) + 'px';
        dica.style.top = (ev.clientY - box.top) + 'px';
        dica.hidden = false;
    });
    caixa.addEventListener('mouseleave', function () { dica.hidden = true; });

    // Período: troca os números e o destaque das barras, sem recarregar.
    card.querySelectorAll('[data-pn-per]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            per = a.getAttribute('data-pn-per');
            card.querySelectorAll('[data-pn-per]').forEach(function (b) {
                b.setAttribute('aria-pressed', b === a ? 'true' : 'false');
            });
            card.querySelectorAll('[data-pn-kpis]').forEach(function (k) {
                k.hidden = k.getAttribute('data-pn-kpis') !== per;
            });
            try { history.replaceState(null, '', a.getAttribute('href')); } catch (e) {}
            desenhar();
        });
    });

    var espera;
    window.addEventListener('resize', function () {
        clearTimeout(espera);
        espera = setTimeout(desenhar, 150);
    });
    desenhar();
})();
