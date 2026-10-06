/* =============================================================================
   Comportamentos da interface (genéricos, sem dependências externas):
   - Abrir/fechar o menu no celular.
   - Abrir/fechar o modal de regras.
   - Habilitar o botão de finalizar só com o aceite marcado.
   ============================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // --- Header condensa ao rolar (a linha 1 sai; a barra de categorias gruda) ---
        var cabecalho = document.querySelector('.cabecalho');
        if (cabecalho) {
            var linha1 = cabecalho.querySelector('.header-linha1');

            // Mede a altura da linha 1 -> vira o `top` negativo do header (CSS).
            var medirLinha1 = function () {
                if (linha1) {
                    cabecalho.style.setProperty('--h1', linha1.offsetHeight + 'px');
                }
            };
            medirLinha1();
            window.addEventListener('resize', medirLinha1);
            window.addEventListener('load', medirLinha1); // após carregar as fontes

            // Sombra sutil quando sai do topo.
            var LIMITE_SCROLL = 8;
            var aplicarScrollHeader = function () {
                var y = window.pageYOffset || document.documentElement.scrollTop || 0;
                cabecalho.classList.toggle('scrolled', y > LIMITE_SCROLL);
            };
            window.addEventListener('scroll', aplicarScrollHeader, { passive: true });
            aplicarScrollHeader(); // estado inicial (caso a página abra já rolada)
        }

        // --- Menu mobile (overlay em tela cheia) ----------------------------
        var menuToggle = document.querySelector('[data-menu-toggle]');
        var menuOverlay = document.getElementById('menu-mobile');
        if (menuToggle && menuOverlay) {
            var menuFechar = menuOverlay.querySelector('[data-menu-fechar]');

            var abrirMenu = function () {
                menuOverlay.classList.add('aberto');
                document.body.classList.add('menu-aberto');
                menuToggle.setAttribute('aria-expanded', 'true');
                if (menuFechar) { menuFechar.focus(); }
            };
            var fecharMenu = function () {
                menuOverlay.classList.remove('aberto');
                document.body.classList.remove('menu-aberto');
                menuToggle.setAttribute('aria-expanded', 'false');
            };

            menuToggle.addEventListener('click', function () {
                abrirMenu();
            });
            if (menuFechar) {
                menuFechar.addEventListener('click', function () {
                    fecharMenu();
                    menuToggle.focus();
                });
            }
            // Fecha ao clicar em qualquer link do menu.
            menuOverlay.querySelectorAll('[data-menu-link]').forEach(function (a) {
                a.addEventListener('click', fecharMenu);
            });
            // Fecha com ESC.
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && menuOverlay.classList.contains('aberto')) {
                    fecharMenu();
                    menuToggle.focus();
                }
            });
        }

        // --- Modal (genérico, controlado por data-attributes) ----------------
        // Abrir: qualquer elemento com data-abrir-modal="ID_DO_MODAL".
        // Fechar: botão/elemento com data-fechar-modal dentro do modal,
        //         clique no fundo escuro, ou tecla ESC.
        function abrirModal(id) {
            var m = document.getElementById(id);
            if (m) { m.classList.add('aberto'); }
        }
        function fecharModal(m) {
            if (m) { m.classList.remove('aberto'); }
        }

        document.querySelectorAll('[data-abrir-modal]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                abrirModal(el.getAttribute('data-abrir-modal'));
            });
        });

        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('click', function (ev) {
                // Clique no fundo (fora do conteúdo) fecha.
                if (ev.target === modal) { fecharModal(modal); }
            });
            modal.querySelectorAll('[data-fechar-modal]').forEach(function (botao) {
                botao.addEventListener('click', function () { fecharModal(modal); });
            });
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                document.querySelectorAll('.modal.aberto').forEach(fecharModal);
            }
        });

        // --- Abas (ex.: Entrar / Criar conta) -------------------------------
        // Botões com data-aba="X" mostram o painel com data-painel="X".
        var botoesAba = document.querySelectorAll('[data-aba]');
        if (botoesAba.length) {
            botoesAba.forEach(function (botao) {
                botao.addEventListener('click', function () {
                    var alvo = botao.getAttribute('data-aba');
                    document.querySelectorAll('[data-aba]').forEach(function (b) {
                        b.classList.toggle('ativa', b.getAttribute('data-aba') === alvo);
                    });
                    document.querySelectorAll('[data-painel]').forEach(function (p) {
                        p.classList.toggle('ativo', p.getAttribute('data-painel') === alvo);
                    });
                });
            });
        }

        // --- Prévia das fotos escolhidas (acumula; envia só ao salvar) ------
        var arqInput = document.querySelector('[data-arquivo-input]');
        var arqPreview = document.querySelector('[data-fotos-preview]');
        if (arqInput && arqPreview) {
            var arqInfo = document.querySelector('[data-arquivo-info]');
            var arqWrap = document.querySelector('[data-preview-wrap]');
            var arqTextoPadrao = arqInfo ? arqInfo.textContent : '';
            var arquivos = [];                       // lista acumulada de File
            var TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
            var MAX = 5 * 1024 * 1024;               // ~5 MB

            var sincronizarInput = function () {
                // Reconstrói input.files com a lista acumulada (DataTransfer).
                var dt = new DataTransfer();
                arquivos.forEach(function (f) { dt.items.add(f); });
                arqInput.files = dt.files;
            };
            var atualizarInfo = function () {
                if (!arqInfo) { return; }
                var n = arquivos.length;
                arqInfo.textContent = n > 0
                    ? (n + (n === 1 ? ' foto selecionada' : ' fotos selecionadas'))
                    : arqTextoPadrao;
            };
            var render = function () {
                arqPreview.innerHTML = '';
                arquivos.forEach(function (f, idx) {
                    var card = document.createElement('div');
                    card.className = 'foto-card';
                    var img = document.createElement('img');
                    img.className = 'card-img';
                    img.alt = '';
                    img.src = URL.createObjectURL(f);
                    img.addEventListener('load', function () { URL.revokeObjectURL(img.src); });

                    var badge = document.createElement('span');
                    badge.className = 'foto-nova etiqueta';
                    badge.textContent = 'nova';

                    var x = document.createElement('button');
                    x.type = 'button';
                    x.className = 'foto-remover';
                    x.setAttribute('aria-label', 'Remover');
                    x.innerHTML = '&times;';
                    x.addEventListener('click', function () {
                        arquivos.splice(idx, 1);
                        sincronizarInput();
                        atualizarInfo();
                        render();
                    });

                    card.appendChild(badge);
                    card.appendChild(img);
                    card.appendChild(x);
                    arqPreview.appendChild(card);
                });
                if (arqWrap) { arqWrap.hidden = arquivos.length === 0; }
            };

            arqInput.addEventListener('change', function () {
                var novos = Array.prototype.slice.call(arqInput.files || []);
                var rejeitados = 0;
                novos.forEach(function (f) {
                    if (TIPOS.indexOf(f.type) === -1 || f.size > MAX) { rejeitados++; return; }
                    arquivos.push(f);
                });
                sincronizarInput();   // input.files passa a ter a lista acumulada
                atualizarInfo();
                render();
                if (rejeitados > 0) {
                    if (typeof notificar === 'function') {
                        notificar('erro', 'Algumas fotos foram ignoradas. Use JPG, PNG ou WebP de até 5 MB.');
                    } else {
                        alert('Algumas fotos foram ignoradas. Use JPG, PNG ou WebP de até 5 MB.');
                    }
                }
            });
        }

        // --- ViaCEP: autopreenche endereço a partir do CEP -----------------
        // Reaproveitável: em qualquer formulário, um [data-cep] preenche os
        // campos [data-cep-rua], [data-cep-bairro], [data-cep-cidade], [data-cep-uf].
        document.querySelectorAll('[data-cep]').forEach(function (cep) {
            cep.addEventListener('blur', function () {
                var d8 = (cep.value || '').replace(/\D+/g, '');
                if (d8.length !== 8) { return; }
                var escopo = cep.closest('form') || document;
                fetch('https://viacep.com.br/ws/' + d8 + '/json/')
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || d.erro) { return; }
                        var setF = function (sel, val) {
                            var el = escopo.querySelector(sel);
                            if (el && val) { el.value = val; }
                        };
                        setF('[data-cep-rua]', d.logradouro);
                        setF('[data-cep-bairro]', d.bairro);
                        setF('[data-cep-cidade]', d.localidade);
                        setF('[data-cep-uf]', d.uf);
                    })
                    .catch(function () { /* silencioso: preenche manualmente */ });
            });
        });

        // --- Mostrar/ocultar senha -----------------------------------------
        // Todo input[type=password] ganha um botão de olho dentro do campo.
        var ICONE_OLHO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var ICONE_OLHO_FECHADO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
            + '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
            + '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        document.querySelectorAll('input[type="password"]').forEach(function (campo) {
            var caixa = document.createElement('span');
            caixa.className = 'senha-caixa';
            campo.parentNode.insertBefore(caixa, campo);
            caixa.appendChild(campo);

            var botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'senha-toggle';
            caixa.appendChild(botao);

            var aplicar = function (visivel) {
                campo.type = visivel ? 'text' : 'password';
                botao.innerHTML = visivel ? ICONE_OLHO_FECHADO : ICONE_OLHO;
                botao.setAttribute('aria-label', visivel ? 'Ocultar senha' : 'Mostrar senha');
                botao.setAttribute('aria-pressed', visivel ? 'true' : 'false');
            };
            botao.addEventListener('click', function () {
                aplicar(campo.type === 'password');
                campo.focus();
            });
            // Ao enviar, volta a ocultar (o navegador não guarda a senha como texto).
            if (campo.form) {
                campo.form.addEventListener('submit', function () { aplicar(false); });
            }
            aplicar(false);
        });

        // --- Estimativa de frete (página do produto) ------------------------
        // Logado com endereço -> calcula para ele ao abrir. Sem login, usa o CEP
        // digitado (lembrado no navegador para os próximos produtos).
        var freteCalc = document.querySelector('[data-frete-calc]');
        if (freteCalc) {
            var freteCep = freteCalc.querySelector('[data-frete-cep]');
            var freteForm = freteCalc.querySelector('[data-frete-form]');
            var freteRes = freteCalc.querySelector('[data-frete-resultado]');
            var freteBtn = freteCalc.querySelector('[data-frete-calcular]');
            var freteEnd = freteCalc.querySelector('[data-frete-endereco]');
            var freteResumo = freteCalc.querySelector('[data-frete-resumo]');

            var ICONE_MOTO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
                + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + '<circle cx="5.5" cy="17" r="3"/><circle cx="18.5" cy="17" r="3"/>'
                + '<path d="M8.5 17h6l2-6h-5l-2 3"/><path d="M15 6h2.5l1.5 5"/></svg>';
            var ICONE_LOJA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
                + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v1.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/>'
                + '<path d="M5 13v7h14v-7"/><path d="M10 20v-4h4v4"/></svg>';

            // Um cartão de opção (Motoboy / Retirada). Textos via textContent.
            var freteOpcao = function (classe, icone, rotulo, valor, detalhe) {
                var card = document.createElement('div');
                card.className = 'frete-opcao ' + classe;
                var r = document.createElement('span');
                r.className = 'frete-opcao-rotulo';
                r.innerHTML = icone;
                r.appendChild(document.createTextNode(rotulo));
                var v = document.createElement('strong');
                v.className = 'frete-opcao-valor';
                v.textContent = valor;
                var dt = document.createElement('span');
                dt.className = 'frete-opcao-detalhe';
                dt.textContent = detalhe;
                card.appendChild(r);
                card.appendChild(v);
                card.appendChild(dt);
                return card;
            };

            var freteTexto = function (classe, texto) {
                var p = document.createElement('p');
                p.className = classe;
                p.textContent = texto;
                return p;
            };

            // d: resposta do /frete. d.soMensagem = erro de digitação (só o aviso).
            var freteMostrar = function (d) {
                freteRes.innerHTML = '';
                var definitivo = d.ok || d.motivo === 'fora_raio';

                if (definitivo) {
                    freteResumo.textContent = d.resumo || d.destino || '';
                    freteEnd.hidden = false;
                    freteForm.hidden = true;
                } else {
                    freteRes.appendChild(freteTexto('frete-calc-msg',
                        d.mensagem || 'Não foi possível calcular o frete agora.'));
                    freteEnd.hidden = true;
                    freteForm.hidden = false;
                }

                if (!d.soMensagem) {
                    var grade = document.createElement('div');
                    grade.className = 'frete-opcoes' + (definitivo ? '' : ' is-unica');
                    if (d.ok) {
                        var km = (d.distancia_km !== null && d.distancia_km !== undefined)
                            ? Number(d.distancia_km).toFixed(1).replace('.', ',') + ' km da loja' : '';
                        grade.appendChild(freteOpcao('frete-opcao--motoboy', ICONE_MOTO, 'Motoboy', d.frete, km));
                    } else if (d.motivo === 'fora_raio') {
                        grade.appendChild(freteOpcao('frete-opcao--indisponivel', ICONE_MOTO, 'Motoboy',
                            'Indisponível', 'Fora do raio de entrega'));
                    }
                    grade.appendChild(freteOpcao('frete-opcao--retirada' + (d.ok ? '' : ' is-destaque'),
                        ICONE_LOJA, 'Retirada', 'Grátis', 'Na loja'));
                    freteRes.appendChild(grade);
                }

                if (d.ok) {
                    freteRes.appendChild(freteTexto('frete-calc-nota', 'O valor final é confirmado no checkout.'));
                }
                freteRes.hidden = false;
            };

            var freteEnviar = function (dados) {
                var fd = new FormData();
                fd.append('_csrf', freteCalc.getAttribute('data-csrf'));
                Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
                if (freteBtn) { freteBtn.disabled = true; }
                freteRes.hidden = false;
                freteRes.innerHTML = '';
                freteRes.appendChild(freteTexto('frete-calc-msg', 'Calculando…'));
                return fetch(freteCalc.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(freteMostrar)
                    .catch(function () { freteMostrar({ ok: false }); })
                    .then(function () { if (freteBtn) { freteBtn.disabled = false; } });
            };

            var freteCalcularCep = function () {
                var d8 = (freteCep.value || '').replace(/\D+/g, '');
                if (d8.length !== 8) {
                    freteMostrar({ ok: false, soMensagem: true, mensagem: 'Informe um CEP válido (8 números).' });
                    return;
                }
                try { localStorage.setItem('frete_cep', d8); } catch (e) { /* sem storage */ }
                // ViaCEP completa o destino (mais preciso); se falhar, vai só com o CEP.
                fetch('https://viacep.com.br/ws/' + d8 + '/json/')
                    .then(function (r) { return r.json(); })
                    .catch(function () { return {}; })
                    .then(function (v) {
                        if (v && v.erro) {
                            freteMostrar({ ok: false, soMensagem: true, mensagem: 'CEP não encontrado. Confira os números.' });
                            return;
                        }
                        v = v || {};
                        freteEnviar({ cep: d8, rua: v.logradouro || '', bairro: v.bairro || '',
                                      cidade: v.localidade || '', uf: v.uf || '' });
                    });
            };

            if (freteCep) {
                freteCep.addEventListener('input', function () {
                    var v = freteCep.value.replace(/\D+/g, '').slice(0, 8);
                    freteCep.value = v.length > 5 ? v.slice(0, 5) + '-' + v.slice(5) : v;
                });
                freteCep.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); freteCalcularCep(); }
                });
            }
            if (freteBtn) { freteBtn.addEventListener('click', freteCalcularCep); }

            var freteOutro = freteCalc.querySelector('[data-frete-outro]');
            if (freteOutro) {
                freteOutro.addEventListener('click', function () {
                    freteEnd.hidden = true;
                    freteForm.hidden = false;
                    freteRes.hidden = true;
                    freteCep.focus();
                });
            }

            // Estado inicial.
            if (freteCalc.hasAttribute('data-frete-cadastro')) {
                freteEnviar({ cadastro: '1' });
            } else {
                var cepSalvo = null;
                try { cepSalvo = localStorage.getItem('frete_cep'); } catch (e) { /* sem storage */ }
                if (cepSalvo && /^\d{8}$/.test(cepSalvo)) {
                    freteCep.value = cepSalvo.slice(0, 5) + '-' + cepSalvo.slice(5);
                    freteCalcularCep();
                }
            }
        }

        // --- Checkout (Finalizar pedido) -----------------------------------
        // Visual + prévia do frete. O POST do checkout recalcula o frete no
        // servidor: o valor da tela é só uma prévia.
        var ck = document.querySelector('[data-checkout]');
        if (ck) {
            var ckSubtotal = parseInt(ck.getAttribute('data-subtotal'), 10) || 0;
            var ckFreteAtivo = ck.getAttribute('data-frete-ativo') === '1';
            var ckQ = function (sel) { return ck.querySelector(sel); };
            var ckRadioMoto = ckQ('[data-entrega][value="motoboy"]');
            var ckRadioRet = ckQ('[data-entrega][value="retirada"]');
            var ckSub = ckQ('[data-entrega-endereco]');
            var ckManual = ckQ('[data-endereco-manual]');
            var ckAviso = ckQ('[data-ck-aviso]');
            var ckEst = ckQ('[data-ck-est]');
            var ckMotoValor = ckQ('[data-ck-moto-valor]');
            var ckMotoDet = ckQ('[data-ck-moto-detalhe]');
            var ckFreteRot = ckQ('[data-ck-frete-rotulo]');
            var ckFreteVal = ckQ('[data-ck-frete-valor]');
            var ckNota = ckQ('[data-ck-nota]');
            var ckTotal = ckQ('[data-ck-total]');
            var ckCtaTotal = ckQ('[data-ck-cta-total]');

            // status: 'off' | 'pendente' | 'calculando' | 'ok' | 'fora' | 'erro'
            var ckEstado = { status: ckFreteAtivo ? 'pendente' : 'off', centavos: null, km: null, autoRetirada: false };
            var ckSeq = 0;
            var ckTimer = null;

            var ckBRL = function (c) {
                return 'R$ ' + (c / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            };
            var ckModo = function () {
                var r = ckQ('[data-entrega]:checked');
                return r ? r.value : 'retirada';
            };
            var ckUsaCadastro = function () {
                var r = ckQ('[data-endereco-opcao]:checked');
                return !!r && r.value === 'cadastro';
            };
            var ckCampo = function (nome) {
                var el = ckQ('[name="' + nome + '"]');
                return el ? el.value.trim() : '';
            };

            var ckAtualizar = function () {
                var st = ckEstado.status;
                var moto = ckModo() === 'motoboy';

                // Cartões selecionáveis: classe de marcado/desabilitado.
                ck.querySelectorAll('.ck-modo, .ck-end').forEach(function (lb) {
                    var inp = lb.querySelector('input');
                    lb.classList.toggle('is-marcado', !!inp && inp.checked);
                    lb.classList.toggle('is-desabilitado', !!inp && inp.disabled);
                });

                if (ckEst) { ckEst.hidden = !moto; }
                // "Entregar em" aparece com Motoboy — e também fora do raio, para trocar o endereço.
                ckSub.hidden = !(moto || st === 'fora');
                if (ckManual) { ckManual.hidden = !!ckQ('[data-endereco-opcao]') && ckUsaCadastro(); }
                ckAviso.hidden = st !== 'fora';

                // Cartão Motoboy.
                var mv = { off: 'Indisponível', pendente: '—', calculando: 'Calculando…', erro: 'A confirmar', fora: 'Indisponível' };
                var md = { off: 'Entrega por motoboy indisponível no momento', pendente: 'Informe o endereço',
                           calculando: '', erro: 'Não foi possível calcular agora', fora: 'Fora do raio de entrega' };
                if (st === 'ok') {
                    ckMotoValor.textContent = ckBRL(ckEstado.centavos);
                    ckMotoDet.textContent = ckEstado.km !== null
                        ? Number(ckEstado.km).toFixed(1).replace('.', ',') + ' km da loja' : '';
                } else {
                    ckMotoValor.textContent = mv[st];
                    ckMotoDet.textContent = md[st];
                }
                ckMotoValor.classList.toggle('is-alerta', st === 'fora' || st === 'off');
                ckMotoDet.classList.toggle('is-alerta', st === 'fora');

                // Resumo: linha do frete + total + botão.
                var frete = 0;
                ckFreteVal.innerHTML = '';
                var val = document.createElement('span');
                val.className = 'ck-preco';
                if (!moto) {
                    ckFreteRot.textContent = 'Retirada na loja';
                    val.classList.add('ck-gratis');
                    val.textContent = 'Grátis';
                } else {
                    ckFreteRot.textContent = 'Frete (motoboy)';
                    if (st === 'ok') {
                        var tag = document.createElement('span');
                        tag.className = 'ck-est-tag';
                        tag.textContent = 'estimado ';
                        ckFreteVal.appendChild(tag);
                        val.textContent = ckBRL(ckEstado.centavos);
                        frete = ckEstado.centavos;
                    } else {
                        val.textContent = st === 'calculando' ? 'Calculando…' : 'A confirmar';
                    }
                }
                ckFreteVal.appendChild(val);
                ckNota.hidden = !(moto && (st === 'erro' || st === 'pendente'));
                ckTotal.textContent = ckBRL(ckSubtotal + frete);
                ckCtaTotal.textContent = ckBRL(ckSubtotal + frete);
            };

            var ckResposta = function (d) {
                if (d && d.ok) {
                    var c = (d.frete_centavos !== undefined && d.frete_centavos !== null)
                        ? d.frete_centavos : parseInt(String(d.frete || '').replace(/\D+/g, ''), 10);
                    ckEstado.status = isNaN(c) ? 'erro' : 'ok';
                    ckEstado.centavos = c;
                    ckEstado.km = d.distancia_km;
                    ckRadioMoto.disabled = false;
                    // Voltou a ter entrega: desfaz a troca automática para Retirada.
                    if (ckEstado.autoRetirada) { ckRadioMoto.checked = true; ckEstado.autoRetirada = false; }
                } else if (d && d.motivo === 'fora_raio') {
                    ckEstado.status = 'fora';
                    if (ckRadioMoto.checked) { ckRadioRet.checked = true; ckEstado.autoRetirada = true; }
                    ckRadioMoto.disabled = true;
                } else {
                    ckEstado.status = 'erro';
                    ckRadioMoto.disabled = false;
                }
                ckAtualizar();
            };

            var ckCalcular = function () {
                if (!ckFreteAtivo) { return; }
                var dados;
                if (ckUsaCadastro()) {
                    dados = { cadastro: '1' };
                } else {
                    var cep = ckCampo('cep').replace(/\D+/g, '');
                    var num = ckCampo('numero');
                    if (cep.length !== 8 || num === '') {
                        ckEstado.status = 'pendente';
                        ckRadioMoto.disabled = false;
                        ckAtualizar();
                        return;
                    }
                    dados = { cep: cep, numero: num, rua: ckCampo('rua'), bairro: ckCampo('bairro'),
                              cidade: ckCampo('cidade'), uf: ckCampo('uf') };
                }
                var seq = ++ckSeq;
                ckEstado.status = 'calculando';
                ckAtualizar();
                var fd = new FormData();
                fd.append('_csrf', ck.getAttribute('data-csrf'));
                Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
                fetch(ck.getAttribute('data-frete-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .catch(function () { return { ok: false }; })
                    .then(function (d) { if (seq === ckSeq) { ckResposta(d); } });
            };

            // Eventos.
            ck.querySelectorAll('[data-entrega]').forEach(function (r) {
                r.addEventListener('change', function () { ckEstado.autoRetirada = false; ckAtualizar(); });
            });
            ck.querySelectorAll('[data-endereco-opcao]').forEach(function (r) {
                r.addEventListener('change', function () { ckAtualizar(); ckCalcular(); });
            });
            // Outro endereço: recalcula quando CEP + número estão completos (com pausa,
            // para o ViaCEP terminar de preencher rua/bairro/cidade).
            ck.querySelectorAll('[data-ck-end-campo]').forEach(function (el) {
                var agendar = function () {
                    clearTimeout(ckTimer);
                    ckTimer = setTimeout(ckCalcular, 900);
                };
                el.addEventListener('input', agendar);
                el.addEventListener('blur', agendar);
            });

            // Presente: campos + contador da mensagem.
            var ckPresente = ckQ('[data-presente]');
            var ckPresenteCampos = ckQ('[data-presente-campos]');
            var ckMsg = ckQ('[data-ck-mensagem]');
            var ckContador = ckQ('[data-ck-contador]');
            if (ckPresente && ckPresenteCampos) {
                var ckAplicarPresente = function () {
                    ckPresenteCampos.hidden = !ckPresente.checked;
                    ckPresente.setAttribute('aria-checked', ckPresente.checked ? 'true' : 'false');
                };
                ckPresente.addEventListener('change', ckAplicarPresente);
                ckAplicarPresente();
            }
            if (ckMsg && ckContador) {
                var ckContar = function () { ckContador.textContent = ckMsg.value.length + ' de 300 caracteres'; };
                ckMsg.addEventListener('input', ckContar);
                ckContar();
            }

            // Seus dados: "Alterar" mostra os campos (que sempre vão no POST).
            var ckAlterar = ckQ('[data-ck-contato-alterar]');
            if (ckAlterar) {
                ckAlterar.addEventListener('click', function () {
                    ckQ('[data-ck-contato-resumo]').hidden = true;
                    ckQ('[data-ck-contato-campos]').hidden = false;
                    ckAlterar.setAttribute('aria-expanded', 'true');
                    ckQ('[name="contato_nome"]').focus();
                });
            }

            // "Confirmar pedido" só com o aceite marcado.
            var ckAceite = ckQ('[data-checkout-aceite]');
            var ckConfirmar = ckQ('[data-checkout-confirmar]');
            if (ckAceite && ckConfirmar) {
                var ckSincronizar = function () { ckConfirmar.disabled = !ckAceite.checked; };
                ckAceite.addEventListener('change', ckSincronizar);
                ckSincronizar();
            }

            // Resumo sticky: fica abaixo do cabeçalho fixo (no computador só a linha 2 gruda).
            var ckResumo = ckQ('[data-ck-resumo]');
            var ckCab = document.querySelector('.cabecalho');
            if (ckResumo && ckCab) {
                var ckTopo = function () {
                    var l1 = ckCab.querySelector('.header-linha1');
                    var visivel = ckCab.offsetHeight - (window.innerWidth >= 768 && l1 ? l1.offsetHeight : 0);
                    ckResumo.style.top = (Math.max(visivel, 0) + 16) + 'px';
                };
                ckTopo();
                window.addEventListener('resize', ckTopo);
                window.addEventListener('load', ckTopo);
            }

            ckAtualizar();
            ckCalcular();
        }

        // --- Máscara de telefone BR: (11) 91234-5678 / (11) 1234-5678 -------
        function mascaraTelefone(valor) {
            var v = (valor || '').replace(/\D/g, '').slice(0, 11);
            if (v.length <= 2) { return v.length ? '(' + v : v; }
            var ddd = v.slice(0, 2);
            var resto = v.slice(2);
            if (resto.length <= 4) { return '(' + ddd + ') ' + resto; }
            if (resto.length <= 8) { return '(' + ddd + ') ' + resto.slice(0, 4) + '-' + resto.slice(4); }
            return '(' + ddd + ') ' + resto.slice(0, 5) + '-' + resto.slice(5);
        }
        document.querySelectorAll('[data-mask-tel]').forEach(function (el) {
            var aplicar = function () { el.value = mascaraTelefone(el.value); };
            el.addEventListener('input', aplicar);
            aplicar(); // formata um valor já preenchido
        });

        // --- Slug automático (nome -> slug) ---------------------------------
        var slugSource = document.querySelector('[data-slug-source]');
        var slugTarget = document.querySelector('[data-slug-target]');
        if (slugSource && slugTarget) {
            var mapaAcentos = {
                'á': 'a', 'à': 'a', 'ã': 'a', 'â': 'a', 'ä': 'a', 'å': 'a',
                'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e',
                'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i',
                'ó': 'o', 'ò': 'o', 'õ': 'o', 'ô': 'o', 'ö': 'o',
                'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u',
                'ç': 'c', 'ñ': 'n', 'ý': 'y', 'ÿ': 'y'
            };
            var gerarSlug = function (texto) {
                texto = (texto || '').toLowerCase();
                for (var k in mapaAcentos) {
                    if (Object.prototype.hasOwnProperty.call(mapaAcentos, k)) {
                        texto = texto.split(k).join(mapaAcentos[k]);
                    }
                }
                return texto
                    .replace(/[^a-z0-9]+/g, '-')   // não-alfanumérico -> hífen (colapsa)
                    .replace(/^-+|-+$/g, '');       // remove hífens das pontas
            };
            // Ao digitar o nome, regenera o slug (inclusive ao renomear).
            slugSource.addEventListener('input', function () {
                slugTarget.value = gerarSlug(slugSource.value);
            });
        }

        // --- Mini-menu do perfil (header) -----------------------------------
        var perfilToggle = document.querySelector('[data-perfil-toggle]');
        var perfilMenu = document.querySelector('[data-perfil-menu]');
        if (perfilToggle && perfilMenu) {
            perfilToggle.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var aberto = perfilMenu.classList.toggle('aberto');
                perfilToggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
            // Fecha ao clicar fora.
            document.addEventListener('click', function (ev) {
                if (perfilMenu.classList.contains('aberto')
                    && !perfilMenu.contains(ev.target) && ev.target !== perfilToggle) {
                    perfilMenu.classList.remove('aberto');
                    perfilToggle.setAttribute('aria-expanded', 'false');
                }
            });
            // Fecha com ESC.
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && perfilMenu.classList.contains('aberto')) {
                    perfilMenu.classList.remove('aberto');
                    perfilToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // --- Drawer de login (deslogado) ------------------------------------
        var loginOverlay = document.querySelector('[data-login-overlay]');
        var abrirLogin = document.querySelector('[data-abrir-login]');
        if (loginOverlay && abrirLogin) {
            var loginFechar = loginOverlay.querySelector('[data-login-fechar]');
            var painelLogin = loginOverlay.querySelector('[data-login-painel="login"]');
            var painelCadastro = loginOverlay.querySelector('[data-login-painel="cadastro"]');
            var irCadastro = loginOverlay.querySelector('[data-login-ir-cadastro]');
            var voltarLogin = loginOverlay.querySelector('[data-login-voltar]');

            var abrirDrawer = function () {
                loginOverlay.classList.add('aberto');
                document.body.classList.add('menu-aberto');
                var campo = loginOverlay.querySelector('[data-login-painel]:not([hidden]) input');
                if (campo) { campo.focus(); }
            };
            var fecharDrawer = function () {
                loginOverlay.classList.remove('aberto');
                document.body.classList.remove('menu-aberto');
            };

            // JS ligado: abre o drawer (sem JS, o link segue para /entrar).
            abrirLogin.addEventListener('click', function (ev) {
                ev.preventDefault();
                abrirDrawer();
            });
            if (loginFechar) { loginFechar.addEventListener('click', fecharDrawer); }
            loginOverlay.addEventListener('click', function (ev) {
                if (ev.target === loginOverlay) { fecharDrawer(); }   // clique no fundo
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && loginOverlay.classList.contains('aberto')) {
                    fecharDrawer();
                }
            });

            // Alterna login <-> cadastro dentro do mesmo drawer.
            if (irCadastro && painelLogin && painelCadastro) {
                irCadastro.addEventListener('click', function () {
                    painelLogin.hidden = true;
                    painelCadastro.hidden = false;
                    var c = painelCadastro.querySelector('input');
                    if (c) { c.focus(); }
                });
            }
            if (voltarLogin && painelLogin && painelCadastro) {
                voltarLogin.addEventListener('click', function () {
                    painelCadastro.hidden = true;
                    painelLogin.hidden = false;
                    var c = painelLogin.querySelector('input');
                    if (c) { c.focus(); }
                });
            }
        }

        // --- Acordeão de informações nutricionais ---------------------------
        document.querySelectorAll('[data-acordeon]').forEach(function (ac) {
            var btn = ac.querySelector('[data-acordeon-toggle]');
            var corpo = ac.querySelector('[data-acordeon-corpo]');
            if (!btn || !corpo) { return; }
            var aberto = false;
            var recalc = function () {
                if (aberto) { corpo.style.maxHeight = corpo.scrollHeight + 'px'; }
            };
            btn.addEventListener('click', function () {
                aberto = !aberto;
                ac.classList.toggle('aberto', aberto);
                btn.setAttribute('aria-expanded', aberto ? 'true' : 'false');
                corpo.style.maxHeight = aberto ? corpo.scrollHeight + 'px' : '0';
            });
            // Abas internas (kit com várias tabelas): trocar ajusta a altura.
            var abas = ac.querySelectorAll('[data-nutri-aba]');
            var paineis = ac.querySelectorAll('[data-nutri-painel]');
            abas.forEach(function (a) {
                a.addEventListener('click', function () {
                    var alvo = a.getAttribute('data-nutri-aba');
                    abas.forEach(function (x) { x.classList.toggle('ativa', x === a); });
                    paineis.forEach(function (p) {
                        p.classList.toggle('ativo', p.getAttribute('data-nutri-painel') === alvo);
                    });
                    recalc();
                });
            });
            window.addEventListener('resize', recalc);
        });

        // --- Carrossel de banners -------------------------------------------
        document.querySelectorAll('[data-carrossel]').forEach(function (raiz) {
            var trilho = raiz.querySelector('.carrossel-trilho');
            var slides = raiz.querySelectorAll('.carrossel-slide');
            var dots = raiz.querySelectorAll('[data-carrossel-dot]');
            var total = slides.length;
            if (!trilho || total <= 1) {
                return; // 1 slide: sem dots/autoplay/navegação
            }

            var reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var atual = 0;
            var timer = null;

            function ir(i) {
                atual = (i + total) % total;
                slides.forEach(function (s, idx) {
                    s.classList.toggle('ativo', idx === atual);
                });
                dots.forEach(function (d, idx) {
                    d.classList.toggle('ativo', idx === atual);
                    d.setAttribute('aria-current', idx === atual ? 'true' : 'false');
                });
            }
            var prox = function () { ir(atual + 1); };
            var ant = function () { ir(atual - 1); };

            function iniciar() { if (!reduz) { parar(); timer = setInterval(prox, 5000); } }
            function parar() { if (timer) { clearInterval(timer); timer = null; } }
            function reiniciar() { parar(); iniciar(); }

            dots.forEach(function (d) {
                d.addEventListener('click', function () {
                    ir(parseInt(d.getAttribute('data-carrossel-dot'), 10) || 0);
                    reiniciar();
                });
            });
            var btnPrev = raiz.querySelector('[data-carrossel-prev]');
            var btnNext = raiz.querySelector('[data-carrossel-next]');
            if (btnPrev) { btnPrev.addEventListener('click', function () { ant(); reiniciar(); }); }
            if (btnNext) { btnNext.addEventListener('click', function () { prox(); reiniciar(); }); }

            raiz.addEventListener('mouseenter', parar);
            raiz.addEventListener('mouseleave', iniciar);

            // Arrastar/deslizar (swipe) com Pointer Events.
            var x0 = null, dx = 0, arrastando = false;
            trilho.addEventListener('pointerdown', function (e) {
                x0 = e.clientX; dx = 0; arrastando = true; parar();
            });
            trilho.addEventListener('pointermove', function (e) {
                if (arrastando && x0 !== null) { dx = e.clientX - x0; }
            });
            function fimArraste() {
                if (!arrastando) { return; }
                arrastando = false;
                if (Math.abs(dx) > 50) { (dx < 0 ? prox : ant)(); }
                x0 = null; dx = 0;
                iniciar();
            }
            trilho.addEventListener('pointerup', fimArraste);
            trilho.addEventListener('pointercancel', fimArraste);
            trilho.addEventListener('pointerleave', fimArraste);

            ir(0);
            iniciar();
        });

        // --- "Mais vendidos": carrossel horizontal (auto + setas + arraste) --
        document.querySelectorAll('[data-carrossel-h]').forEach(function (raiz) {
            var trilho = raiz.querySelector('[data-mv-trilho]');
            if (!trilho) { return; }
            var btnPrev = raiz.querySelector('[data-mv-prev]');
            var btnNext = raiz.querySelector('[data-mv-next]');
            var cards = trilho.querySelectorAll('.mv-card');

            var reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // Easing cubic-bezier(.33, 1, .68, 1): desacelera suave no fim.
            var easing = (function (x1, y1, x2, y2) {
                function calc(t, a, b) {
                    var A = 1 - 3 * b + 3 * a, B = 3 * b - 6 * a, C = 3 * a;
                    return ((A * t + B) * t + C) * t;
                }
                function slope(t, a, b) {
                    var A = 1 - 3 * b + 3 * a, B = 3 * b - 6 * a, C = 3 * a;
                    return (3 * A * t + 2 * B) * t + C;
                }
                return function (x) {
                    if (x <= 0) { return 0; }
                    if (x >= 1) { return 1; }
                    var t = x;
                    for (var i = 0; i < 5; i++) {
                        var s = slope(t, x1, x2);
                        if (s === 0) { break; }
                        t -= (calc(t, x1, x2) - x) / s;
                    }
                    return calc(t, y1, y2);
                };
            })(0.33, 1, 0.68, 1);

            var maxScroll = function () { return trilho.scrollWidth - trilho.clientWidth; };

            // Atualiza o estado (habilitado/desabilitado) das setas conforme a posição.
            var atualizarSetas = function () {
                var max = maxScroll(), x = trilho.scrollLeft;
                if (btnPrev) { btnPrev.disabled = x <= 1; }
                if (btnNext) { btnNext.disabled = x >= max - 1; }
            };

            // Scroll animado por rAF (controla duração e easing; o nativo não deixa).
            var animId = null;
            function limparAnim() {
                if (animId) { cancelAnimationFrame(animId); animId = null; }
                trilho.style.scrollSnapType = '';
                trilho.style.scrollBehavior = '';
            }
            function animarPara(destino, dur) {
                limparAnim();
                var origem = trilho.scrollLeft, dist = destino - origem;
                if (Math.abs(dist) < 1) { return; }
                // Durante a animação, sem snap/smooth nativo brigando com o rAF.
                trilho.style.scrollSnapType = 'none';
                trilho.style.scrollBehavior = 'auto';
                var inicio = null;
                function frame(ts) {
                    if (inicio === null) { inicio = ts; }
                    var p = Math.min(1, (ts - inicio) / dur);
                    trilho.scrollLeft = origem + dist * easing(p);
                    if (p < 1) { animId = requestAnimationFrame(frame); }
                    else { animId = null; trilho.style.scrollSnapType = ''; trilho.style.scrollBehavior = ''; }
                }
                animId = requestAnimationFrame(frame);
            }

            // Largura de avanço de um card (distância entre dois cards vizinhos).
            var passoCard = function () {
                if (cards.length > 1) { return cards[1].offsetLeft - cards[0].offsetLeft; }
                return Math.max(160, trilho.clientWidth * 0.9);
            };

            // --- Transição automática: um card por vez, em loop -----------
            var autoTimer = null;
            var pausadoHover = false;
            var PAUSA = 2000, DESLIZE = 1200;

            function podeAuto() { return !reduz && cards.length > 1 && maxScroll() > 1; }
            function pararAuto() {
                if (autoTimer) { clearTimeout(autoTimer); autoTimer = null; }
                limparAnim();
            }
            function avancar() {
                var max = maxScroll();
                var destino = (trilho.scrollLeft >= max - 1)
                    ? 0                                              // fim -> volta ao começo
                    : Math.min(trilho.scrollLeft + passoCard(), max);
                animarPara(destino, DESLIZE);
            }
            function agendarAuto() {
                pararAuto();
                if (!podeAuto() || pausadoHover) { return; }
                autoTimer = setTimeout(function ciclo() {
                    avancar();
                    autoTimer = setTimeout(ciclo, PAUSA + DESLIZE);
                }, PAUSA);
            }

            // Setas: deslizam um "trecho" e pausam/retomam o automático.
            function deslizar(dir) {
                pararAuto();
                var max = maxScroll();
                var destino = dir > 0
                    ? Math.min(trilho.scrollLeft + passoCard(), max)
                    : Math.max(trilho.scrollLeft - passoCard(), 0);
                animarPara(destino, 700);
                if (!pausadoHover) { agendarAuto(); }   // retoma depois (se não estiver no hover)
            }
            if (btnPrev) { btnPrev.addEventListener('click', function () { deslizar(-1); }); }
            if (btnNext) { btnNext.addEventListener('click', function () { deslizar(1); }); }

            trilho.addEventListener('scroll', atualizarSetas, { passive: true });
            window.addEventListener('resize', function () { atualizarSetas(); });

            // Pausa no hover (mouse/caneta); retoma ao sair. Toque não conta como hover.
            raiz.addEventListener('pointerenter', function (e) {
                if (e.pointerType === 'touch') { return; }
                pausadoHover = true;
                pararAuto();
            });
            raiz.addEventListener('pointerleave', function (e) {
                if (e.pointerType === 'touch') { return; }
                pausadoHover = false;
                agendarAuto();
            });

            // Arrastar/deslizar (swipe no celular já é nativo; aqui habilita o drag no desktop).
            var baixo = false, xIni = 0, scrollIni = 0, moveu = false;
            trilho.addEventListener('pointerdown', function (e) {
                pararAuto();                              // não deixa o card "fugir" ao interagir
                if (e.pointerType === 'touch') { return; }
                baixo = true;
                moveu = false;
                xIni = e.clientX;
                scrollIni = trilho.scrollLeft;
                trilho.classList.add('arrastando');
            });
            trilho.addEventListener('pointermove', function (e) {
                if (!baixo) { return; }
                var dx = e.clientX - xIni;
                if (Math.abs(dx) > 4) { moveu = true; }
                trilho.scrollLeft = scrollIni - dx;
            });
            var fim = function (e) {
                if (baixo) {
                    baixo = false;
                    trilho.classList.remove('arrastando');
                    // Evita que o "soltar" após arrastar dispare o clique no card (navegação).
                    if (moveu && e && e.target) {
                        var link = e.target.closest('a');
                        if (link) {
                            var suprimir = function (ev) {
                                ev.preventDefault();
                                link.removeEventListener('click', suprimir, true);
                            };
                            link.addEventListener('click', suprimir, true);
                        }
                    }
                    atualizarSetas();
                }
                if (!pausadoHover) { agendarAuto(); }     // toque/drag: retoma o automático
            };
            trilho.addEventListener('pointerup', fim);
            trilho.addEventListener('pointercancel', fim);
            // Não bloquear a seleção/arraste de imagens durante o drag.
            trilho.addEventListener('dragstart', function (e) {
                if (baixo) { e.preventDefault(); }
            });

            atualizarSetas();
            agendarAuto();
        });

        // --- Showcase rotativo de destaques (fade em loop + dots) -----------
        document.querySelectorAll('[data-showcase]').forEach(function (raiz) {
            var slides = raiz.querySelectorAll('[data-showcase-slide]');
            var dots = raiz.querySelectorAll('[data-showcase-dot]');
            var total = slides.length;
            if (total === 0) { return; }

            var atual = 0;
            function ir(i) {
                atual = (i + total) % total;
                slides.forEach(function (s, idx) { s.classList.toggle('ativo', idx === atual); });
                dots.forEach(function (d, idx) {
                    d.classList.toggle('ativo', idx === atual);
                    d.setAttribute('aria-current', idx === atual ? 'true' : 'false');
                });
            }

            // Dots navegáveis (existem só quando há mais de um produto).
            dots.forEach(function (d) {
                d.addEventListener('click', function () {
                    ir(parseInt(d.getAttribute('data-showcase-dot'), 10) || 0);
                    reiniciar();
                });
            });

            ir(0);

            // 1 produto (estático) ou preferência por menos movimento: sem rotação.
            var reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var timer = null;
            function parar() { if (timer) { clearInterval(timer); timer = null; } }
            function iniciar() {
                if (raiz.hasAttribute('data-showcase-estatico') || total <= 1 || reduz) { return; }
                parar();
                // 3s de leitura (produto parado) + 0,9s da animação do blur = 3900ms.
                timer = setInterval(function () { ir(atual + 1); }, 3900);
            }
            function reiniciar() { parar(); iniciar(); }

            // Pausa o loop com o mouse sobre a seção.
            raiz.addEventListener('mouseenter', parar);
            raiz.addEventListener('mouseleave', iniciar);

            iniciar();
        });

        // --- Nome do arquivo escolhido em uploads simples -------------------
        // Para inputs [data-arquivo-nome], mostra "arquivo.ext selecionado" no
        // .arquivo-info do mesmo .campo (mantendo o texto padrão quando vazio).
        document.querySelectorAll('input[type="file"][data-arquivo-nome]').forEach(function (input) {
            var campo = input.closest('.campo');
            var alvo = campo ? campo.querySelector('[data-arquivo-nome-alvo]') : null;
            if (!alvo) { return; }
            var padrao = alvo.textContent;
            input.addEventListener('change', function () {
                var f = input.files && input.files[0];
                alvo.textContent = f ? (f.name + ' selecionado') : padrao;
            });
        });

        // --- Frases do marquee (editar tudo; adicionar/remover linhas) ------
        var marqueeForm = document.querySelector('[data-marquee-form]');
        if (marqueeForm) {
            var mqLista = marqueeForm.querySelector('[data-marquee-lista]');
            var mqModelo = marqueeForm.querySelector('[data-marquee-modelo]');
            var mqAdd = marqueeForm.querySelector('[data-marquee-adicionar]');
            var mqAviso = marqueeForm.querySelector('[data-marquee-limite]');
            var MQ_MAX = 5;

            var mqContar = function () {
                return mqLista.querySelectorAll('[data-marquee-linha]').length;
            };
            var mqAtualizar = function () {
                var n = mqContar();
                if (mqAdd) { mqAdd.disabled = n >= MQ_MAX; }
                if (mqAviso) { mqAviso.hidden = n < MQ_MAX; }
            };
            var mqLigarRemover = function (linha) {
                var x = linha.querySelector('[data-marquee-remover]');
                if (x) {
                    x.addEventListener('click', function () {
                        linha.remove();          // some só no navegador; grava ao Salvar
                        mqAtualizar();
                    });
                }
            };

            // Liga as linhas que já vieram do banco.
            mqLista.querySelectorAll('[data-marquee-linha]').forEach(mqLigarRemover);

            if (mqAdd && mqModelo) {
                mqAdd.addEventListener('click', function () {
                    if (mqContar() >= MQ_MAX) { return; }
                    var frag = mqModelo.content.cloneNode(true);
                    var linha = frag.querySelector('[data-marquee-linha]');
                    mqLista.appendChild(frag);   // linha nova VAZIA, sem recarregar
                    mqLigarRemover(linha);
                    var inp = linha.querySelector('input');
                    if (inp) { inp.focus(); }
                    mqAtualizar();
                });
            }

            mqAtualizar();
        }

        // --- Seletor de quantidade em pílula (− num +) ----------------------
        var qtdPilula = document.querySelector('[data-qtd]');
        if (qtdPilula) {
            var qtdInput = qtdPilula.querySelector('[data-qtd-input]');
            var qtdNum = qtdPilula.querySelector('[data-qtd-num]');
            var qtdMenos = qtdPilula.querySelector('[data-qtd-menos]');
            var qtdMais = qtdPilula.querySelector('[data-qtd-mais]');
            var setQtd = function (v) {
                v = Math.max(1, Math.min(99, v || 1));
                if (qtdInput) { qtdInput.value = v; }
                if (qtdNum) { qtdNum.textContent = v; }
            };
            if (qtdMenos) {
                qtdMenos.addEventListener('click', function () {
                    setQtd((parseInt(qtdInput.value, 10) || 1) - 1);
                });
            }
            if (qtdMais) {
                qtdMais.addEventListener('click', function () {
                    setQtd((parseInt(qtdInput.value, 10) || 1) + 1);
                });
            }
        }

        // --- Galeria do produto (fotos + miniaturas + zonas + dots + lightbox) ---
        var galeria = document.querySelector('[data-galeria]');
        if (galeria) {
            var fotos = galeria.querySelectorAll('[data-galeria-foto]');
            var minis = galeria.querySelectorAll('[data-galeria-mini]');
            var dots  = galeria.querySelectorAll('[data-galeria-dot]');
            var totalFotos = fotos.length;

            var lightbox = galeria.querySelector('[data-lightbox]');
            var lbImg = galeria.querySelector('[data-lightbox-img]');
            var lbContador = galeria.querySelector('[data-lightbox-contador]');

            if (totalFotos > 0) {
                var atualFoto = 0;

                var atualizarLightbox = function () {
                    if (lbImg) { lbImg.setAttribute('src', fotos[atualFoto].getAttribute('src')); }
                    if (lbContador) { lbContador.textContent = (atualFoto + 1) + ' / ' + totalFotos; }
                };
                var irFoto = function (i) {
                    atualFoto = (i + totalFotos) % totalFotos;
                    fotos.forEach(function (el, idx) { el.classList.toggle('ativa', idx === atualFoto); });
                    minis.forEach(function (el, idx) { el.classList.toggle('ativa', idx === atualFoto); });
                    dots.forEach(function (el, idx) { el.classList.toggle('ativa', idx === atualFoto); });
                    atualizarLightbox();
                };

                var galPrev = galeria.querySelector('[data-galeria-prev]');
                var galNext = galeria.querySelector('[data-galeria-next]');
                // Zonas de navegação (nas bordas): stopPropagation p/ não abrir o lightbox.
                if (galPrev) { galPrev.addEventListener('click', function (ev) { ev.stopPropagation(); irFoto(atualFoto - 1); }); }
                if (galNext) { galNext.addEventListener('click', function (ev) { ev.stopPropagation(); irFoto(atualFoto + 1); }); }

                minis.forEach(function (m) {
                    m.addEventListener('click', function () {
                        irFoto(parseInt(m.getAttribute('data-galeria-mini'), 10) || 0);
                    });
                });
                dots.forEach(function (d) {
                    d.addEventListener('click', function (ev) {
                        ev.stopPropagation();
                        irFoto(parseInt(d.getAttribute('data-galeria-dot'), 10) || 0);
                    });
                });

                // Lightbox: compartilha o índice atual da galeria.
                if (lightbox) {
                    var abrirLightbox = function () {
                        atualizarLightbox();
                        lightbox.classList.add('aberto');
                        document.body.classList.add('menu-aberto'); // trava o scroll
                    };
                    var fecharLightbox = function () {
                        lightbox.classList.remove('aberto');
                        document.body.classList.remove('menu-aberto');
                    };

                    // Clicar na foto (centro do palco) amplia.
                    var palco = galeria.querySelector('.galeria-palco');
                    if (palco) { palco.addEventListener('click', abrirLightbox); }
                    var lbFechar = galeria.querySelector('[data-lightbox-fechar]');
                    if (lbFechar) { lbFechar.addEventListener('click', fecharLightbox); }
                    var lbPrev = galeria.querySelector('[data-lightbox-prev]');
                    var lbNext = galeria.querySelector('[data-lightbox-next]');
                    if (lbPrev) { lbPrev.addEventListener('click', function () { irFoto(atualFoto - 1); }); }
                    if (lbNext) { lbNext.addEventListener('click', function () { irFoto(atualFoto + 1); }); }

                    // Clique no fundo escuro fecha.
                    lightbox.addEventListener('click', function (ev) {
                        if (ev.target === lightbox) { fecharLightbox(); }
                    });
                    // Teclado: setas navegam, Esc fecha (só com o lightbox aberto).
                    document.addEventListener('keydown', function (ev) {
                        if (!lightbox.classList.contains('aberto')) { return; }
                        if (ev.key === 'Escape') { fecharLightbox(); }
                        else if (ev.key === 'ArrowLeft') { irFoto(atualFoto - 1); }
                        else if (ev.key === 'ArrowRight') { irFoto(atualFoto + 1); }
                    });
                }

                irFoto(0);
            }
        }

        // --- Aceite de termos habilita o botão de finalizar -----------------
        var aceite = document.getElementById('aceite');
        var btnFinalizar = document.getElementById('btn-finalizar');
        if (aceite && btnFinalizar) {
            var sincronizar = function () {
                btnFinalizar.disabled = !aceite.checked;
            };
            sincronizar(); // estado inicial
            aceite.addEventListener('change', sincronizar);
        }
    });
})();
