/* =============================================================================
   Admin › Tabelas nutricionais
   Lista em cards: busca e filtros sem recarregar, card clicável (mouse e
   teclado), limpeza em lote no filtro "Sem produto" com "Desfazer".
   Editor (página própria): porção/%VD/avisos/Prévia ao vivo, alérgenos guiados,
   salvar por fetch, excluir (lógico) com "Desfazer" e "Sair sem salvar?".
   O cálculo do rótulo é o mesmo de app/lib/nutricao.php (loja).
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-tn]');
    if (!raiz) { return; }
    var $ = function (s, el) { return (el || raiz).querySelector(s); };
    var $$ = function (s, el) { return Array.prototype.slice.call((el || raiz).querySelectorAll(s)); };
    var esc = function (t) {
        return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    };
    var URLS = window.TN_URL || {};

    // --- Aviso (com "Desfazer" opcional) ---------------------------------------------
    var toast = $('[data-tn-toast]');
    var toastT;
    var avisar = function (msg, desfazer, erro) {
        clearTimeout(toastT);
        toast.textContent = msg;
        toast.classList.toggle('is-erro', !!erro);
        if (desfazer) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = 'Desfazer';
            b.addEventListener('click', function () { toast.hidden = true; desfazer(); });
            toast.appendChild(b);
        }
        toast.hidden = false;
        toastT = setTimeout(function () { toast.hidden = true; }, 5000);
    };
    var postar = function (dados) {
        var corpo;
        if (dados instanceof FormData) {
            corpo = dados;
        } else {
            corpo = new URLSearchParams();
            Object.keys(dados).forEach(function (k) {
                [].concat(dados[k]).forEach(function (v) { corpo.append(Array.isArray(dados[k]) ? k + '[]' : k, v); });
            });
        }
        return fetch(URLS.post, { method: 'POST', credentials: 'same-origin', body: corpo, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }).then(function (d) { d.http = r.status; return d; }); });
    };
    var guardarAviso = function (o) { try { sessionStorage.setItem('tn-aviso', JSON.stringify(o)); } catch (e) {} };

    // Aviso deixado antes de recarregar (salvou, excluiu…), com "Desfazer" quando cabe.
    try {
        var guardado = JSON.parse(sessionStorage.getItem('tn-aviso') || 'null');
        sessionStorage.removeItem('tn-aviso');
        if (guardado && guardado.msg) {
            var desfazer = null;
            if (guardado.restaurar) {
                desfazer = function () {
                    postar({ op: 'restaurar', _csrf: URLS.csrf, id: guardado.restaurar }).then(function (d) {
                        if (d.ok) { location.href = d.url; } else { avisar(d.mensagem || 'Não foi possível desfazer.', null, true); }
                    });
                };
            } else if (guardado.restaurarLote) {
                desfazer = function () {
                    postar({ op: 'restaurar_lote', _csrf: URLS.csrf, ids: guardado.restaurarLote }).then(function (d) {
                        if (d.ok) { guardarAviso({ msg: d.mensagem }); location.reload(); } else { avisar(d.mensagem || 'Não foi possível desfazer.', null, true); }
                    });
                };
            }
            avisar(guardado.msg, desfazer);
        }
    } catch (e) {}

    // --- "Sair sem salvar?" (qualquer link da página, inclusive o menu do admin) ---------
    var sujo = false;
    var modal = $('[data-tn-modal]');
    var destino = null;
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('a[href]');
        if (!a || !sujo || a.target === '_blank' || ev.defaultPrevented || ev.ctrlKey || ev.metaKey || ev.shiftKey) { return; }
        ev.preventDefault();
        destino = a.href;
        var nome = $('#tn-nome') ? ($('#tn-nome').value.trim() || 'Nova tabela') : '';
        $('[data-tn-modal-texto]').textContent = 'As alterações em “' + nome + '” vão ser perdidas.';
        modal.hidden = false;
        $('[data-tn-ficar]').focus();
    });
    $('[data-tn-ficar]').addEventListener('click', function () { modal.hidden = true; destino = null; });
    $('[data-tn-sair]').addEventListener('click', function () { sujo = false; modal.hidden = true; if (destino) { location.href = destino; } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !modal.hidden) { modal.hidden = true; destino = null; } });
    window.addEventListener('beforeunload', function (ev) { if (sujo) { ev.preventDefault(); ev.returnValue = ''; } });

    // ==========================================================================================
    // LISTA EM CARDS
    // ==========================================================================================
    var grade = $('[data-tn-cards]');
    if (grade) {
        var busca = $('[data-tn-q]');
        var filtro = raiz.getAttribute('data-filtro') || 'todas';
        var comFiltros = function (href) {
            var u = new URL(href, location.href);
            if (filtro !== 'todas') { u.searchParams.set('f', filtro); } else { u.searchParams.delete('f'); }
            if (busca.value.trim()) { u.searchParams.set('q', busca.value.trim()); } else { u.searchParams.delete('q'); }
            return u.pathname + u.search;
        };
        var cards = $$('[data-tn-card]');
        var visiveis = function () { return cards.filter(function (c) { return !c.hidden; }); };
        var botaoLote = $('[data-tn-excluir-lote]');
        var todas = $('[data-tn-sel-todas]');
        var atualizarLote = function () {
            var marcadas = visiveis().filter(function (c) { var ck = $('[data-tn-sel]', c); return ck && ck.checked; });
            var selecionaveis = visiveis().filter(function (c) { return $('[data-tn-sel]', c); });
            botaoLote.disabled = marcadas.length === 0;
            botaoLote.textContent = 'Excluir selecionadas' + (marcadas.length ? ' (' + marcadas.length + ')' : '');
            todas.checked = selecionaveis.length > 0 && marcadas.length === selecionaveis.length;
            todas.indeterminate = marcadas.length > 0 && marcadas.length < selecionaveis.length;
        };
        var filtrar = function () {
            var q = busca.value.trim().toLocaleLowerCase('pt-BR');
            cards.forEach(function (c) {
                var uso = c.getAttribute('data-uso') === '1';
                c.hidden = !((filtro === 'todas' || (filtro === 'uso' ? uso : !uso)) && (!q || c.getAttribute('data-nome').indexOf(q) !== -1));
                if (c.hidden) { var ck = $('[data-tn-sel]', c); if (ck) { ck.checked = false; } }
                c.setAttribute('data-href', comFiltros(c.getAttribute('data-href')));
            });
            $('[data-tn-vazio]').hidden = visiveis().length > 0;
            raiz.setAttribute('data-filtro', filtro);
            $$('.tn-filtros [data-tn-filtro]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-tn-filtro') === filtro ? 'true' : 'false'); });
            var faixa = $('[data-tn-faixa]');
            if (faixa) { faixa.hidden = filtro === 'sem'; }
            $('[data-tn-lote]').hidden = filtro !== 'sem';
            var nova = document.querySelector('.admin-titulo-linha a');
            if (nova) { nova.setAttribute('href', comFiltros(nova.getAttribute('href'))); }
            atualizarLote();
            try { history.replaceState(null, '', comFiltros(location.href)); } catch (e) {}
        };
        busca.addEventListener('input', filtrar);
        $$('[data-tn-filtro]').forEach(function (b) {
            b.addEventListener('click', function () { filtro = b.getAttribute('data-tn-filtro'); filtrar(); });
        });

        // Card inteiro abre o editor (a caixa de seleção não).
        grade.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-tn-sel]')) { return; }
            var c = ev.target.closest('[data-tn-card]');
            if (c) { location.href = c.getAttribute('data-href'); }
        });
        grade.addEventListener('keydown', function (ev) {
            var c = ev.target.closest('[data-tn-card]');
            if (c && ev.target === c && (ev.key === 'Enter' || ev.key === ' ')) {
                ev.preventDefault();
                location.href = c.getAttribute('data-href');
            }
        });
        grade.addEventListener('change', function (ev) { if (ev.target.matches('[data-tn-sel]')) { atualizarLote(); } });
        todas.addEventListener('change', function () {
            visiveis().forEach(function (c) { var ck = $('[data-tn-sel]', c); if (ck) { ck.checked = todas.checked; } });
            atualizarLote();
        });
        botaoLote.addEventListener('click', function () {
            var ids = visiveis().map(function (c) { return $('[data-tn-sel]', c); }).filter(function (ck) { return ck && ck.checked; })
                .map(function (ck) { return ck.value; });
            if (!ids.length) { return; }
            botaoLote.disabled = true;
            postar({ op: 'excluir_lote', _csrf: URLS.csrf, ids: ids }).then(function (d) {
                if (!d.ok) { avisar(d.mensagem || 'Não foi possível excluir.', null, true); atualizarLote(); return; }
                guardarAviso({ msg: d.mensagem, restaurarLote: d.ids });
                location.reload();
            }).catch(function () { avisar('Não foi possível excluir. Tente de novo.', null, true); atualizarLote(); });
        });
        filtrar();
        return;
    }

    // ==========================================================================================
    // EDITOR
    // ==========================================================================================
    var form = $('[data-tn-form]');
    if (!form) { return; }
    var D = JSON.parse(document.getElementById('tn-dados').textContent);
    var CAMPOS = D.campos;          // coluna => {rotulo, un, casas, vd, nivel}
    var salvando = false;
    var campo = function (col) { return document.getElementById('tn-' + col); };

    // Número digitado: '' -> null; "12,5" -> 12.5; negativo/texto -> NaN.
    var num = function (txt) {
        txt = String(txt).trim();
        if (txt === '') { return null; }
        return /^\d+([.,]\d+)?$/.test(txt) ? parseFloat(txt.replace(',', '.')) : NaN;
    };
    var fmt = function (v, casas) {
        return v == null || isNaN(v) ? '—' : v.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: casas });
    };

    // --- Alérgenos ----------------------------------------------------------------------------
    var lista = function (g) { var v = $('[data-tn-lista="' + g + '"]').value; return v ? v.split(',') : []; };
    var ORDEM = $$('[data-tn-alg="contem"]').map(function (b) { return b.getAttribute('data-a'); });
    var gluten = function () { return $('[data-tn-gluten]').getAttribute('aria-checked') === 'true'; };
    var manual = function () { return $('[data-tn-modo]').value === 'manual'; };
    var textoAuto = function () {
        var c = lista('contem'), t = lista('traco');
        var junta = function (a) {
            a = a.map(function (x) { return x.toLocaleLowerCase('pt-BR'); });
            return a.length > 1 ? a.slice(0, -1).join(', ') + ' e ' + a[a.length - 1] : a[0];
        };
        var partes = [];
        if (c.length) { partes.push('Alérgicos: contém ' + junta(c) + '.'); }
        if (t.length) { partes.push((c.length ? 'Pode conter ' : 'Alérgicos: pode conter ') + junta(t) + '.'); }
        partes.push(gluten() ? 'Contém glúten.' : 'Não contém glúten.');
        return partes.join(' ');
    };
    var textoFinal = function () { return manual() ? $('#tn-manual').value.trim() : textoAuto(); };

    $$('[data-tn-alg]').forEach(function (b) {
        b.addEventListener('click', function () {
            var g = b.getAttribute('data-tn-alg'), outro = g === 'contem' ? 'traco' : 'contem', a = b.getAttribute('data-a');
            var atual = lista(g), oposto = lista(outro);
            if (atual.indexOf(a) !== -1) {
                atual = atual.filter(function (x) { return x !== a; });
            } else {
                atual.push(a);
                oposto = oposto.filter(function (x) { return x !== a; });   // não pode estar nos dois
            }
            var ordenar = function (arr) { return ORDEM.filter(function (x) { return arr.indexOf(x) !== -1; }); };
            $('[data-tn-lista="' + g + '"]').value = ordenar(atual).join(',');
            $('[data-tn-lista="' + outro + '"]').value = ordenar(oposto).join(',');
            ['contem', 'traco'].forEach(function (k) {
                var l = lista(k);
                $$('[data-tn-alg="' + k + '"]').forEach(function (x) { x.setAttribute('aria-pressed', l.indexOf(x.getAttribute('data-a')) !== -1 ? 'true' : 'false'); });
            });
            marcarSujo();
        });
    });
    $('[data-tn-gluten]').addEventListener('click', function () {
        var sw = $('[data-tn-gluten]'), on = !gluten();
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        $('[data-tn-gluten-campo]').value = on ? '1' : '0';
        marcarSujo();
    });
    $('[data-tn-ir-manual]').addEventListener('click', function () {
        $('#tn-manual').value = textoAuto();
        $('[data-tn-modo]').value = 'manual';
        $('[data-tn-auto]').hidden = true;
        $('[data-tn-manual]').hidden = false;
        $('#tn-manual').focus();
        marcarSujo();
    });
    $('[data-tn-ir-auto]').addEventListener('click', function () {
        $('[data-tn-modo]').value = 'auto';
        $('[data-tn-manual]').hidden = true;
        $('[data-tn-auto]').hidden = false;
        marcarSujo();
    });

    // --- Cálculos, avisos e Prévia -------------------------------------------------------------
    var rotulo = function (p, medida) {
        var linhas = Object.keys(CAMPOS).map(function (col) {
            var c = CAMPOS[col], x = num(campo(col).value);
            x = isNaN(x) ? null : x;
            var xp = x != null && p != null ? x * p / 100 : null;
            var vd = c.vd && xp != null ? Math.round(xp / c.vd * 100) + '%' : '';
            return '<tr' + (c.nivel ? ' class="nivel-' + c.nivel + '"' : '') + '><td>' + esc(c.rotulo + ' (' + c.un + ')') + '</td><td>'
                + fmt(x, c.casas) + '</td><td>' + fmt(xp, c.casas) + '</td><td>' + vd + '</td></tr>';
        }).join('');
        var porcao = p != null ? fmt(p, 1) + ' g' : '—';
        var alg = textoFinal();
        return '<div class="nutri-rotulo"><div class="nutri-rotulo-titulo">INFORMAÇÃO NUTRICIONAL</div>'
            + '<div class="nutri-rotulo-porcao">Porção: ' + esc(porcao + (medida ? ' (' + medida + ')' : '')) + '</div>'
            + '<table><thead><tr><th></th><th>100 g</th><th>' + esc(p != null ? fmt(p, 1) + ' g' : 'Porção') + '</th><th>%VD*</th></tr></thead>'
            + '<tbody>' + linhas + '</tbody></table>'
            + '<div class="nutri-rotulo-rodape">*Percentual de valores diários fornecidos pela porção.</div></div>'
            + (alg ? '<p class="nutri-rotulo-alergenos">' + esc(alg.toLocaleUpperCase('pt-BR')) + '</p>' : '');
    };

    var recalcular = function () {
        var pRaw = num($('#tn-porcao').value);
        var p = pRaw != null && !isNaN(pRaw) && pRaw > 0 ? pRaw : null;
        var v = {};
        Object.keys(CAMPOS).forEach(function (col) {
            var c = CAMPOS[col], x = num(campo(col).value);
            v[col] = x;
            var ok = !(typeof x === 'number' && isNaN(x));
            var xp = ok && x != null && p != null ? x * p / 100 : null;
            $('[data-tn-porcao="' + col + '"]').textContent = fmt(xp, c.casas) + (xp != null ? ' ' + c.un : '');
            $('[data-tn-vd="' + col + '"]').textContent = c.vd && xp != null ? Math.round(xp / c.vd * 100) + '%' : '';
        });
        var g = function (k) { var x = v['nutri_' + k]; return x == null || isNaN(x) ? null : x; };
        var avisos = [], ruins = {};
        if (g('acucares_add') != null && g('acucares_totais') != null && g('acucares_add') > g('acucares_totais')) {
            avisos.push('Açúcares adicionados não podem ser maiores que os açúcares totais.'); ruins.nutri_acucares_add = 1;
        }
        if (g('acucares_totais') != null && g('carboidratos') != null && g('acucares_totais') > g('carboidratos')) {
            avisos.push('Açúcares totais não podem ser maiores que os carboidratos.'); ruins.nutri_acucares_totais = 1;
        }
        if (g('gorduras_totais') != null && (g('gorduras_sat') != null || g('gorduras_trans') != null)
            && (g('gorduras_sat') || 0) + (g('gorduras_trans') || 0) > g('gorduras_totais')) {
            avisos.push('Gorduras saturadas + trans passam das gorduras totais.'); ruins.nutri_gorduras_sat = 1; ruins.nutri_gorduras_trans = 1;
        }
        if (g('valor_energetico') != null && g('carboidratos') != null && g('proteinas') != null && g('gorduras_totais') != null) {
            var calc = 4 * g('carboidratos') + 4 * g('proteinas') + 9 * g('gorduras_totais') + 2 * (g('fibra') || 0);
            if (Math.abs(calc - g('valor_energetico')) / Math.max(calc, 1) > 0.15) {
                avisos.push('O valor energético não bate com os macros (o cálculo dá ≈ ' + fmt(calc, 0) + ' kcal). Vale conferir.');
                ruins.nutri_valor_energetico = 1;
            }
        }
        if (p == null) { avisos.push('Sem a porção, a loja não consegue mostrar os valores por porção nem o %VD.'); }
        Object.keys(CAMPOS).forEach(function (col) {
            var invalido = typeof v[col] === 'number' && isNaN(v[col]);
            $('[data-tn-linha="' + col + '"]').classList.toggle('is-ruim', !!ruins[col] || invalido);
            var erro = $('[data-tn-erro="' + col + '"]');
            if (invalido) { erro.textContent = 'Use só números, sem sinal de menos (ex.: 12,5).'; erro.hidden = false; campo(col).setAttribute('aria-invalid', 'true'); }
            else if (erro.textContent.indexOf('Use só números') === 0) { erro.hidden = true; erro.textContent = ''; campo(col).removeAttribute('aria-invalid'); }
        });
        $('[data-tn-avisos]').innerHTML = avisos.map(function (a) { return '<p class="pe-aviso">' + esc(a) + '</p>'; }).join('');
        $('[data-tn-algtexto]').textContent = textoAuto();
        $('[data-tn-previa]').innerHTML = rotulo(p, $('#tn-medida').value.trim());
        var nome = $('#tn-nome').value.trim();
        $('[data-tn-titulo]').textContent = nome || 'Nova tabela nutricional';
        var chave = nome.toLocaleLowerCase('pt-BR');
        $('[data-tn-dup]').hidden = !chave || !D.nomes.some(function (n) { return n[1] === chave && n[0] !== D.id; });
    };

    // --- Estado e barra -------------------------------------------------------------------------
    var botaoSalvar = $('[data-tn-salvar]');
    var atualizarBarra = function () {
        var est = $('[data-tn-estado]');
        est.classList.toggle('is-sujo', sujo);
        $('[data-tn-estado-txt]').textContent = salvando ? 'Salvando…' : (sujo ? 'Alterações não salvas' : (D.novo ? 'Tabela nova' : 'Tudo salvo'));
        $('[data-tn-descartar]').textContent = sujo ? 'Descartar' : 'Voltar';
        botaoSalvar.disabled = !sujo || salvando || !$('#tn-nome').value.trim();
    };
    var marcarSujo = function () { sujo = true; recalcular(); atualizarBarra(); };
    form.addEventListener('input', function (ev) {
        if (!ev.target.name) { return; }
        var erro = $('[data-tn-erro="' + ev.target.name + '"]');
        if (erro && erro.textContent.indexOf('Use só números') !== 0) { erro.hidden = true; ev.target.removeAttribute('aria-invalid'); }
        marcarSujo();
    });

    var mostrarErros = function (erros) {
        var primeiro = null;
        Object.keys(erros).forEach(function (k) {
            var span = $('[data-tn-erro="' + k + '"]');
            if (span) { span.textContent = erros[k]; span.hidden = false; }
            var el = form.querySelector('[name="' + k + '"]');
            if (el) { el.setAttribute('aria-invalid', 'true'); }
            primeiro = primeiro || el || span;
        });
        if (primeiro) {
            primeiro.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (primeiro.focus) { primeiro.focus({ preventScroll: true }); }
        }
    };

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (botaoSalvar.disabled) { return; }
        var erros = {};
        if ($('#tn-nome').value.trim().length < 2) { erros.nome = 'Informe o nome da tabela.'; }
        var pp = num($('#tn-porcao').value);
        if (pp != null && (isNaN(pp) || pp <= 0)) { erros.porcao_individual_g = 'Use um número maior que zero (ex.: 30).'; }
        Object.keys(CAMPOS).forEach(function (col) {
            var x = num(campo(col).value);
            if (typeof x === 'number' && isNaN(x)) { erros[col] = 'Use só números, sem sinal de menos (ex.: 12,5).'; }
        });
        if (Object.keys(erros).length) { mostrarErros(erros); avisar('Confira os campos destacados.', null, true); return; }
        salvando = true;
        atualizarBarra();
        postar(new FormData(form)).then(function (d) {
            salvando = false;
            if (!d.ok) {
                if (d.erros) { mostrarErros(d.erros); }
                avisar(d.mensagem || 'Não foi possível salvar. Tente de novo.', null, true);
                atualizarBarra();
                return;
            }
            sujo = false;
            guardarAviso({ msg: d.mensagem });
            // Mantém a busca e o filtro da lista no endereço (para o "← Tabelas nutricionais").
            var u = new URL(d.url, location.href);
            var atual = new URL(location.href);
            ['f', 'q'].forEach(function (k) { if (atual.searchParams.get(k)) { u.searchParams.set(k, atual.searchParams.get(k)); } });
            location.replace(u.pathname + u.search);
        }).catch(function () {
            salvando = false;
            atualizarBarra();
            avisar('Não foi possível salvar. Confira a conexão e tente de novo.', null, true);
        });
    });

    // --- Excluir (confirmação na página) --------------------------------------------------------
    var btnExcluir = $('[data-tn-excluir]');
    if (btnExcluir) {
        var conf = $('[data-tn-confirmar]');
        btnExcluir.addEventListener('click', function () { btnExcluir.hidden = true; conf.hidden = false; $('[data-tn-excluir-nao]').focus(); });
        $('[data-tn-excluir-nao]').addEventListener('click', function () { conf.hidden = true; btnExcluir.hidden = false; btnExcluir.focus(); });
        $('[data-tn-excluir-sim]').addEventListener('click', function () {
            postar({ op: 'excluir', _csrf: D.csrf, id: D.id }).then(function (d) {
                if (!d.ok) { avisar(d.mensagem || 'Não foi possível excluir.', null, true); return; }
                sujo = false;
                guardarAviso({ msg: d.mensagem, restaurar: d.id });
                location.href = D.urlLista;
            }).catch(function () { avisar('Não foi possível excluir. Tente de novo.', null, true); });
        });
    }

    recalcular();
    if (D.copia) {
        sujo = true;
        avisar('Cópia criada. Ajuste o nome e os valores e clique em Criar tabela.');
    }
    atualizarBarra();
})();
