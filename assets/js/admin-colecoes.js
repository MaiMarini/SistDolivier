/* =============================================================================
   Admin › Coleções: ordem (arrastar pela alça ⠿ e setas, salva sozinho), chave
   Ligada/Desligada com "Desfazer", formulário na própria linha (editar e criar)
   e exclusão com "Desfazer". Mesmo padrão de Admin › Categorias. Tudo por fetch;
   se o servidor recusar, a tela volta ao que era.
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-ct]');
    if (!raiz) { return; }
    var URL_POST = raiz.getAttribute('data-url');
    var CSRF = raiz.getAttribute('data-csrf');
    var HOJE = raiz.getAttribute('data-hoje');
    var CAT_PADRAO = raiz.getAttribute('data-cat-padrao');
    var $ = function (s, el) { return (el || raiz).querySelector(s); };
    var $$ = function (s, el) { return Array.prototype.slice.call((el || raiz).querySelectorAll(s)); };
    var corpo = $('[data-ct-linhas]');
    var FALHOU = 'Não foi possível salvar. Tente de novo.';

    // --- Aviso (com "Desfazer" opcional) ---------------------------------------------
    var toast = $('[data-ct-toast]');
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
        toastT = setTimeout(function () { toast.hidden = true; }, 6000);
    };
    var postar = function (dados) {
        var c = new URLSearchParams();
        c.append('_csrf', CSRF);
        Object.keys(dados).forEach(function (k) {
            if (Array.isArray(dados[k])) { dados[k].forEach(function (v) { c.append(k + '[]', v); }); } else { c.append(k, dados[k]); }
        });
        return fetch(URL_POST, { method: 'POST', credentials: 'same-origin', body: c, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false }; }).then(function (d) {
                    if (!r.ok || !d.ok) { var e = new Error(d.mensagem || FALHOU); e.dados = d; throw e; }
                    return d;
                });
            });
    };

    var linhas = function () { return $$('[data-ct-linha]', corpo); };
    var nomeDe = function (l) { return l.getAttribute('data-nome'); };

    var atualizar = function () {
        var ls = linhas();
        ls.forEach(function (l, i) {
            $('[data-ct-mover="-1"]', l).disabled = i === 0;
            $('[data-ct-mover="1"]', l).disabled = i === ls.length - 1;
        });
        $('[data-ct-sem]').hidden = ls.length > 0;
    };
    var trocarLinhas = function (html) { fecharForm(); corpo.innerHTML = html; atualizar(); };

    // --- Ordem ----------------------------------------------------------------------------
    var ordemAtual = function () { return linhas().map(function (l) { return l.getAttribute('data-id'); }); };
    var aplicarOrdem = function (ids) { ids.forEach(function (id) { corpo.appendChild($('[data-ct-linha][data-id="' + id + '"]', corpo)); }); atualizar(); };
    var salvarOrdem = function (antes) {
        atualizar();
        postar({ op: 'ordem', ids: ordemAtual() })
            .then(function () { avisar('Ordem salva.'); })
            .catch(function () { aplicarOrdem(antes); avisar(FALHOU, null, true); });
    };
    corpo.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-ct-mover]');
        if (!b) { return; }
        var l = b.closest('[data-ct-linha]');
        var antes = ordemAtual();
        var dir = +b.getAttribute('data-ct-mover');
        var viz = dir < 0 ? l.previousElementSibling : l.nextElementSibling;
        if (!viz) { return; }
        if (dir < 0) { corpo.insertBefore(l, viz); } else { corpo.insertBefore(viz, l); }
        salvarOrdem(antes);
        var mesmo = $('[data-ct-mover="' + dir + '"]', l);
        (mesmo.disabled ? $('[data-ct-mover]:not(:disabled)', l) : mesmo).focus();
    });

    var arrastando = null, ordemAntes = null;
    corpo.addEventListener('pointerdown', function (ev) {
        var alca = ev.target.closest('[data-ct-alca]');
        if (alca) { alca.closest('[data-ct-linha]').draggable = true; }
    });
    corpo.addEventListener('dragstart', function (ev) {
        var l = ev.target.closest && ev.target.closest('[data-ct-linha]');
        if (!l || !l.draggable) { ev.preventDefault(); return; }
        arrastando = l;
        ordemAntes = ordemAtual();
        l.classList.add('is-arrastando');
        try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', l.getAttribute('data-id')); } catch (e) {}
    });
    corpo.addEventListener('dragover', function (ev) {
        if (!arrastando) { return; }
        ev.preventDefault();
        var alvo = ev.target.closest('[data-ct-linha]');
        if (!alvo || alvo === arrastando) { return; }
        var r = alvo.getBoundingClientRect();
        corpo.insertBefore(arrastando, ev.clientY < r.top + r.height / 2 ? alvo : alvo.nextElementSibling);
    });
    corpo.addEventListener('drop', function (ev) { if (arrastando) { ev.preventDefault(); } });
    corpo.addEventListener('dragend', function () {
        if (!arrastando) { return; }
        arrastando.classList.remove('is-arrastando');
        arrastando.draggable = false;
        arrastando = null;
        if (ordemAtual().join() !== ordemAntes.join()) { salvarOrdem(ordemAntes); }
    });

    // --- Ligada / Desligada -------------------------------------------------------------------
    var pintarAtivo = function (l, on) {
        l.setAttribute('data-ativo', on ? '1' : '0');
        l.classList.toggle('is-oculta', !on);
        $('[data-ct-ativo]', l).setAttribute('aria-checked', on ? 'true' : 'false');
        $('[data-ct-ativo-txt]', l).textContent = on ? 'Ligada' : 'Desligada';
    };
    var mudarAtivo = function (l, on, comDesfazer) {
        pintarAtivo(l, on);
        return postar({ op: 'ativo', id: l.getAttribute('data-id'), ativo: on ? '1' : '0' })
            .then(function () {
                avisar('“' + nomeDe(l) + '” ' + (on ? 'ligada.' : 'desligada.'), comDesfazer ? function () { mudarAtivo(l, !on, false); } : null);
            })
            .catch(function () { pintarAtivo(l, !on); avisar(FALHOU, null, true); });
    };
    corpo.addEventListener('click', function (ev) {
        var sw = ev.target.closest('[data-ct-ativo]');
        if (!sw) { return; }
        var l = sw.closest('[data-ct-linha]');
        mudarAtivo(l, l.getAttribute('data-ativo') !== '1', true);
    });

    // --- Formulário na linha -----------------------------------------------------------------
    var modelo = $('[data-ct-modelo]');
    var aberto = null;   // { form, linha (null = nova), lugar }
    var fecharForm = function () {
        if (!aberto) { return; }
        aberto.lugar.innerHTML = '';
        aberto.lugar.hidden = true;
        if (aberto.linha) {
            aberto.linha.classList.remove('is-aberta');
            var b = $('[data-ct-editar]', aberto.linha);
            b.textContent = 'Editar';
            b.setAttribute('aria-expanded', 'false');
            b.focus();
        } else {
            $('[data-ct-nova]').hidden = true;
        }
        aberto = null;
    };
    var abrirForm = function (linha) {
        fecharForm();
        var lugar = linha ? $('[data-ct-inline]', linha) : $('[data-ct-nova-form]');
        lugar.appendChild(modelo.content.cloneNode(true));
        lugar.hidden = false;
        var f = $('form', lugar);
        var novo = !linha;
        var campo = function (n) { return f.elements[n]; };
        var salvar = $('[data-ct-salvar]', f);
        aberto = { form: f, linha: linha, lugar: lugar };

        campo('nome').value = novo ? '' : nomeDe(linha);
        campo('category_id').value = novo ? CAT_PADRAO : linha.getAttribute('data-cat');
        campo('encomendas_ate').value = novo ? '' : linha.getAttribute('data-ate');
        campo('entrega_em').value = novo ? '' : linha.getAttribute('data-entrega');
        campo('observacao').value = novo ? '' : linha.getAttribute('data-obs');
        if (campo('abre_em')) { campo('abre_em').value = novo ? '' : linha.getAttribute('data-abre'); }
        var n = novo ? 0 : +linha.getAttribute('data-n');
        if (n > 0) {
            // Com produtos, a categoria fica (os produtos são dela).
            campo('category_id').disabled = true;
            $('[data-co-cat-ajuda]', f).textContent = 'Para trocar a categoria, tire primeiro os ' + (n === 1 ? '1 produto' : n + ' produtos') + ' desta coleção.';
        }
        var on = novo ? true : linha.getAttribute('data-ativo') === '1';
        $('[data-ct-f-sw]', f).setAttribute('aria-checked', on ? 'true' : 'false');
        $('[data-ct-f-ativo]', f).value = on ? '1' : '0';
        salvar.textContent = novo ? 'Criar coleção' : 'Salvar';
        if (novo) { $('[data-ct-perigo]', f).remove(); }

        // Capa: enviada na hora para uma pasta temporária; entra na coleção ao salvar.
        var capa = { tmp: '', remover: false, url: novo ? '' : linha.getAttribute('data-capa'), enviando: false };
        var cImg = $('[data-co-capa-img]', f);
        var pintarNome = function () {};
        if (cImg) {
            var foto = $('[data-co-capa-foto]', f), arq = $('[data-co-capa-arq]', f), escolher = $('[data-co-capa-escolher]', f);
            var rem = $('[data-co-capa-remover]', f), conf = $('[data-co-capa-conf]', f), cErro = $('[data-ct-erro="capa"]', f);
            campo('capa_alt').value = novo ? '' : linha.getAttribute('data-capa-alt');
            pintarNome = function () { $('[data-co-capa-nome]', f).textContent = campo('nome').value.trim() || 'Nome da coleção'; };
            var pintarCapa = function () {
                var u = capa.remover ? '' : capa.url;
                if (u) { foto.src = u; } else { foto.removeAttribute('src'); }
                foto.hidden = !u;
                cImg.classList.toggle('is-vazia', !u);
                escolher.textContent = u ? 'Trocar imagem' : 'Escolher imagem';
                rem.hidden = !u;
                conf.hidden = true;
            };
            var erroCapa = function (msg) { cErro.textContent = msg || ''; cErro.hidden = !msg; };
            escolher.addEventListener('click', function () { arq.click(); });
            arq.addEventListener('change', function () {
                var file = arq.files && arq.files[0];
                arq.value = '';
                if (!file) { return; }
                erroCapa('');
                if (file.size > 5 * 1024 * 1024) { erroCapa('A imagem excede o tamanho máximo de 5 MB.'); return; }
                var fd = new FormData();
                fd.append('_csrf', CSRF);
                fd.append('op', 'capa');
                fd.append('arquivo', file);
                capa.enviando = true;
                escolher.disabled = true;
                $('[data-co-capa-enviando]', f).hidden = false;
                conferir();
                fetch(URL_POST, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) {
                        return r.json().catch(function () { return { ok: false }; }).then(function (d) {
                            if (!r.ok || !d.ok) { throw new Error(d.mensagem || 'Não foi possível enviar a imagem. Tente de novo.'); }
                            return d;
                        });
                    })
                    .then(function (d) { capa.tmp = d.tmp; capa.url = d.url; capa.remover = false; pintarCapa(); })
                    .catch(function (e) { erroCapa(e.message); })
                    .then(function () {
                        capa.enviando = false;
                        escolher.disabled = false;
                        $('[data-co-capa-enviando]', f).hidden = true;
                        conferir();
                    });
            });
            rem.addEventListener('click', function () { rem.hidden = true; conf.hidden = false; $('[data-co-capa-remover-sim]', f).focus(); });
            $('[data-co-capa-remover-nao]', f).addEventListener('click', function () { conf.hidden = true; rem.hidden = false; rem.focus(); });
            $('[data-co-capa-remover-sim]', f).addEventListener('click', function () {
                capa.tmp = '';
                capa.remover = true;
                pintarCapa();
                escolher.focus();
            });
            pintarCapa();
            pintarNome();
        }

        var repetido = function () {
            var k = campo('nome').value.trim().toLocaleLowerCase('pt-BR');
            return !!k && linhas().some(function (l) { return l !== linha && nomeDe(l).trim().toLocaleLowerCase('pt-BR') === k; });
        };
        var conferir = function () {
            var dup = repetido();
            $('[data-ct-dup]', f).hidden = !dup;
            var ate = campo('encomendas_ate').value, ent = campo('entrega_em').value;
            $('[data-co-passou]', f).hidden = !ate || ate >= HOJE;
            salvar.disabled = !campo('nome').value.trim() || dup || !ate || !ent || capa.enviando;
        };
        var limparErro = function (k) { var s = $('[data-ct-erro="' + k + '"]', f); if (s) { s.hidden = true; } };
        f.addEventListener('input', function (ev) { if (ev.target.name) { limparErro(ev.target.name); } pintarNome(); conferir(); });
        f.addEventListener('change', function (ev) { if (ev.target.name) { limparErro(ev.target.name); } conferir(); });
        $('[data-ct-f-sw]', f).addEventListener('click', function () {
            var sw = $('[data-ct-f-sw]', f), v = sw.getAttribute('aria-checked') !== 'true';
            sw.setAttribute('aria-checked', v ? 'true' : 'false');
            $('[data-ct-f-ativo]', f).value = v ? '1' : '0';
        });
        $('[data-ct-cancelar]', f).addEventListener('click', fecharForm);
        f.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (salvar.disabled) { return; }
            var ate = campo('encomendas_ate').value, ent = campo('entrega_em').value;
            if (ent < ate) {
                var s = $('[data-ct-erro="entrega_em"]', f);
                s.textContent = 'A entrega não pode ser antes da data limite para encomendar.';
                s.hidden = false;
                campo('entrega_em').focus();
                return;
            }
            var abre = campo('abre_em') ? campo('abre_em').value : '';
            if (abre && abre > ate) {
                var sa = $('[data-ct-erro="abre_em"]', f);
                sa.textContent = 'O início da campanha não pode ser depois da data limite para encomendar.';
                sa.hidden = false;
                campo('abre_em').focus();
                return;
            }
            salvar.disabled = true;
            var dados = {
                op: 'salvar', id: novo ? '0' : linha.getAttribute('data-id'), nome: campo('nome').value.trim(),
                category_id: campo('category_id').value, encomendas_ate: ate, entrega_em: ent,
                observacao: campo('observacao').value.trim(), ativo: $('[data-ct-f-ativo]', f).value
            };
            if (campo('abre_em')) { dados.abre_em = abre; }
            if (cImg) {
                dados.capa_tmp = capa.tmp;
                dados.capa_remover = capa.remover ? '1' : '';
                dados.capa_alt = campo('capa_alt').value.trim();
            }
            postar(dados)
                .then(function (d) {
                    trocarLinhas(d.linhas);
                    avisar(d.mensagem);
                    var l = $('[data-ct-linha][data-id="' + d.id + '"]', corpo);
                    if (l) { $('[data-ct-editar]', l).focus(); }
                })
                .catch(function (e) {
                    var erros = (e.dados && e.dados.erros) || {};
                    var primeiro = null;
                    Object.keys(erros).forEach(function (k) {
                        var s = $('[data-ct-erro="' + k + '"]', f);
                        if (s) { s.textContent = erros[k]; s.hidden = false; }
                        if (!primeiro && campo(k)) { primeiro = campo(k); }
                    });
                    if (primeiro) { primeiro.focus(); }
                    avisar(e.message, null, true);
                    conferir();
                });
        });

        if (!novo) {
            var conf = $('[data-ct-confirmar]', f), sim = $('[data-ct-excluir-sim]', f);
            $('[data-ct-excluir]', f).addEventListener('click', function () {
                $('[data-ct-excluir]', f).hidden = true;
                conf.hidden = false;
                $('[data-ct-conf-titulo]', f).textContent = n
                    ? 'Excluir “' + nomeDe(linha) + '”? ' + (n === 1 ? 'O produto sai' : 'Os ' + n + ' produtos saem') + ' da coleção e ' + (n === 1 ? 'volta' : 'voltam') + ' ao prazo de produção.'
                    : 'Excluir “' + nomeDe(linha) + '”?';
                sim.focus();
            });
            $('[data-ct-excluir-nao]', f).addEventListener('click', function () {
                conf.hidden = true;
                $('[data-ct-excluir]', f).hidden = false;
                $('[data-ct-excluir]', f).focus();
            });
            sim.addEventListener('click', function () {
                sim.disabled = true;
                postar({ op: 'excluir', id: linha.getAttribute('data-id') })
                    .then(function (d) {
                        trocarLinhas(d.linhas);
                        avisar(d.mensagem, function () {
                            postar({ op: 'desfazer', id: d.id })
                                .then(function (r) { trocarLinhas(r.linhas); avisar(r.mensagem); })
                                .catch(function (e) { avisar(e.message, null, true); });
                        });
                    })
                    .catch(function (e) { sim.disabled = false; avisar(e.message, null, true); });
            });
        }

        if (linha) {
            linha.classList.add('is-aberta');
            var b = $('[data-ct-editar]', linha);
            b.textContent = 'Fechar';
            b.setAttribute('aria-expanded', 'true');
        } else {
            $('[data-ct-nova]').hidden = false;
        }
        conferir();
        campo('nome').focus();
    };

    corpo.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-ct-editar]');
        if (!b) { return; }
        var l = b.closest('[data-ct-linha]');
        if (aberto && aberto.linha === l) { fecharForm(); } else { abrirForm(l); }
    });
    var botaoNova = document.querySelector('[data-ct-nova-btn]');
    if (botaoNova) {
        botaoNova.addEventListener('click', function () {
            abrirForm(null);
            $('[data-ct-nova]').scrollIntoView({ block: 'nearest' });
        });
    }
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && aberto) { fecharForm(); } });

    atualizar();
})();
