/* =============================================================================
   Comportamentos da interface (genéricos, sem dependências externas):
   - Abrir/fechar o menu no celular.
   - Abrir/fechar o modal de regras.
   - Habilitar o botão de finalizar só com o aceite marcado.
   ============================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

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

        // --- Contador de fotos escolhidas -----------------------------------
        var arqInput = document.querySelector('[data-arquivo-input]');
        var arqInfo = document.querySelector('[data-arquivo-info]');
        if (arqInput && arqInfo) {
            var arqTextoPadrao = arqInfo.textContent;
            arqInput.addEventListener('change', function () {
                var n = arqInput.files ? arqInput.files.length : 0;
                arqInfo.textContent = n > 0
                    ? (n + (n === 1 ? ' foto selecionada' : ' fotos selecionadas'))
                    : arqTextoPadrao;
            });
        }

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
