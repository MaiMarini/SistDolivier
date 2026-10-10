/* =============================================================================
   Admin › Home: mapa da home + edição da parte escolhida, num formulário só.
   O estado da tela mora em S (vem do servidor em #hm-dados); trocar de parte não
   perde nada. Arquivos sobem na hora para uma pasta temporária e só valem ao
   salvar. Salvar manda tudo de uma vez (o servidor grava numa transação).
   ============================================================================= */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-hm]');
    if (!raiz) { return; }
    var D = JSON.parse(document.getElementById('hm-dados').textContent);
    var L = D.limites;
    var $ = function (s, el) { return (el || raiz).querySelector(s); };
    var esc = function (t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var clone = function (x) { return JSON.parse(JSON.stringify(x)); };

    var PARTES = { banners: 'Banners', frases: 'Frases', col: 'Coleções', ed: 'Bloco editorial' };
    var S = D.estado;               // o que está na tela
    var base = clone(S);            // o que está salvo
    var ui = { parte: D.parte, aberto: null, confirmar: false, erros: {}, enviando: {}, salvando: false, excluidos: [], alvo: null };
    var seq = 0;

    // --- Alterações -----------------------------------------------------------------------------
    var parteJSON = function (st, k, excl) {
        if (k === 'banners') { return JSON.stringify([st.banners, excl]); }
        return JSON.stringify(st[k]);
    };
    var sujo = function (k) { return parteJSON(S, k, ui.excluidos) !== parteJSON(base, k, []); };
    var sujas = function () { return Object.keys(PARTES).filter(sujo); };
    var bolinha = function (k) { return sujo(k) ? '<i class="cf-bolinha" aria-hidden="true"></i><span class="ap-sr"> (não salvo)</span>' : ''; };
    var marca = function (k) { return '<span class="hm-marca" data-hm-marca>' + bolinha(k) + '</span>'; };

    // --- Utilidades -------------------------------------------------------------------------------
    var br = function (d) { return d ? d.split('-').reverse().join('/') : ''; };
    var noAr = function (b) {
        return b.ativo && !!b.img && (!b.inicio || b.inicio <= D.hoje) && (!b.fim || b.fim >= D.hoje);
    };
    var status = function (b) {
        if (!b.ativo) { return 'Desligado'; }
        if (!b.img) { return 'Falta a imagem'; }
        if (b.fim && b.fim < D.hoje) { return 'Período encerrado em ' + br(b.fim); }
        if (b.inicio && b.inicio > D.hoje) { return 'Começa em ' + br(b.inicio); }
        return b.fim ? 'No ar até ' + br(b.fim) : 'No ar';
    };
    var idDe = function (k) { return 'hm-' + k.replace(/[^a-z0-9]/gi, '-'); };
    var erro = function (k) {
        return ui.erros[k] ? '<span class="pe-erro" id="' + idDe(k) + '-erro" role="alert">' + esc(ui.erros[k]) + '</span>' : '';
    };
    var inv = function (k) { return ui.erros[k] ? ' aria-invalid="true" aria-describedby="' + idDe(k) + '-erro"' : ''; };
    var acharBanner = function (cid) {
        for (var i = 0; i < S.banners.length; i++) { if (S.banners[i].cid === cid) { return i; } }
        return -1;
    };
    var catNome = function (id) {
        for (var i = 0; i < D.categorias.length; i++) { if (D.categorias[i].id === id) { return D.categorias[i]; } }
        return null;
    };

    // --- Aviso (com "Desfazer") ---------------------------------------------------------------------
    var toast = $('[data-hm-toast]'), toastT, desfazer = null;
    var avisar = function (msg, fn, ruim) {
        clearTimeout(toastT);
        desfazer = fn || null;
        toast.innerHTML = esc(msg) + (fn ? '<button type="button" data-hm-desfazer>Desfazer</button>' : '');
        toast.classList.toggle('is-erro', !!ruim);
        toast.hidden = false;
        toastT = setTimeout(function () { toast.hidden = true; desfazer = null; }, fn ? 7000 : 4500);
    };
    toast.addEventListener('click', function (ev) {
        if (!ev.target.closest('[data-hm-desfazer]') || !desfazer) { return; }
        var fn = desfazer;
        desfazer = null;
        toast.hidden = true;
        fn();
    });

    // --- Campo "leva para" (banners e botão do bloco editorial) ------------------------------------
    var campoLink = function (k, link, rotulo) {
        var id = idDe(k);
        var cat = link.tipo === 'cat' ? catNome(link.cat) : null;
        var ops = D.categorias.filter(function (c) { return c.ativo || (link.tipo === 'cat' && c.id === link.cat); }).map(function (c) {
            return '<option value="cat:' + c.id + '"' + (link.tipo === 'cat' && link.cat === c.id ? ' selected' : '') + '>'
                + esc(c.nome) + (c.ativo ? '' : ' (desativada)') + '</option>';
        }).join('');
        var aviso = link.tipo === 'cat' && cat && !cat.ativo ? '<span class="pe-ajuda is-aviso">Essa categoria está desativada: na loja, o clique não leva a lugar nenhum.</span>' : '';
        return '<div class="pe-campo"><label for="' + id + '">' + esc(rotulo) + '</label>'
            + '<select id="' + id + '" data-hm-link="' + k + '"' + (link.tipo !== 'url' ? inv(k) : '') + '>'
            + '<option value=""' + (link.tipo === 'nenhum' ? ' selected' : '') + '>Não leva a lugar nenhum</option>'
            + '<optgroup label="Categoria">' + ops + '</optgroup>'
            + '<option value="url"' + (link.tipo === 'url' ? ' selected' : '') + '>Outro endereço…</option></select>'
            + (link.tipo === 'url' ? '<input type="url" id="' + id + '-url" data-hm-url="' + k + '" value="' + esc(link.url) + '" maxlength="' + L.url
                + '" placeholder="https://dolivier.com.br/produto/..." aria-label="Endereço"' + inv(k) + '>' : '')
            + aviso + erro(k) + '</div>';
    };

    // Miniatura com "Enviando…" por cima.
    var previa = function (alvo, conteudo, vazio, classe) {
        return '<div class="hm-prev' + (classe ? ' ' + classe : '') + '">' + (conteudo || '<span>' + esc(vazio) + '</span>')
            + (ui.enviando[alvo] ? '<span class="hm-enviando">Enviando…</span>' : '') + '</div>';
    };

    // --- Mapa ----------------------------------------------------------------------------------------
    var mapa = function () {
        var blk = function (k, classe, titulo, sub) {
            return '<button type="button" class="hm-blk ' + classe + '" data-hm-parte="' + k + '" aria-current="' + (ui.parte === k ? 'true' : 'false') + '">'
                + esc(titulo) + '<small>' + esc(sub) + '</small>' + bolinha(k) + '</button>';
        };
        var n = S.banners.filter(noAr).length;
        var f = S.frases.filter(function (x) { return x.trim() !== ''; }).length;
        $('[data-hm-mapa]').innerHTML = '<div class="hm-mapa-tit">A home, de cima para baixo</div>'
            + '<div class="hm-blk is-fixo">Menu e categorias</div>'
            + blk('banners', 'is-banners', 'Banners', n + ' no carrossel')
            + blk('frases', 'is-frases', 'Faixa de frases', f + (f === 1 ? ' frase' : ' frases'))
            + '<div class="hm-blk is-fixo">Mais vendidos</div>'
            + blk('ed', 'is-ed', 'Bloco editorial', S.ed.tipo === 'video' ? 'vídeo + texto' : 'foto + texto')
            + blk('col', 'is-col', 'Coleções', 'imagem lateral')
            + '<div class="hm-blk is-fixo">Instagram e rodapé</div>';
    };

    // --- Banners -------------------------------------------------------------------------------------
    var linhaTexto = function (b) {
        return (b.titulo.trim() ? '<b>' + esc(b.titulo) + '</b>' : '<b class="is-vazio">Sem título</b>') + '<small>' + esc(status(b)) + '</small>';
    };
    var formBanner = function (b) {
        var k = 'b:' + b.cid + ':', alvo = 'banner:' + b.cid;
        return '<div class="hm-bform">'
            + '<div class="hm-midia">' + previa(alvo, b.img ? '<img src="' + esc(b.img) + '" alt="">' : '', 'Sem imagem')
            + '<div class="hm-midia-lado"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="' + idDe(k + 'img') + '" data-hm-acao="up" data-alvo="' + alvo + '"'
            + inv(k + 'img') + (ui.enviando[alvo] ? ' disabled' : '') + '>' + (b.img ? 'Trocar imagem' : 'Escolher imagem') + '</button>'
            + '<span class="pe-ajuda">JPG ou PNG. Otimizamos o tamanho sozinhos.</span>' + erro(k + 'img') + '</div></div>'
            + '<div class="pe-campo"><label for="' + idDe(k + 'titulo') + '">Título <span class="pe-ajuda">(opcional)</span></label>'
            + '<input type="text" id="' + idDe(k + 'titulo') + '" data-hm-b="titulo" data-cid="' + b.cid + '" value="' + esc(b.titulo) + '" maxlength="' + L.banner + '" placeholder="Ex.: Dia dos avós"' + inv(k + 'titulo') + '>'
            + '<span class="pe-ajuda">Aparece escrito no canto de baixo da imagem e é lido por leitores de tela. Deixe vazio se a arte já tem o texto.</span>' + erro(k + 'titulo') + '</div>'
            + campoLink(k + 'url', b.link, 'Ao clicar, leva para')
            + '<div class="pe-grid2">'
            + '<div class="pe-campo"><label for="' + idDe(k + 'inicio') + '">Mostrar a partir de</label><input type="date" id="' + idDe(k + 'inicio') + '" data-hm-b="inicio" data-cid="' + b.cid + '" value="' + esc(b.inicio) + '"' + inv(k + 'inicio') + '>' + erro(k + 'inicio') + '</div>'
            + '<div class="pe-campo"><label for="' + idDe(k + 'fim') + '">Tirar do ar em</label><input type="date" id="' + idDe(k + 'fim') + '" data-hm-b="fim" data-cid="' + b.cid + '" value="' + esc(b.fim) + '"' + inv(k + 'fim') + '>' + erro(k + 'fim') + '</div>'
            + '</div><span class="pe-ajuda hm-periodo">Deixe vazio para ficar no ar sempre. Bom para datas como Dia dos avós ou Natal. O banner aparece até o fim do dia de “Tirar do ar em”.</span>'
            + '<div class="hm-perigo">' + (ui.confirmar
                ? '<div class="hm-confirma" role="group" aria-labelledby="hm-conf-' + b.cid + '"><b id="hm-conf-' + b.cid + '">Excluir este banner?</b><div class="hm-linha">'
                    + '<button type="button" class="ap-btn ap-btn-perigo-cheio hm-btn-p" id="hm-excluir-sim" data-hm-acao="b-excluir-sim" data-cid="' + b.cid + '">Sim, excluir</button>'
                    + '<button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="hm-excluir-nao" data-hm-acao="b-excluir-nao">Cancelar</button></div></div>'
                : '<button type="button" class="hm-excluir" id="hm-excluir-' + b.cid + '" data-hm-acao="b-excluir" data-cid="' + b.cid + '">Excluir banner</button>')
            + '</div></div>';
    };
    var cartaoBanners = function () {
        var B = S.banners;
        var linhas = B.map(function (b, i) {
            var aberto = ui.aberto === b.cid, nome = b.titulo.trim() || 'banner ' + (i + 1);
            return '<div class="hm-brow' + (b.ativo ? '' : ' is-off') + (aberto ? ' is-aberto' : '') + '" data-cid="' + b.cid + '">'
                + '<span class="hm-setas"><button type="button" id="hm-sobe-' + b.cid + '" data-hm-acao="b-mover" data-d="-1" data-cid="' + b.cid + '" aria-label="Subir ' + esc(nome) + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
                + '<button type="button" id="hm-desce-' + b.cid + '" data-hm-acao="b-mover" data-d="1" data-cid="' + b.cid + '" aria-label="Descer ' + esc(nome) + '"' + (i === B.length - 1 ? ' disabled' : '') + '>↓</button></span>'
                + '<span class="hm-thumb">' + (b.thumb ? '<img src="' + esc(b.thumb) + '" alt="">' : 'Sem imagem') + '</span>'
                + '<span class="hm-bt" data-hm-bt>' + linhaTexto(b) + '</span>'
                + '<span class="hm-bsw"><button type="button" class="pr-sw" role="switch" id="hm-liga-' + b.cid + '" aria-checked="' + (b.ativo ? 'true' : 'false') + '" data-hm-acao="b-ligar" data-cid="' + b.cid + '" aria-label="Mostrar ' + esc(nome) + ' na loja">'
                + '<span class="pr-sw-trilho" aria-hidden="true"></span><span>' + (b.ativo ? 'Ligado' : 'Desligado') + '</span></button></span>'
                + '<span class="hm-bed"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="hm-editar-' + b.cid + '" data-hm-acao="b-editar" data-cid="' + b.cid + '" aria-expanded="' + (aberto ? 'true' : 'false') + '">' + (aberto ? 'Fechar' : 'Editar') + '</button></span>'
                + (aberto ? formBanner(b) : '') + '</div>';
        }).join('');
        return '<section class="pe-card hm-card" aria-labelledby="hm-h"><h2 id="hm-h">Banners ' + marca('banners') + '</h2>'
            + '<p class="cf-onde">O carrossel do topo da home, na ordem abaixo.</p>'
            + '<div class="hm-blist">' + (linhas || '<p class="hm-vazio">Nenhum banner. A home mostra o nome e a descrição da loja no lugar do carrossel.</p>') + '</div>'
            + '<div class="hm-linha"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="hm-novo" data-hm-acao="b-novo">+ Novo banner</button>'
            + '<span class="pe-ajuda">Imagem horizontal na proporção 16:6 (ex.: 1600 × 600 px). As pontas podem ser cortadas em telas estreitas.</span></div></section>';
    };

    // --- Frases --------------------------------------------------------------------------------------
    var tarja = function () {
        var f = S.frases.map(function (x) { return x.trim(); }).filter(Boolean);
        if (!f.length) { return '<p class="hm-vazio">Sem frases, a faixa não aparece na loja.</p>'; }
        var um = f.map(function (x) { return '<span class="tarja-item">' + esc(x) + '</span><span class="tarja-sep" aria-hidden="true">·</span>'; }).join('');
        var rep = Math.max(1, Math.ceil(12 / f.length)), grupo = '';
        for (var i = 0; i < rep; i++) { grupo += um; }
        return '<div class="tarja hm-tarja" aria-hidden="true"><div class="tarja-track"><div class="tarja-grupo">' + grupo + '</div><div class="tarja-grupo">' + grupo + '</div></div></div>';
    };
    var cartaoFrases = function () {
        var F = S.frases;
        return '<section class="pe-card hm-card" aria-labelledby="hm-h"><h2 id="hm-h">Frases da faixa ' + marca('frases') + ' <small class="ap-num">' + F.length + ' de 5</small></h2>'
            + '<p class="cf-onde">A faixa que passa rolando na home. Frases curtas, até ' + L.frase + ' letras.</p>'
            + '<div class="hm-frases">' + F.map(function (f, i) {
                var k = 'fr:' + i;
                return '<div class="hm-frase"><span class="hm-setas"><button type="button" id="hm-fsobe-' + i + '" data-hm-acao="f-mover" data-d="-1" data-i="' + i + '" aria-label="Subir frase ' + (i + 1) + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
                    + '<button type="button" id="hm-fdesce-' + i + '" data-hm-acao="f-mover" data-d="1" data-i="' + i + '" aria-label="Descer frase ' + (i + 1) + '"' + (i === F.length - 1 ? ' disabled' : '') + '>↓</button></span>'
                    + '<input type="text" id="' + idDe(k) + '" data-hm-frase="' + i + '" value="' + esc(f) + '" maxlength="' + L.frase + '" aria-label="Frase ' + (i + 1) + '" placeholder="Nova frase"' + inv(k) + '>'
                    + '<button type="button" class="hm-x" data-hm-acao="f-remover" data-i="' + i + '" aria-label="Remover frase ' + (i + 1) + '">✕</button>'
                    + erro(k) + '</div>';
            }).join('') + '</div>' + erro('fr:geral')
            + '<div class="hm-linha"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="hm-fadd" data-hm-acao="f-add"' + (F.length >= 5 ? ' disabled' : '') + '>+ Adicionar frase</button>'
            + (F.length >= 5 ? '<span class="pe-ajuda">Limite de 5 frases.</span>' : '') + '</div>'
            + '<div class="hm-previa"><span class="pe-ajuda"><b>Prévia</b></span><div data-hm-tarja>' + tarja() + '</div></div></section>';
    };

    // --- Bloco editorial -----------------------------------------------------------------------------
    var cartaoEd = function () {
        var e = S.ed, video = e.tipo === 'video', m = video ? e.video : e.foto;
        var conteudo = !m.url ? '' : (video && !m.gif ? '<video src="' + esc(m.url) + '" muted autoplay loop playsinline></video>' : '<img src="' + esc(m.url) + '" alt="">');
        return '<section class="pe-card hm-card" aria-labelledby="hm-h"><h2 id="hm-h">Bloco editorial ' + marca('ed') + '</h2>'
            + '<p class="cf-onde">A faixa com foto ou vídeo e um texto sobre a marca, no meio da home.</p>'
            + '<div class="hm-seg" role="group" aria-label="Tipo de mídia">'
            + '<button type="button" id="hm-tipo-foto" data-hm-acao="ed-tipo" data-tipo="foto" aria-pressed="' + (!video) + '">Foto</button>'
            + '<button type="button" id="hm-tipo-video" data-hm-acao="ed-tipo" data-tipo="video" aria-pressed="' + video + '">Vídeo ou GIF</button></div>'
            + '<div class="hm-midia">' + previa('ed', conteudo, video ? 'Sem vídeo' : 'Sem foto')
            + '<div class="hm-midia-lado"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="' + idDe('ed:midia') + '" data-hm-acao="up" data-alvo="ed"' + inv('ed:midia') + (ui.enviando.ed ? ' disabled' : '') + '>'
            + (m.url ? 'Trocar ' : 'Escolher ') + (video ? 'vídeo' : 'foto') + '</button>'
            + '<span class="pe-ajuda">' + (video ? 'Vídeo curto (5 a 10 s) ou GIF, sem som. Toca sozinho em loop. Até 15 MB.' : 'Foto horizontal. JPG ou PNG.') + '</span>' + erro('ed:midia') + '</div></div>'
            + '<div class="pe-campo"><label for="' + idDe('ed:titulo') + '">Título</label><input type="text" id="' + idDe('ed:titulo') + '" data-hm-ed="titulo" value="' + esc(e.titulo) + '" maxlength="' + L.titulo + '"' + inv('ed:titulo') + '>' + erro('ed:titulo') + '</div>'
            + '<div class="pe-campo"><label for="' + idDe('ed:sub') + '">Texto</label><textarea id="' + idDe('ed:sub') + '" data-hm-ed="sub" rows="6" maxlength="' + L.sub + '"' + inv('ed:sub') + '>' + esc(e.sub) + '</textarea>'
            + '<span class="pe-contador ap-num" data-hm-contador>' + e.sub.length + ' / ' + L.sub + '</span>' + erro('ed:sub') + '</div>'
            + '<div class="pe-grid2"><div class="pe-campo"><label for="' + idDe('ed:btn') + '">Texto do botão</label><input type="text" id="' + idDe('ed:btn') + '" data-hm-ed="btn" value="' + esc(e.btn) + '" maxlength="' + L.btn + '"' + inv('ed:btn') + '>'
            + '<span class="pe-ajuda">O botão só aparece com texto e destino.</span>' + erro('ed:btn') + '</div>'
            + campoLink('ed:url', e.link, 'O botão leva para') + '</div></section>';
    };

    // --- Coleções ------------------------------------------------------------------------------------
    var cartaoCol = function () {
        return '<section class="pe-card hm-card" aria-labelledby="hm-h"><h2 id="hm-h">Imagem da seção Coleções ' + marca('col') + '</h2>'
            + '<p class="cf-onde">A foto vertical que fica parada ao lado da grade de coleções enquanto a pessoa rola a página.</p>'
            + '<div class="hm-midia">' + previa('col', S.col.url ? '<img src="' + esc(S.col.url) + '" alt="">' : '', 'Sem imagem', 'is-alta')
            + '<div class="hm-midia-lado"><button type="button" class="ap-btn ap-btn-linha hm-btn-p" id="' + idDe('col:img') + '" data-hm-acao="up" data-alvo="col"' + inv('col:img') + (ui.enviando.col ? ' disabled' : '') + '>'
            + (S.col.url ? 'Trocar imagem' : 'Escolher imagem') + '</button>'
            + '<span class="pe-ajuda">Foto em pé (vertical). Otimizamos sozinhos para até 1200 px.</span>' + erro('col:img') + '</div></div></section>';
    };

    var CARTOES = { banners: cartaoBanners, frases: cartaoFrases, ed: cartaoEd, col: cartaoCol };

    // --- Desenho --------------------------------------------------------------------------------------
    var barra = function () {
        var l = sujas();
        $('[data-hm-estado]').classList.toggle('is-sujo', l.length > 0);
        $('[data-hm-estado-txt]').textContent = ui.salvando ? 'Salvando…' : (l.length ? 'Não salvo: ' + l.map(function (k) { return PARTES[k]; }).join(', ') : 'Tudo salvo');
        $('[data-hm-descartar]').hidden = !l.length;
        $('[data-hm-salvar]').disabled = !l.length || ui.salvando;
    };
    // Bolinhas e contagens sem redesenhar os campos (enquanto a pessoa digita).
    var leve = function () {
        mapa();
        var m = $('[data-hm-marca]');
        if (m) { m.innerHTML = bolinha(ui.parte); }
        barra();
    };
    var desenhar = function (focoId) {
        var ativo = focoId || (document.activeElement && raiz.contains(document.activeElement) ? document.activeElement.id : '');
        mapa();
        $('[data-hm-edicao]').innerHTML = CARTOES[ui.parte]();
        barra();
        if (ativo) {
            var el = document.getElementById(ativo);
            if (el && el.disabled) {   // seta que chegou na ponta: vai para a outra
                el = el.parentNode.querySelector('button:not([disabled])');
            }
            if (el) { el.focus({ preventScroll: !focoId }); }
        }
    };
    var abrirParte = function (k) {
        ui.parte = k;
        ui.confirmar = false;
        try {
            var u = new URL(location.href);
            u.searchParams.set('parte', k);
            history.replaceState(null, '', u.pathname + u.search);
        } catch (e) {}
    };
    var limparErro = function (k) {
        if (!ui.erros[k]) { return; }
        delete ui.erros[k];
        var s = document.getElementById(idDe(k) + '-erro');
        if (s) { s.remove(); }
        raiz.querySelectorAll('[aria-describedby="' + idDe(k) + '-erro"]').forEach(function (el) {
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
    };

    // --- Upload na hora ---------------------------------------------------------------------------------
    var arquivo = $('[data-hm-arquivo]');
    var IMG = 'image/jpeg,image/png,image/webp', VID = 'video/mp4,video/webm,image/gif';
    var escolher = function (alvo) {
        ui.alvo = alvo;
        arquivo.accept = alvo === 'ed' && S.ed.tipo === 'video' ? VID : IMG;
        arquivo.value = '';
        arquivo.click();
    };
    arquivo.addEventListener('change', function () {
        var f = arquivo.files[0], alvo = ui.alvo;
        if (!f || !alvo) { return; }
        var tipo = alvo.indexOf('banner:') === 0 ? 'banner' : (alvo === 'col' ? 'col' : (S.ed.tipo === 'video' ? 'ed_video' : 'ed_foto'));
        var cid = tipo === 'banner' ? alvo.slice(7) : '';
        var chave = tipo === 'banner' ? 'b:' + cid + ':img' : (tipo === 'col' ? 'col:img' : 'ed:midia');
        var max = tipo === 'ed_video' ? 15 : 5;
        if (f.size > max * 1024 * 1024) {
            ui.erros[chave] = (tipo === 'ed_video' ? 'O vídeo' : 'A imagem') + ' deve ter no máximo ' + max + ' MB.';
            desenhar();
            return;
        }
        var fd = new FormData();
        fd.append('op', 'upload');
        fd.append('_csrf', D.csrf);
        fd.append('tipo', tipo);
        fd.append('arquivo', f);
        ui.enviando[alvo] = true;
        delete ui.erros[chave];
        desenhar();
        fetch(D.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                delete ui.enviando[alvo];
                if (!d.ok) {
                    ui.erros[chave] = d.mensagem || 'Não foi possível enviar o arquivo.';
                    desenhar();
                    return;
                }
                if (tipo === 'banner') {
                    var i = acharBanner(cid);
                    if (i !== -1) { S.banners[i].tmp = d.tmp; S.banners[i].img = d.url; S.banners[i].thumb = d.url; }
                } else if (tipo === 'col') {
                    S.col.tmp = d.tmp; S.col.url = d.url;
                } else if (tipo === 'ed_video') {
                    S.ed.video = { url: d.url, tmp: d.tmp, gif: !!d.gif };
                } else {
                    S.ed.foto = { url: d.url, tmp: d.tmp };
                }
                desenhar();
                avisar('Arquivo enviado. Clique em Salvar alterações para publicar.');
            })
            .catch(function () {
                delete ui.enviando[alvo];
                ui.erros[chave] = 'Falha no envio. Confira a conexão e tente de novo.';
                desenhar();
            });
    });

    // --- Cliques ------------------------------------------------------------------------------------
    raiz.addEventListener('click', function (ev) {
        var p = ev.target.closest('[data-hm-parte]');
        if (p) { abrirParte(p.getAttribute('data-hm-parte')); desenhar(); return; }
        var a = ev.target.closest('[data-hm-acao]');
        if (!a || a.disabled) { return; }
        var acao = a.getAttribute('data-hm-acao'), cid = a.getAttribute('data-cid'), i = acharBanner(cid), B = S.banners, b = B[i];

        if (acao === 'up') { escolher(a.getAttribute('data-alvo')); return; }
        if (acao === 'b-mover') {
            var j = i + (+a.getAttribute('data-d'));
            if (j < 0 || j >= B.length) { return; }
            B[i] = B[j]; B[j] = b;
            desenhar();
            return;
        }
        if (acao === 'b-ligar') {
            b.ativo = !b.ativo;
            if (!b.ativo) { limparErro('b:' + cid + ':img'); }
            desenhar();
            return;
        }
        if (acao === 'b-editar') { ui.aberto = ui.aberto === cid ? null : cid; ui.confirmar = false; desenhar(); return; }
        if (acao === 'b-novo') {
            var novo = { cid: 'n' + (++seq), id: 0, titulo: '', link: { tipo: 'nenhum', cat: 0, url: '' }, ativo: false, inicio: '', fim: '', img: '', thumb: '', tmp: '' };
            B.push(novo);
            ui.aberto = novo.cid;
            ui.confirmar = false;
            desenhar(idDe('b:' + novo.cid + ':img'));
            avisar('Banner novo criado desligado. Escolha a imagem e ligue quando quiser.');
            return;
        }
        if (acao === 'b-excluir') { ui.confirmar = true; desenhar('hm-excluir-nao'); return; }
        if (acao === 'b-excluir-nao') { ui.confirmar = false; desenhar('hm-excluir-' + ui.aberto); return; }
        if (acao === 'b-excluir-sim') {
            B.splice(i, 1);
            if (b.id) { ui.excluidos.push(b.id); }
            Object.keys(ui.erros).forEach(function (k) { if (k.indexOf('b:' + cid + ':') === 0) { delete ui.erros[k]; } });
            ui.aberto = null;
            ui.confirmar = false;
            desenhar('hm-novo');
            avisar('Banner removido. Ele só sai da loja quando você salvar.', function () {
                B.splice(Math.min(i, B.length), 0, b);
                if (b.id) { ui.excluidos = ui.excluidos.filter(function (x) { return x !== b.id; }); }
                if (ui.parte !== 'banners') { abrirParte('banners'); }
                desenhar('hm-editar-' + b.cid);
            });
            return;
        }
        var F = S.frases, fi = +a.getAttribute('data-i');
        if (acao === 'f-mover') {
            var fj = fi + (+a.getAttribute('data-d'));
            if (fj < 0 || fj >= F.length) { return; }
            var t = F[fi]; F[fi] = F[fj]; F[fj] = t;
            ui.erros = {};
            desenhar();
            return;
        }
        if (acao === 'f-remover') {
            var rem = F.splice(fi, 1)[0];
            Object.keys(ui.erros).forEach(function (k) { if (k.indexOf('fr:') === 0) { delete ui.erros[k]; } });
            desenhar(F.length ? idDe('fr:' + Math.min(fi, F.length - 1)) : 'hm-fadd');
            avisar('“' + (rem.trim() || 'Frase vazia') + '” removida.', function () {
                F.splice(Math.min(fi, F.length), 0, rem);
                if (ui.parte !== 'frases') { abrirParte('frases'); }
                desenhar(idDe('fr:' + fi));
            });
            return;
        }
        if (acao === 'f-add') {
            if (F.length < 5) { F.push(''); desenhar(idDe('fr:' + (F.length - 1))); }
            return;
        }
        if (acao === 'ed-tipo') {
            S.ed.tipo = a.getAttribute('data-tipo');
            limparErro('ed:midia');
            desenhar();
        }
    });

    // --- Digitação -----------------------------------------------------------------------------------
    raiz.addEventListener('input', function (ev) {
        var el = ev.target, ds = el.dataset;
        if (ds.hmB) {
            var b = S.banners[acharBanner(ds.cid)];
            b[ds.hmB] = el.value;
            limparErro('b:' + ds.cid + ':' + ds.hmB);
            if (ds.hmB === 'fim' || ds.hmB === 'inicio') { limparErro('b:' + ds.cid + ':fim'); }
            var bt = el.closest('.hm-brow').querySelector('[data-hm-bt]');
            if (bt) { bt.innerHTML = linhaTexto(b); }
        } else if (ds.hmUrl) {
            (ds.hmUrl === 'ed:url' ? S.ed.link : S.banners[acharBanner(ds.hmUrl.split(':')[1])].link).url = el.value;
            limparErro(ds.hmUrl);
        } else if (ds.hmFrase != null) {
            S.frases[+ds.hmFrase] = el.value;
            limparErro('fr:' + ds.hmFrase);
            $('[data-hm-tarja]').innerHTML = tarja();
        } else if (ds.hmEd) {
            S.ed[ds.hmEd] = el.value;
            limparErro('ed:' + ds.hmEd);
            if (ds.hmEd === 'sub') { $('[data-hm-contador]').textContent = el.value.length + ' / ' + L.sub; }
        } else {
            return;
        }
        leve();
    });
    raiz.addEventListener('change', function (ev) {
        var el = ev.target, k = el.getAttribute('data-hm-link');
        if (!k) { return; }
        var link = k === 'ed:url' ? S.ed.link : S.banners[acharBanner(k.split(':')[1])].link, v = el.value;
        link.tipo = v === '' ? 'nenhum' : (v === 'url' ? 'url' : 'cat');
        link.cat = link.tipo === 'cat' ? +v.slice(4) : 0;
        if (link.tipo !== 'url') { link.url = ''; }
        limparErro(k);
        desenhar(link.tipo === 'url' ? idDe(k) + '-url' : idDe(k));
    });

    // --- Salvar / descartar ----------------------------------------------------------------------------
    var campoDoErro = function (k) {
        if (/^b:.+:url$/.test(k) || k === 'ed:url') { return document.getElementById(idDe(k) + '-url') || document.getElementById(idDe(k)); }
        return document.getElementById(idDe(k));
    };
    var parteDoErro = function (k) {
        return k.indexOf('b:') === 0 ? 'banners' : (k.indexOf('fr:') === 0 ? 'frases' : (k.indexOf('ed:') === 0 ? 'ed' : 'col'));
    };
    var mostrarErros = function (erros) {
        ui.erros = erros;
        var k = Object.keys(erros)[0];
        if (!k) { return; }
        abrirParte(parteDoErro(k));
        if (k.indexOf('b:') === 0) { ui.aberto = k.split(':')[1]; ui.confirmar = false; }
        desenhar();
        var el = campoDoErro(k);
        if (el) { el.focus(); el.scrollIntoView({ block: 'center' }); }
    };
    var payload = function () {
        return JSON.stringify({
            banners: S.banners.map(function (b) {
                return { cid: b.cid, id: b.id, titulo: b.titulo, link: b.link, ativo: b.ativo, inicio: b.inicio, fim: b.fim, tmp: b.tmp };
            }),
            excluidos: ui.excluidos,
            frases: S.frases,
            ed: { tipo: S.ed.tipo, titulo: S.ed.titulo, sub: S.ed.sub, btn: S.ed.btn, link: S.ed.link, foto: { tmp: S.ed.foto.tmp }, video: { tmp: S.ed.video.tmp } },
            col: { tmp: S.col.tmp }
        });
    };
    $('[data-hm-salvar]').addEventListener('click', function () {
        if (Object.keys(ui.enviando).length) { avisar('Espere o envio do arquivo terminar.', null, true); return; }
        var fd = new FormData();
        fd.append('op', 'salvar');
        fd.append('_csrf', D.csrf);
        fd.append('payload', payload());
        ui.salvando = true;
        barra();
        fetch(D.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                ui.salvando = false;
                if (!d.ok) {
                    if (d.erros) { mostrarErros(d.erros); } else { barra(); }
                    avisar(d.mensagem || 'Não foi possível salvar. Tente de novo.', null, true);
                    return;
                }
                // O banner aberto continua aberto (um novo ganha id: acha pela posição, que é a da tela).
                var pos = ui.aberto !== null ? acharBanner(ui.aberto) : -1;
                S = d.estado;
                base = clone(S);
                ui.excluidos = [];
                ui.erros = {};
                ui.confirmar = false;
                ui.aberto = pos >= 0 && S.banners[pos] ? S.banners[pos].cid : null;
                desenhar();
                avisar(d.mensagem);
            })
            .catch(function () { ui.salvando = false; barra(); avisar('Não foi possível salvar. Confira a conexão e tente de novo.', null, true); });
    });
    $('[data-hm-descartar]').addEventListener('click', function () {
        var antes = { s: S, x: ui.excluidos };
        S = clone(base);
        ui.excluidos = [];
        ui.erros = {};
        ui.aberto = null;
        ui.confirmar = false;
        desenhar();
        avisar('Alterações descartadas.', function () { S = antes.s; ui.excluidos = antes.x; desenhar(); });
    });
    window.addEventListener('beforeunload', function (ev) {
        if (sujas().length) { ev.preventDefault(); ev.returnValue = ''; }
    });

    desenhar();
})();
