/* =============================================================================
   Admin › Novo/Editar produto: prévia ao vivo, chaves, slug, fotos (envio na hora,
   ordem, remover com "Desfazer"), tabelas nutricionais e barra de salvar.
   Salvar envia tudo por fetch; o servidor grava numa transação e devolve os erros
   por campo. Fotos novas ficam temporárias (token de rascunho) até salvar.
   ============================================================================= */
(function () {
    'use strict';

    var form = document.querySelector('[data-pe]');
    var bloco = document.getElementById('pe-dados');
    if (!form || !bloco) { return; }
    var D = JSON.parse(bloco.textContent);
    var $ = function (s) { return form.querySelector(s); };
    var $$ = function (s) { return Array.prototype.slice.call(form.querySelectorAll(s)); };

    var campo = {
        nome: $('#pe-nome'), cat: $('#pe-cat'), preco: $('#pe-preco'), dias: $('#pe-dias'),
        slug: $('#pe-slug'), desc: $('#pe-desc'), regras: $('#pe-regras')
    };
    var fotos = D.fotos.map(function (f) { return { chave: f.chave, url: f.url, estado: 'ok' }; });
    var ligadas = D.ligadas.slice();
    var slugManual = !D.novo;          // produto existente: o slug não muda sozinho
    var sujo = false;
    var salvando = false;

    // --- Utilidades ----------------------------------------------------------------
    var esc = function (t) {
        return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    };
    var slugify = function (t) {
        return String(t).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 140);
    };
    var PRECO_OK = /^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/;
    var centavos = function (txt) {
        txt = txt.trim();
        if (!PRECO_OK.test(txt)) { return 0; }
        var n = txt.indexOf(',') !== -1 ? txt.replace(/\./g, '').replace(',', '.') : txt;
        return Math.round(parseFloat(n) * 100);
    };
    var brl = function (c) { return (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    var chaveLigada = function (nome) { return $('[data-pe-sw="' + nome + '"]').getAttribute('aria-checked') === 'true'; };

    // --- Aviso (com "Desfazer" opcional) --------------------------------------------
    var toast = $('[data-pe-toast]');
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

    // --- Barra de salvar --------------------------------------------------------------
    var estado = $('[data-pe-estado]');
    var botaoSalvar = $('[data-pe-salvar]');
    var atualizarBarra = function () {
        estado.classList.toggle('is-sujo', sujo);
        $('[data-pe-estado-txt]').textContent = salvando ? 'Salvando…'
            : (sujo ? 'Alterações não salvas' : (D.novo ? 'Preencha os dados do produto' : 'Tudo salvo'));
        botaoSalvar.disabled = !sujo || salvando;
    };
    var marcarSujo = function () { sujo = true; atualizarBarra(); };
    window.addEventListener('beforeunload', function (ev) {
        if (sujo) { ev.preventDefault(); ev.returnValue = ''; }
    });

    // --- Erros por campo ----------------------------------------------------------------
    var CAMPO_ERRO = { nome: campo.nome, category_id: campo.cat, preco: campo.preco, dias_producao: campo.dias,
        descricao: campo.desc, regras_produto: campo.regras, fotos: null };
    var limparErro = function (nome) {
        var span = $('[data-pe-erro="' + nome + '"]');
        if (span) { span.hidden = true; span.textContent = ''; }
        if (CAMPO_ERRO[nome]) { CAMPO_ERRO[nome].removeAttribute('aria-invalid'); }
    };
    var mostrarErros = function (erros) {
        Object.keys(CAMPO_ERRO).forEach(limparErro);
        var primeiro = null;
        Object.keys(erros).forEach(function (nome) {
            var span = $('[data-pe-erro="' + nome + '"]');
            if (span) { span.textContent = erros[nome]; span.hidden = false; }
            var el = CAMPO_ERRO[nome];
            if (el) { el.setAttribute('aria-invalid', 'true'); }
            if (!primeiro) { primeiro = el || span; }
        });
        if (primeiro) {
            var det = primeiro.closest('details');
            if (det) { det.open = true; }
            primeiro.scrollIntoView({ block: 'center', behavior: 'smooth' });
            if (primeiro.focus) { primeiro.focus({ preventScroll: true }); }
        }
    };

    // --- Prévia ----------------------------------------------------------------------
    var prevImg = $('[data-pe-previa-img]');
    var atualizarPrevia = function () {
        var nome = campo.nome.value.trim();
        $('[data-pe-previa-nome]').textContent = nome || 'Sem nome';
        var c = centavos(campo.preco.value);
        $('[data-pe-previa-preco]').textContent = c > 0 ? brl(c) : (chaveLigada('permite_personalizacao') ? 'Sob consulta' : '—');
        var cat = campo.cat.options[campo.cat.selectedIndex];
        var dias = campo.dias.value.trim();
        $('[data-pe-previa-linha]').textContent = (campo.cat.value ? cat.text : 'Sem categoria')
            + ' · produção em ' + (dias === '' ? '0' : dias) + ' ' + (dias === '1' ? 'dia útil' : 'dias úteis');
        var capa = fotos.length ? fotos[0] : null;
        var img = prevImg.querySelector('img');
        if (capa && capa.estado !== 'erro') {
            if (!img) { img = document.createElement('img'); img.alt = ''; prevImg.insertBefore(img, prevImg.firstChild); }
            if (img.getAttribute('src') !== capa.url) { img.src = capa.url; }
        } else if (img) {
            img.remove();
        }
        prevImg.querySelector('.pe-previa-vazia').hidden = !!(capa && capa.estado !== 'erro');
        $('[data-pe-previa-destaque]').hidden = !chaveLigada('destaque');
        $('[data-pe-previa-oculto]').hidden = chaveLigada('ativo');
    };

    // --- Informações: nome, slug, preço, prazo -------------------------------------------
    var titulo = $('[data-pe-titulo]');
    campo.nome.addEventListener('input', function () {
        if (!slugManual) { campo.slug.value = slugify(campo.nome.value); }
        if (!D.novo) { titulo.textContent = campo.nome.value.trim() || 'Sem nome'; }
        $('[data-pe-nome-repetido]').hidden = true;
    });
    campo.slug.addEventListener('input', function () { slugManual = true; });
    campo.slug.addEventListener('blur', function () { if (campo.slug.value.trim()) { campo.slug.value = slugify(campo.slug.value); } });
    $('[data-pe-gerar-slug]').addEventListener('click', function () {
        campo.slug.value = slugify(campo.nome.value);
        slugManual = false;
        marcarSujo();
    });
    // Aviso (não bloqueia): já existe outro produto com este nome.
    campo.nome.addEventListener('blur', function () {
        var nome = campo.nome.value.trim();
        if (nome.length < 2) { return; }
        var corpo = new URLSearchParams({ op: 'nome_existe', _csrf: D.csrf, nome: nome, id: String(D.id) });
        fetch(D.urlPost, { method: 'POST', credentials: 'same-origin', body: corpo, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { $('[data-pe-nome-repetido]').hidden = !(d && d.existe); })
            .catch(function () {});
    });

    var ajustarPreco = function () {
        var pers = chaveLigada('permite_personalizacao');
        campo.preco.required = !pers;
        campo.preco.placeholder = pers ? 'Sob consulta' : '0,00';
        $('[data-pe-preco-ajuda]').textContent = pers
            ? 'Opcional: sem preço, a loja mostra “Sob consulta” e o botão do WhatsApp.'
            : 'Obrigatório para produtos que não aceitam personalização.';
    };

    // Qualquer edição: marca como não salvo, limpa o erro do campo e atualiza a prévia.
    form.addEventListener('input', function (ev) {
        var n = ev.target.name;
        if (!n || ev.target.matches('[data-pe-tq]')) { return; }
        limparErro(n);
        marcarSujo();
        atualizarPrevia();
        if (ev.target.hasAttribute('data-pe-contar')) { contar(ev.target); }
    });
    form.addEventListener('change', function (ev) {
        if (ev.target === campo.cat) { limparErro('category_id'); marcarSujo(); atualizarPrevia(); }
    });

    // Contadores "N / máx".
    var contar = function (ta) {
        var c = $('[data-pe-contador="' + ta.id + '"]');
        if (c) { c.textContent = ta.value.length + ' / ' + ta.maxLength; }
    };
    $$('[data-pe-contar]').forEach(contar);

    // --- Chaves (switch) -----------------------------------------------------------------
    $$('[data-pe-sw]').forEach(function (sw) {
        sw.addEventListener('click', function () {
            var ligado = sw.getAttribute('aria-checked') !== 'true';
            sw.setAttribute('aria-checked', ligado ? 'true' : 'false');
            $('[data-pe-sw-campo="' + sw.getAttribute('data-pe-sw') + '"]').value = ligado ? '1' : '0';
            if (sw.getAttribute('data-pe-sw') === 'permite_personalizacao') { ajustarPreco(); limparErro('preco'); }
            marcarSujo();
            atualizarPrevia();
        });
    });

    // --- Fotos ----------------------------------------------------------------------------
    var grade = $('[data-pe-fotos]');
    var arquivoInput = $('[data-pe-arquivo]');
    var TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
    var LIMITE = 5 * 1024 * 1024;
    var validas = function () { return fotos.filter(function (f) { return f.estado !== 'erro'; }).length; };

    var desenharFotos = function () {
        var n = fotos.length;
        var html = fotos.map(function (f, i) {
            var rot = 'foto ' + (i + 1);
            return '<div class="pe-foto' + (f.estado === 'enviando' ? ' is-enviando' : '') + (f.estado === 'erro' ? ' is-erro' : '')
                + '" draggable="true" data-i="' + i + '">'
                + (f.url ? '<img src="' + esc(f.url) + '" alt="Foto ' + (i + 1) + '">' : '<span class="pe-foto-sem" aria-hidden="true">!</span>')
                + (i === 0 && f.estado !== 'erro' ? '<span class="pe-foto-capa">Capa</span>' : '')
                + (f.estado === 'enviando' ? '<span class="pe-foto-msg">Enviando…</span>' : '')
                + (f.estado === 'erro'
                    ? '<div class="pe-foto-falha" role="alert"><span>' + esc(f.erro || 'Não foi possível enviar.') + '</span>'
                      + (f.arquivo && f.podeRepetir ? '<button type="button" data-pe-foto-repetir="' + i + '">Tentar de novo</button>' : '')
                      + '<button type="button" data-pe-foto-tirar="' + i + '">Tirar</button></div>'
                    : '<div class="pe-foto-acoes"><span><button type="button" data-pe-foto-mover="' + i + ':-1" aria-label="Mover ' + rot + ' para a esquerda"' + (i === 0 ? ' disabled' : '') + '>←</button>'
                      + '<button type="button" data-pe-foto-mover="' + i + ':1" aria-label="Mover ' + rot + ' para a direita"' + (i === n - 1 ? ' disabled' : '') + '>→</button></span>'
                      + '<button type="button" class="pe-foto-x" data-pe-foto-remover="' + i + '" aria-label="Remover ' + rot + '">✕</button></div>')
                + '</div>';
        }).join('');
        if (validas() < D.max) {
            html += '<button type="button" class="pe-foto-add" data-pe-foto-add><b>+ Adicionar fotos</b>'
                + '<span>Clique ou arraste aqui. JPG, PNG ou WebP, até 5 MB.</span></button>';
        }
        grade.innerHTML = html;
        $('[data-pe-fotos-conta]').textContent = validas() + ' de ' + D.max + ' · a primeira é a capa';
        atualizarPrevia();
    };

    var enviarFoto = function (f) {
        f.estado = 'enviando';
        desenharFotos();
        var fd = new FormData();
        fd.append('op', 'foto_enviar');
        fd.append('_csrf', D.csrf);
        fd.append('rascunho', D.rascunho);
        fd.append('foto', f.arquivo);
        fetch(D.urlPost, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.mensagem) || 'Não foi possível enviar.'); }
                if (f.local) { URL.revokeObjectURL(f.local); f.local = null; }
                f.chave = d.chave; f.url = d.url; f.estado = 'ok'; f.arquivo = null;
            })
            .catch(function (e) { f.estado = 'erro'; f.erro = e.message || 'Não foi possível enviar.'; f.podeRepetir = true; })
            .then(desenharFotos);
    };

    var adicionarArquivos = function (lista) {
        var cabem = D.max - validas();
        var ignoradas = 0;
        Array.prototype.forEach.call(lista, function (arq) {
            if (cabem <= 0) { ignoradas++; return; }
            var f = { chave: null, url: '', estado: 'enviando', arquivo: arq };
            if (TIPOS.indexOf(arq.type) === -1) {
                f.estado = 'erro'; f.erro = '“' + arq.name + '”: use JPG, PNG ou WebP.';
            } else if (arq.size > LIMITE) {
                f.estado = 'erro'; f.erro = '“' + arq.name + '” tem mais de 5 MB.';
            } else {
                f.local = URL.createObjectURL(arq);
                f.url = f.local;
                cabem--;
            }
            fotos.push(f);
            if (f.estado === 'enviando') { enviarFoto(f); }
        });
        if (ignoradas) { avisar('Limite de ' + D.max + ' fotos: ' + ignoradas + ' não ' + (ignoradas === 1 ? 'entrou' : 'entraram') + '.'); }
        limparErro('fotos');
        marcarSujo();
        desenharFotos();
    };

    grade.addEventListener('click', function (ev) {
        var b;
        if (ev.target.closest('[data-pe-foto-add]')) { arquivoInput.click(); return; }
        if ((b = ev.target.closest('[data-pe-foto-mover]'))) {
            var p = b.getAttribute('data-pe-foto-mover').split(':');
            var i = +p[0], j = i + (+p[1]);
            var t = fotos[i]; fotos[i] = fotos[j]; fotos[j] = t;
            marcarSujo(); desenharFotos();
            var alvo = grade.querySelector('[data-pe-foto-mover="' + j + ':' + p[1] + '"]') || grade.querySelector('[data-pe-foto-mover^="' + j + ':"]:not(:disabled)');
            if (alvo) { alvo.focus(); }
            return;
        }
        if ((b = ev.target.closest('[data-pe-foto-remover]'))) {
            var k = +b.getAttribute('data-pe-foto-remover');
            var tirada = fotos.splice(k, 1)[0];
            marcarSujo(); desenharFotos();
            avisar('Foto removida.', function () {
                fotos.splice(Math.min(k, fotos.length), 0, tirada);
                desenharFotos();
            });
            return;
        }
        if ((b = ev.target.closest('[data-pe-foto-repetir]'))) { enviarFoto(fotos[+b.getAttribute('data-pe-foto-repetir')]); return; }
        if ((b = ev.target.closest('[data-pe-foto-tirar]'))) {
            var f = fotos.splice(+b.getAttribute('data-pe-foto-tirar'), 1)[0];
            if (f.local) { URL.revokeObjectURL(f.local); }
            desenharFotos();
        }
    });
    arquivoInput.addEventListener('change', function () {
        if (arquivoInput.files.length) { adicionarArquivos(arquivoInput.files); }
        arquivoInput.value = '';
    });

    // Arrastar: fotos da grade (reordenar) ou arquivos do computador (adicionar).
    var arrastando = null;
    grade.addEventListener('dragstart', function (ev) {
        var t = ev.target.closest('.pe-foto');
        if (!t) { return; }
        arrastando = +t.getAttribute('data-i');
        t.classList.add('is-arrastando');
        try { ev.dataTransfer.setData('text/plain', String(arrastando)); ev.dataTransfer.effectAllowed = 'move'; } catch (e) {}
    });
    grade.addEventListener('dragend', function () { arrastando = null; $$('.pe-foto.is-arrastando').forEach(function (t) { t.classList.remove('is-arrastando'); }); });
    grade.addEventListener('dragover', function (ev) {
        var temArquivos = ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types || [], 'Files') !== -1;
        if (arrastando !== null || temArquivos) {
            ev.preventDefault();
            var add = grade.querySelector('[data-pe-foto-add]');
            if (add) { add.classList.toggle('is-sobre', temArquivos); }
        }
    });
    grade.addEventListener('dragleave', function (ev) {
        if (!grade.contains(ev.relatedTarget)) {
            var add = grade.querySelector('[data-pe-foto-add]');
            if (add) { add.classList.remove('is-sobre'); }
        }
    });
    grade.addEventListener('drop', function (ev) {
        ev.preventDefault();
        if (arrastando === null) {
            if (ev.dataTransfer && ev.dataTransfer.files.length) { adicionarArquivos(ev.dataTransfer.files); }
            return;
        }
        var alvo = ev.target.closest('.pe-foto');
        var para = alvo ? +alvo.getAttribute('data-i') : fotos.length - 1;
        if (para !== arrastando) {
            var f = fotos.splice(arrastando, 1)[0];
            fotos.splice(para, 0, f);
            marcarSujo();
        }
        arrastando = null;
        desenharFotos();
    });

    // --- Tabelas nutricionais -------------------------------------------------------------
    var chips = $('[data-pe-chips]');
    var tlista = $('[data-pe-tlista]');
    var tq = $('[data-pe-tq]');
    var nomeTabela = function (id) {
        var t = D.tabelas.filter(function (x) { return x.id === id; })[0];
        return t ? t.nome : '';
    };
    var desenharTabelas = function () {
        chips.innerHTML = ligadas.map(function (id) {
            return '<span class="pe-chip">' + esc(nomeTabela(id)) + '<button type="button" data-pe-tirar-tabela="' + id + '" aria-label="Tirar ' + esc(nomeTabela(id)) + '">✕</button></span>';
        }).join('');
        chips.hidden = !ligadas.length;
        $('[data-pe-sem-tabela]').hidden = ligadas.length > 0;
        var q = tq.value.trim().toLocaleLowerCase('pt-BR');
        var vis = D.tabelas.filter(function (t) { return !q || t.nome.toLocaleLowerCase('pt-BR').indexOf(q) !== -1; });
        tlista.innerHTML = vis.length ? vis.map(function (t) {
            var on = ligadas.indexOf(t.id) !== -1;
            return '<li><button type="button" data-pe-tabela="' + t.id + '" aria-pressed="' + on + '">' + esc(t.nome)
                + '<span>' + (on ? '✓ Ligada' : '+ Adicionar') + '</span></button></li>';
        }).join('') : '<li class="pe-ajuda pe-picker-vazio">' + (D.tabelas.length ? 'Nenhuma tabela com esse nome.' : 'Nenhuma tabela cadastrada ainda.') + '</li>';
    };
    tq.addEventListener('input', desenharTabelas);
    form.addEventListener('click', function (ev) {
        var b;
        if ((b = ev.target.closest('[data-pe-tabela]'))) {
            var id = +b.getAttribute('data-pe-tabela');
            var i = ligadas.indexOf(id);
            if (i === -1) { ligadas.push(id); } else { ligadas.splice(i, 1); }
            marcarSujo(); desenharTabelas();
            var mesmo = tlista.querySelector('[data-pe-tabela="' + id + '"]');
            if (mesmo) { mesmo.focus(); }
            return;
        }
        if ((b = ev.target.closest('[data-pe-tirar-tabela]'))) {
            var tid = +b.getAttribute('data-pe-tirar-tabela');
            ligadas = ligadas.filter(function (x) { return x !== tid; });
            marcarSujo(); desenharTabelas();
        }
    });
    // Voltou para esta aba (ex.: criou uma tabela em outra aba): recarrega a lista.
    var recarregarTabelas = function () {
        fetch(D.urlTabelas, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                D.tabelas = d.tabelas;
                var existe = {};
                d.tabelas.forEach(function (t) { existe[t.id] = true; });
                ligadas = ligadas.filter(function (id) { return existe[id]; });
                desenharTabelas();
            })
            .catch(function () {});
    };
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { recarregarTabelas(); } });

    // --- Salvar ----------------------------------------------------------------------------
    var validar = function () {
        var e = {};
        var nome = campo.nome.value.trim();
        if (nome.length < 2) { e.nome = 'Informe o nome do produto.'; }
        if (!campo.cat.value) { e.category_id = 'Escolha a categoria.'; }
        var p = campo.preco.value.trim();
        if (p === '') {
            if (!chaveLigada('permite_personalizacao')) { e.preco = 'Informe o preço (ou ligue “Aceita personalização”).'; }
        } else if (centavos(p) <= 0 || centavos(p) > 99999999) {
            e.preco = 'Preço inválido. Use, por exemplo, 35,90.';
        }
        if (campo.dias.value.trim() !== '' && !/^\d{1,4}$/.test(campo.dias.value.trim())) { e.dias_producao = 'Use um número inteiro de dias (0 ou mais).'; }
        if (campo.desc.value.length > 1200) { e.descricao = 'Use no máximo 1200 caracteres.'; }
        if (campo.regras.value.length > 600) { e.regras_produto = 'Use no máximo 600 caracteres.'; }
        return e;
    };

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (salvando || !sujo) { return; }
        if (fotos.some(function (f) { return f.estado === 'enviando'; })) { avisar('Aguarde o envio das fotos terminar.'); return; }
        if (fotos.some(function (f) { return f.estado === 'erro'; })) {
            mostrarErros({ fotos: 'Há fotos que não foram enviadas: tente de novo ou tire.' });
            return;
        }
        var erros = validar();
        if (Object.keys(erros).length) { mostrarErros(erros); avisar('Confira os campos destacados.', null, true); return; }

        var fd = new FormData(form);
        fotos.forEach(function (f) { fd.append('fotos[]', f.chave); });
        ligadas.forEach(function (id) { fd.append('tabelas[]', String(id)); });
        salvando = true;
        atualizarBarra();
        fetch(D.urlPost, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                salvando = false;
                if (!d || !d.ok) {
                    if (d && d.erros) { mostrarErros(d.erros); }
                    avisar((d && d.mensagem) || 'Não foi possível salvar. Tente de novo.', null, true);
                    atualizarBarra();
                    return;
                }
                sujo = false;
                if (d.redirecionar) { location.href = d.redirecionar; return; }
                // Edição: fotos com os ids definitivos, endereço final e "última alteração".
                fotos = d.fotos.map(function (f) { return { chave: f.chave, url: f.url, estado: 'ok' }; });
                campo.slug.value = d.slug;
                D.urlLoja = d.url_loja;
                $$('[data-pe-ver-loja]').forEach(function (a) { a.href = d.url_loja; });
                var quando = $('[data-pe-quando]');
                if (quando) { quando.textContent = d.quando; }
                var st = $('[data-pe-status-loja]');
                if (st) { st.textContent = chaveLigada('ativo') ? 'Na loja' : 'Oculto'; }
                var vo = $('[data-pe-ver-oculto]');
                if (vo) { vo.hidden = chaveLigada('ativo'); }
                desenharFotos();
                atualizarBarra();
                avisar('Produto salvo.' + (d.aviso_slug ? ' ' + d.aviso_slug : ''));
            })
            .catch(function () {
                salvando = false;
                atualizarBarra();
                avisar('Não foi possível salvar. Confira a conexão e tente de novo.', null, true);
            });
    });
    // Ctrl/Cmd+S salva.
    document.addEventListener('keydown', function (ev) {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
            ev.preventDefault();
            if (!botaoSalvar.disabled) { form.requestSubmit ? form.requestSubmit() : botaoSalvar.click(); }
        }
    });

    ajustarPreco();
    desenharFotos();
    desenharTabelas();
    atualizarPrevia();
    atualizarBarra();
})();
