/* =============================================================================
   Admin › Configurações: abas num formulário só (nada se perde ao trocar),
   simuladores de parcelamento e frete (mesmas regras do PHP), avisos ao vivo,
   prévias do WhatsApp, CEP pelo ViaCEP e barra de salvar (fetch, tudo de uma vez).
   ============================================================================= */
(function () {
    'use strict';

    var form = document.querySelector('[data-cf]');
    if (!form) { return; }
    var D = JSON.parse(document.getElementById('cf-dados').textContent);
    var $ = function (s, el) { return (el || form).querySelector(s); };
    var $$ = function (s, el) { return Array.prototype.slice.call((el || form).querySelectorAll(s)); };
    var campo = function (nome) { return form.elements[nome]; };
    var valor = function (nome) { var c = campo(nome); return c ? String(c.value) : ''; };
    var ABAS = { loja: 'Loja', pagamento: 'Pagamento', entrega: 'Entrega', textos: 'Textos' };
    var abaDe = function (el) { var p = el.closest('[data-cf-painel]'); return p ? p.getAttribute('data-cf-painel') : null; };
    var esc = function (t) {
        return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    };

    // --- Aviso -----------------------------------------------------------------------------
    var toast = $('[data-cf-toast]');
    var toastT;
    var avisar = function (msg, erro) {
        clearTimeout(toastT);
        toast.textContent = msg;
        toast.classList.toggle('is-erro', !!erro);
        toast.hidden = false;
        toastT = setTimeout(function () { toast.hidden = true; }, 4000);
    };

    // --- Números (mesmo formato do PHP: reais com vírgula -> centavos) ----------------------
    var centavos = function (txt) {
        txt = String(txt).trim();
        if (txt === '') { return 0; }
        var n = txt.indexOf(',') !== -1 ? txt.replace(/\./g, '').replace(',', '.') : txt;
        var v = parseFloat(n);
        return isNaN(v) ? 0 : Math.round(v * 100);
    };
    var inteiro = function (txt) { var v = parseInt(String(txt).trim(), 10); return isNaN(v) ? 0 : v; };
    var brl = function (c) { return (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };

    // Parcelamento — mesma regra de parcelamento_parcelas() (app/helpers.php).
    var parcelas = function (total) {
        var limite = centavos(valor('parcelamento_limite_centavos'));
        var minima = centavos(valor('parcela_minima_centavos'));
        var max = inteiro(valor('parcelamento_max'));
        if (total <= 0 || total < limite) { return 1; }
        var n = minima > 0 ? Math.floor(total / minima) : (max >= 2 ? max : 1);
        if (max >= 2) { n = Math.min(n, max); }
        return Math.max(1, n);
    };
    // Valor da parcela arredondado para baixo, como parcelamento_texto().
    var textoParcelas = function (total) {
        var n = parcelas(total);
        return n < 2 ? 'só à vista' : 'até ' + n + 'x de ' + brl(Math.floor(total / n));
    };
    // Frete — mesma regra de frete_calcular() + frete_por_distancia() (app/lib/frete.php).
    var frete = function (km) {
        var raio = inteiro(valor('entrega_raio_max_km'));
        if (raio > 0 && km > raio) { return null; }
        var base = inteiro(valor('frete_base_km'));
        var fixo = centavos(valor('frete_base_centavos'));
        if (km <= base) { return fixo; }
        return fixo + Math.ceil(km - base) * centavos(valor('frete_por_km_centavos'));
    };

    var simular = function () {
        var sp = $('[data-cf-sim="parcelas"]');
        var v = +$('[data-cf-sim-range]', sp).value * 100;
        $('[data-cf-sim-valor]', sp).textContent = brl(v);
        $('[data-cf-sim-res]', sp).textContent = textoParcelas(v);
        $('[data-cf-sim-ex]', sp).innerHTML = [60, 100, 120, 150, 250, 400].map(function (r) {
            return '<div><b>' + brl(r * 100) + '</b>' + esc(textoParcelas(r * 100)) + '</div>';
        }).join('');
        var se = $('[data-cf-sim="frete"]');
        var km = +$('[data-cf-sim-range]', se).value;
        var f = frete(km);
        $('[data-cf-sim-valor]', se).textContent = km.toLocaleString('pt-BR') + ' km';
        $('[data-cf-sim-res]', se).textContent = f == null ? 'só retirada' : brl(f);
        $('[data-cf-sim-res]', se).classList.toggle('is-retirada', f == null);
        $('[data-cf-sim-ex]', se).innerHTML = [2, 5, 8, 12, 15, 20].map(function (d) {
            var x = frete(d);
            return '<div' + (x == null ? ' class="is-retirada"' : '') + '><b>' + (x == null ? 'só retirada' : brl(x)) + '</b>' + d + ' km</div>';
        }).join('');
    };

    // --- Telefone, CNPJ, links -----------------------------------------------------------------
    var digitos = function (t) { return String(t).replace(/\D+/g, ''); };
    var foneAviso = function () {
        var d = digitos(valor('whatsapp_numero'));
        if (d.length >= 12 && d.indexOf('55') === 0) { d = d.slice(2); }
        return d.length === 10 && /^[6-9]/.test(d.slice(2)) ? 'Celular com 8 dígitos depois do DDD. Confira se falta o 9 na frente.' : '';
    };
    var cnpjValido = function (c) {
        var d = digitos(c);
        if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) { return false; }
        var dv = function (n) {
            var pesos = n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            var s = 0;
            for (var i = 0; i < n; i++) { s += +d[i] * pesos[i]; }
            var r = s % 11;
            return r < 2 ? 0 : 11 - r;
        };
        return dv(12) === +d[12] && dv(13) === +d[13];
    };

    // --- Endereço (resumo do "mesmo endereço da loja") ------------------------------------------
    var resumoEndereco = function () {
        var g = function (p) { return valor('endereco_' + p).trim(); };
        if (!g('rua')) { return 'Preencha o endereço na aba Loja.'; }
        var cep = digitos(g('cep'));
        return g('rua') + (g('numero') ? ', ' + g('numero') : '') + (g('complemento') ? ' · ' + g('complemento') : '')
            + (g('bairro') ? ' — ' + g('bairro') : '') + (g('cidade') ? ', ' + g('cidade') + (g('uf') ? '/' + g('uf') : '') : '')
            + (cep.length === 8 ? ' · CEP ' + cep.slice(0, 5) + '-' + cep.slice(5) : '');
    };

    // --- Prévias do WhatsApp ------------------------------------------------------------------------
    var previaWa = function () {
        $('[data-cf-wa="whatsapp_msg"]').textContent = valor('whatsapp_msg');
        $('[data-cf-wa="personalizar_msg_template"]').innerHTML = esc(valor('personalizar_msg_template'))
            .replace(/\{produto\}/g, '<b>Biscoito de baunilha</b>')
            .replace(/\{link\}/g, '<u>dolivier.com.br/produto/biscoito-de-baunilha</u>');
    };

    // --- Avisos (não impedem salvar) -----------------------------------------------------------------
    var avisos = function () {
        var a = { loja: [], pagamento: [], entrega: [], textos: [] };
        var f = foneAviso();
        if (f) { a.loja.push(f); }
        if (!valor('retirada_endereco').trim()) { a.entrega.push('Sem instruções de retirada: o cliente não recebe orientação de como e quando retirar.'); }
        if (/\{(produto|link)\}/.test(valor('whatsapp_msg'))) { a.textos.push('Essa mensagem não tem produto nem link: o cliente veria o texto entre chaves. Tire essa parte.'); }
        if (valor('personalizar_msg_template').indexOf('{link}') === -1) { a.textos.push('A mensagem de personalização está sem {link}: você não vai saber de qual produto a pessoa fala.'); }
        if (valor('sobre_texto').trim().length < 40) { a.textos.push('“Sobre nós” parece um texto de teste. Ele aparece na página Sobre da loja.'); }
        return a;
    };

    // --- Estado: o que mudou desde o último salvar -----------------------------------------------------
    var nomes = function () {
        return Array.prototype.filter.call(form.elements, function (el) { return el.name && el.name !== '_csrf'; });
    };
    var foto = {};
    var fotografar = function () { foto = {}; nomes().forEach(function (el) { foto[el.name] = el.value; }); };
    var abasSujas = function () {
        var s = {};
        nomes().forEach(function (el) { if (foto[el.name] !== el.value) { s[abaDe(el)] = true; } });
        return s;
    };

    var atualizar = function () {
        var sujas = abasSujas(), av = avisos();
        // Bolinha nas abas (alteração não salva ou aviso).
        Object.keys(ABAS).forEach(function (k) {
            var b = $('[data-cf-aba="' + k + '"]');
            var marca = !!sujas[k] || av[k].length > 0;
            $('.cf-bolinha', b).hidden = !marca;
            $('[data-cf-aba-sr]', b).textContent = sujas[k] ? ' (não salvo)' : (av[k].length ? ' (com aviso)' : '');
            var caixa = $('[data-cf-avisos="' + k + '"]');
            if (caixa) { caixa.innerHTML = av[k].map(function (x) { return '<p class="pe-aviso">' + esc(x) + '</p>'; }).join(''); }
        });
        var lista = Object.keys(ABAS).filter(function (k) { return sujas[k]; }).map(function (k) { return ABAS[k]; });
        $('[data-cf-estado]').classList.toggle('is-sujo', lista.length > 0);
        $('[data-cf-estado-txt]').textContent = lista.length ? 'Não salvo: ' + lista.join(', ') : 'Tudo salvo';
        $('[data-cf-descartar]').hidden = !lista.length;
        $('[data-cf-salvar]').disabled = !lista.length || salvando;

        // CNPJ, teste do WhatsApp, mapa, resumo, status do Google, contadores, prévias e simuladores.
        var cn = valor('cnpj').trim(), cnEl = $('[data-cf-cnpj]');
        cnEl.textContent = cn === '' ? '' : (cnpjValido(cn) ? '✓ CNPJ válido' : 'CNPJ inválido');
        cnEl.className = 'cf-cnpj ' + (cnpjValido(cn) ? 'is-ok' : 'is-ruim');
        $('[data-cf-testar]').href = 'https://wa.me/' + digitos(valor('whatsapp_numero'));
        $('[data-cf-mapa]').href = 'https://www.google.com/maps?q=' + encodeURIComponent(valor('loja_lat').replace(',', '.') + ',' + valor('loja_lng').replace(',', '.'));
        $('[data-cf-resumo]').textContent = resumoEndereco();
        var st = $('[data-cf-status]'), prov = valor('frete_provedor');
        st.classList.toggle('is-alerta', prov !== 'google' || !D.temChave);
        st.textContent = prov !== 'google' ? 'Cálculo de distância desligado: o frete por motoboy não é oferecido, só retirada.'
            : (D.temChave ? 'Distância calculada pelo Google Maps · chave configurada'
                : 'Chave do Google não configurada: o frete por motoboy não é calculado, só retirada.');
        $$('[data-cf-contar]').forEach(function (t) { $('[data-cf-contador="' + t.name + '"]').textContent = t.value.length + ' / ' + t.maxLength; });
        previaWa();
        simular();
    };

    // --- Abas (o painel só se esconde; nada se perde) -------------------------------------------------
    var abrirAba = function (k, foco) {
        $$('[data-cf-aba]').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-cf-aba') === k ? 'true' : 'false'); });
        $$('[data-cf-painel]').forEach(function (p) { p.hidden = p.getAttribute('data-cf-painel') !== k; });
        try {
            var u = new URL(location.href);
            u.searchParams.set('aba', k);
            history.replaceState(null, '', u.pathname + u.search);
        } catch (e) {}
        if (foco) { $('[data-cf-aba="' + k + '"]').focus(); }
    };
    $$('[data-cf-aba]').forEach(function (b) {
        b.addEventListener('click', function () { abrirAba(b.getAttribute('data-cf-aba')); });
        b.addEventListener('keydown', function (ev) {
            var ks = Object.keys(ABAS), i = ks.indexOf(b.getAttribute('data-cf-aba'));
            if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
                ev.preventDefault();
                abrirAba(ks[(i + (ev.key === 'ArrowRight' ? 1 : ks.length - 1)) % ks.length], true);
            }
        });
    });

    // --- Entrada de dados ------------------------------------------------------------------------------
    form.addEventListener('input', function (ev) {
        var el = ev.target;
        if (el.closest('.cf-sem-arroba') && el.value.indexOf('@') === 0) { el.value = el.value.replace(/^@+/, ''); }
        if (el.name && /_uf$/.test(el.name)) { el.value = el.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2); }
        if (el.name) {
            var erro = $('[data-cf-erro="' + el.name + '"]');
            if (erro) { erro.hidden = true; el.removeAttribute('aria-invalid'); }
        }
        atualizar();
    });
    form.addEventListener('change', atualizar);

    // Chave "Mesmo endereço da loja"
    $$('[data-cf-sw]').forEach(function (sw) {
        sw.addEventListener('click', function () {
            var on = sw.getAttribute('aria-checked') !== 'true';
            sw.setAttribute('aria-checked', on ? 'true' : 'false');
            campo(sw.getAttribute('data-cf-sw')).value = on ? '1' : '0';
            $('[data-cf-resumo]').hidden = !on;
            $('[data-cf-saida]').hidden = on;
            atualizar();
        });
    });

    // {produto} / {link} na posição do cursor
    $$('[data-cf-token]').forEach(function (b) {
        b.addEventListener('click', function () {
            var ta = campo('personalizar_msg_template'), t = b.getAttribute('data-cf-token');
            var a = ta.selectionStart != null ? ta.selectionStart : ta.value.length, z = ta.selectionEnd != null ? ta.selectionEnd : ta.value.length;
            ta.value = ta.value.slice(0, a) + t + ta.value.slice(z);
            ta.focus();
            ta.setSelectionRange(a + t.length, a + t.length);
            atualizar();
        });
    });

    // CEP -> ViaCEP (pelo navegador)
    $$('[data-cf-buscar-cep]').forEach(function (b) {
        b.addEventListener('click', function () {
            var p = b.getAttribute('data-cf-buscar-cep'), msg = $('[data-cf-cep-msg="' + p + '"]');
            var d8 = digitos(valor(p + 'cep'));
            if (d8.length !== 8) { msg.textContent = 'Digite o CEP com 8 números.'; msg.classList.add('is-aviso'); return; }
            b.disabled = true;
            msg.textContent = 'Buscando…';
            fetch('https://viacep.com.br/ws/' + d8 + '/json/')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || d.erro) { throw new Error(); }
                    campo(p + 'cep').value = d8.slice(0, 5) + '-' + d8.slice(5);
                    [['rua', d.logradouro], ['bairro', d.bairro], ['cidade', d.localidade], ['uf', d.uf]].forEach(function (x) {
                        if (x[1]) { campo(p + x[0]).value = x[1]; }
                    });
                    msg.textContent = 'Endereço encontrado. Confira e preencha o número.';
                    msg.classList.remove('is-aviso');
                    campo(p + 'numero').focus();
                })
                .catch(function () {
                    msg.textContent = 'CEP não encontrado. Preencha o endereço à mão.';
                    msg.classList.add('is-aviso');
                })
                .then(function () { b.disabled = false; atualizar(); });
        });
    });

    // --- Salvar / descartar ----------------------------------------------------------------------------
    var salvando = false;
    var mostrarErros = function (erros) {
        var primeiro = null;
        Object.keys(erros).forEach(function (k) {
            var span = $('[data-cf-erro="' + k + '"]'), el = campo(k);
            if (span) { span.textContent = erros[k]; span.hidden = false; }
            if (el && el.setAttribute) { el.setAttribute('aria-invalid', 'true'); }
            if (!primeiro && el) { primeiro = el; }
        });
        if (primeiro) {
            abrirAba(abaDe(primeiro));
            var det = primeiro.closest('details');
            if (det) { det.open = true; }
            var saida = primeiro.closest('[data-cf-saida]');
            if (saida && saida.hidden) { $('[data-cf-sw="loja_endereco_igual"]').click(); }
            primeiro.focus();
        }
    };
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var cn = valor('cnpj').trim();
        if (cn !== '' && !cnpjValido(cn)) { mostrarErros({ cnpj: 'CNPJ inválido. Confira os números.' }); return; }
        salvando = true;
        atualizar();
        $('[data-cf-estado-txt]').textContent = 'Salvando…';
        fetch(D.url, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                salvando = false;
                if (!d.ok) {
                    if (d.erros) { mostrarErros(d.erros); }
                    avisar(d.mensagem || 'Não foi possível salvar. Tente de novo.', true);
                    atualizar();
                    return;
                }
                fotografar();
                atualizar();
                avisar(d.mensagem);
            })
            .catch(function () { salvando = false; atualizar(); avisar('Não foi possível salvar. Confira a conexão e tente de novo.', true); });
    });
    $('[data-cf-descartar]').addEventListener('click', function () {
        nomes().forEach(function (el) { if (el.name in foto) { el.value = foto[el.name]; } });
        var on = valor('loja_endereco_igual') === '1';
        $('[data-cf-sw="loja_endereco_igual"]').setAttribute('aria-checked', on ? 'true' : 'false');
        $('[data-cf-resumo]').hidden = !on;
        $('[data-cf-saida]').hidden = on;
        $$('[data-cf-erro]').forEach(function (s) { s.hidden = true; });
        atualizar();
        avisar('Alterações descartadas.');
    });
    window.addEventListener('beforeunload', function (ev) {
        if (Object.keys(abasSujas()).length) { ev.preventDefault(); ev.returnValue = ''; }
    });

    fotografar();
    atualizar();
})();
