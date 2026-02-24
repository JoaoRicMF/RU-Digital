document.addEventListener('DOMContentLoaded', () => {

    // ----------------------------------------------------------
    // 0. CONFIGURAÇÃO DA API
    // ----------------------------------------------------------
    const API_BASE = 'http://localhost:8000/api';

    /**
     * Wrapper de fetch que:
     * - Sempre envia Content-Type: application/json
     * - Injeta o JWT no header Authorization automaticamente
     * - Lança um erro com a mensagem da API se o status não for 2xx
     */
    async function apiFetch(endpoint, options = {}) {
        const token = localStorage.getItem('ru_jwt_token');

        const headers = {
            'Content-Type': 'application/json',
            ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
            ...(options.headers ?? {}),
        };

        const response = await fetch(`${API_BASE}${endpoint}`, {
            ...options,
            headers,
        });

        const data = await response.json();

        // Se a API retornou erro, lança para o catch do chamador
        if (!response.ok) {
            throw new Error(data.mensagem ?? `Erro ${response.status}`);
        }

        return data; // { status: 'success', data: { ... } }
    }


    // ----------------------------------------------------------
    // 1. ESTADO GLOBAL
    //    Preenchido pelos dados reais da API após login
    // ----------------------------------------------------------
    const appState = {
        user:         { name: '', balance: 0 },
        transactions: [],
        jwtPayload:   null, // payload decodificado do JWT
        cardapioId:   null, // ID real do cardápio do dia, preenchido por refreshMenu()
        menuData:     [],       // Guarda o almoço e janta da data selecionada
        selectedDate: new Date().toISOString().split('T')[0], // Hoje (YYYY-MM-DD)
        selectedMeal: 'almoco'  // Padrão é almoço
    };

    const currencyFormatter = new Intl.NumberFormat('pt-BR', {
        style: 'currency', currency: 'BRL',
    });

    /** Decodifica o payload do JWT sem verificar assinatura (client-side) */
    function decodeJwtPayload(token) {
        try {
            const base64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            return JSON.parse(atob(base64));
        } catch {
            return null;
        }
    }


    // ----------------------------------------------------------
    // 2. RENDERIZAÇÃO
    // ----------------------------------------------------------
    function updateBalanceUI() {
        document.querySelectorAll('.balance-display').forEach(el => {
            el.classList.remove('skeleton');
            el.textContent = currencyFormatter.format(appState.user.balance);
        });
    }

    function renderTransactionsSkeleton() {
        const list = document.getElementById('transaction-list');
        if (!list) return;
        list.innerHTML = `
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
        `;
    }

    function renderTransactions() {
        const list = document.getElementById('transaction-list');
        if (!list) return;
        list.innerHTML = '';

        if (appState.transactions.length === 0) {
            list.innerHTML = '<p style="color:#888;text-align:center;padding:20px">Nenhuma transação encontrada.</p>';
            return;
        }

        appState.transactions.forEach(t => {
            const row = document.createElement('div');
            row.className = `transaction-item ${t.isIncome ? 'trans-income' : 'trans-expense'}`;
            const data = new Date(t.data).toLocaleDateString('pt-BR', { day:'2-digit', month:'short' }).toUpperCase();
            row.innerHTML = `${data} | ${t.descricao} - ${currencyFormatter.format(t.valor)}`;
            list.appendChild(row);
        });
    }

    /** Busca saldo e extrato na API e atualiza a UI */
    async function refreshWalletData() {
        try {
            // Requisições paralelas para melhor performance
            const [saldoResp, extratoResp] = await Promise.all([
                apiFetch('/saldo'),
                apiFetch('/extrato?limit=10'),
            ]);

            appState.user.balance     = saldoResp.data.saldo;
            appState.transactions     = extratoResp.data.transacoes;

            updateBalanceUI();
            renderTransactions();
        } catch (err) {
            console.error('[refreshWalletData]', err);
        }
    }

    /** Busca e exibe o cardápio de hoje */
    async function fetchMenuForHome() {
        const menuEl = document.getElementById('home-menu-text');
        try {
            const today = new Date().toISOString().split('T')[0];
            const resp = await apiFetch(`/cardapio?data=${today}`);
            const almoco = resp.data.cardapios.find(c => c.refeicao === 'almoco');

            if (almoco && menuEl) {
                appState.cardapioId = almoco.id ?? null; // ID para as avaliações
                const principal = almoco.itens.find(i => i.categoria === 'principal');
                const guarnicao = almoco.itens.find(i => i.categoria === 'guarnicao');
                
                menuEl.classList.remove('skeleton-text', 'skel-menu');
                menuEl.textContent = [principal?.descricao, guarnicao?.descricao].filter(Boolean).join(', ');
            } else if (menuEl) {
                menuEl.classList.remove('skeleton-text', 'skel-menu');
                menuEl.textContent = 'Cardápio não disponível hoje.';
            }
        } catch (err) {
            if (menuEl) menuEl.textContent = 'Erro ao carregar';
        }
    }

    /** Inicializa a interface de dias da semana e os cliques */
    function initMenuUI() {
        const weekContainer = document.getElementById('week-days-container');
        if (!weekContainer) return;
        
        const diasSemana = ['S', 'T', 'Q', 'Q', 'S', 'S'];
        const nomesDias = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        
        // Lógica para encontrar a Segunda-feira da semana atual
        const today = new Date();
        const dayOfWeek = today.getDay(); // 0 é Domingo, 1 a 6 são Seg a Sáb
        const diffToMonday = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
        const monday = new Date(today.setDate(diffToMonday));

        weekContainer.innerHTML = ''; // Limpa antes de preencher
        
        // Gera os 6 botões (Seg a Sáb)
        for (let i = 0; i < 6; i++) {
            const date = new Date(monday);
            date.setDate(monday.getDate() + i);
            const dateString = date.toISOString().split('T')[0];
            
            const btn = document.createElement('button');
            // Se a data gerada for igual a selecionada no appState, fica ativa
            btn.className = `day-btn ${dateString === appState.selectedDate ? 'active' : ''}`;
            btn.textContent = diasSemana[i];
            
            btn.addEventListener('click', () => {
                // Remove active de todos e bota no clicado
                document.querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                // Atualiza o estado e a interface
                appState.selectedDate = dateString;
                document.getElementById('full-menu-title').textContent = `Cardápio de ${nomesDias[i]}-Feira`;
                
                // Busca os dados da nova data na API
                fetchMenuForSelectedDate();
            });
            
            weekContainer.appendChild(btn);

            // Ajusta o título inicial se hoje for de Seg a Sáb
            if (dateString === appState.selectedDate) {
                document.getElementById('full-menu-title').textContent = `Cardápio de ${nomesDias[i]}-Feira`;
            }
        }

        // Eventos do Switch Almoço/Janta
        document.querySelectorAll('.meal-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.meal-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                appState.selectedMeal = btn.dataset.meal; // 'almoco' ou 'jantar'
                renderMenuDetails(); // Renderiza usando os dados já baixados
            });
        });

        // Carrega a aba de cardápio pela primeira vez
        fetchMenuForSelectedDate();
    }

    /** Busca o cardápio do dia selecionado e renderiza */
    async function fetchMenuForSelectedDate() {
        const fullMenuList = document.getElementById('full-menu-list');
        if (fullMenuList) fullMenuList.innerHTML = '<div class="skeleton-text skel-menu">Carregando cardápio...</div>';

        try {
            // Usa o parâmetro ?data= para pegar o dia exato
            const resp = await apiFetch(`/cardapio?data=${appState.selectedDate}`);
            appState.menuData = resp.data.cardapios; // Guarda almoço e janta no estado local
            renderMenuDetails();
        } catch (err) {
            console.error('[fetchMenuForSelectedDate]', err);
            if (fullMenuList) fullMenuList.innerHTML = '<li>Erro ao carregar o cardápio.</li>';
        }
    }

    /** Exibe os itens com base na refeição escolhida no Switch */
    function renderMenuDetails() {
        const fullMenuList = document.getElementById('full-menu-list');
        if (!fullMenuList) return;

        // Procura nos dados da API se existe a refeição que o utilizador marcou (almoço ou janta)
        const refeicao = appState.menuData.find(c => c.refeicao === appState.selectedMeal);

        if (refeicao) {
            fullMenuList.innerHTML = '';
            const nomesCategorias = {
                'principal': 'Principal',
                'guarnicao': 'Guarnição',
                'arroz_feijao': 'Arroz / Feijão',
                'salada': 'Salada',
                'sobremesa': 'Sobremesa',
                'suco': 'Suco'
            };

            refeicao.itens.forEach(item => {
                const li = document.createElement('li');
                const catFormatada = nomesCategorias[item.categoria] || item.categoria;
                const imgTag = item.imagem_url ? `<br><img src="${item.imagem_url}" style="max-width: 100px; border-radius: 8px; margin-top: 5px;">` : '';
                
                li.innerHTML = `<strong>${catFormatada}:</strong> ${item.descricao} ${imgTag}`;
                fullMenuList.appendChild(li);
            });
        } else {
            const nomeRefeicao = appState.selectedMeal === 'almoco' ? 'almoço' : 'jantar';
            fullMenuList.innerHTML = `<li style="text-align:center; padding: 20px 0; color: #888;">Nenhum ${nomeRefeicao} planeado para este dia.</li>`;
        }
    }

    // ----------------------------------------------------------
    // 3. NAVEGAÇÃO
    // ----------------------------------------------------------
    const navItems    = document.querySelectorAll('.nav-item');
    const views       = document.querySelectorAll('.view');
    const mainContent = document.getElementById('main-content');

    window.navigateTo = function (targetId) {
        navItems.forEach(nav =>
            nav.classList.toggle('active', nav.getAttribute('data-target') === targetId)
        );
        views.forEach(view =>
            view.classList.toggle('active', view.id === targetId)
        );
        if (mainContent) mainContent.scrollTop = 0;

        // Ao entrar na carteira, atualiza os dados
        if (targetId === 'view-wallet') {
            renderTransactionsSkeleton();
            refreshWalletData();
        }
    };

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const targetId = item.getAttribute('data-target');
            if (targetId) window.navigateTo(targetId);
        });
    });


    // ----------------------------------------------------------
    // 4. MODAL NUTRICIONAL
    // ----------------------------------------------------------
    const nutritionModal = document.getElementById('nutrition-modal');
    document.getElementById('btn-nutrition')?.addEventListener('click', () => {
        nutritionModal.classList.add('open');
    });
    document.querySelector('#nutrition-modal .close-modal')?.addEventListener('click', () => {
        nutritionModal.classList.remove('open');
    });


    // ----------------------------------------------------------
    // 5. MODAL DE RECARGA — integrado com a API
    // ----------------------------------------------------------
    const rechargeModal = document.getElementById('recharge-modal');
    const rechargeInput = document.getElementById('recharge-input-value');

    document.querySelectorAll('.btn-recharge').forEach(btn => {
        btn.addEventListener('click', () => {
            if (rechargeInput) rechargeInput.value = '';
            rechargeModal.classList.add('open');
        });
    });

    document.getElementById('close-recharge')?.addEventListener('click', () => {
        rechargeModal.classList.remove('open');
    });

    document.getElementById('btn-confirm-recharge')?.addEventListener('click', async function () {
        if (!rechargeInput?.value) return;

        const value = parseFloat(rechargeInput.value.replace(',', '.'));
        if (isNaN(value) || value <= 0) {
            alert('Valor inválido.');
            return;
        }

        const btn = this;
        btn.classList.add('is-loading');

        try {
            const resp = await apiFetch('/recarga', {
                method: 'POST',
                body: JSON.stringify({ valor: value, metodo: 'app' }),
            });

            // Atualiza o estado local com o saldo retornado pelo servidor
            appState.user.balance = resp.data.saldo_atual;
            updateBalanceUI();
            rechargeModal.classList.remove('open');

            // Atualiza extrato com skeleton
            renderTransactionsSkeleton();
            const extratoResp = await apiFetch('/extrato?limit=10');
            appState.transactions = extratoResp.data.transacoes;
            renderTransactions();

            alert(resp.data.mensagem);

        } catch (err) {
            alert('Erro ao realizar recarga: ' + err.message);
        } finally {
            btn.classList.remove('is-loading');
        }
    });


    // ----------------------------------------------------------
    // 6. AVALIAÇÃO — integrada com a API
    // ----------------------------------------------------------
    document.querySelectorAll('.interactive-stars').forEach(group => {
        const stars = group.querySelectorAll('i');
        stars.forEach(star => {
            star.addEventListener('click', function () {
                const val = parseInt(this.getAttribute('data-val'));
                stars.forEach(s => {
                    const sVal = parseInt(s.getAttribute('data-val'));
                    s.className = sVal <= val ? 'fa-solid fa-star active' : 'fa-regular fa-star';
                    if (sVal <= val) {
                        s.classList.add('star-animating');
                        setTimeout(() => s.classList.remove('star-animating'), 300);
                    }
                });
                group.setAttribute('data-selected', val);
            });
        });
    });

    document.getElementById('btn-submit-rating')?.addEventListener('click', async function () {
        const allGroups = document.querySelectorAll('.interactive-stars');
        const commentBox = document.querySelector('.comment-box');

        const notas = {};
        const campos = ['nota_sabor', 'nota_temp', 'nota_atend', 'nota_limpeza', 'nota_geral'];

        allGroups.forEach((g, index) => {
            const selected = g.getAttribute('data-selected');
            if (selected) notas[campos[index]] = parseInt(selected);
        });

        if (Object.keys(notas).length === 0) {
            alert('Por favor, avalie pelo menos um critério clicando nas estrelas antes de enviar.');
            return;
        }

        // Garante que o cardápio do dia foi carregado antes de enviar
        if (!appState.cardapioId) {
            alert('Não foi possível identificar o cardápio do dia. Tente novamente em instantes.');
            return;
        }

        const btn = this;
        btn.disabled = true;

        try {
            const resp = await apiFetch('/avaliacao', {
                method: 'POST',
                body: JSON.stringify({
                    cardapio_id: appState.cardapioId, // ID real do cardápio carregado via API
                    ...notas,
                    comentario: commentBox?.value.trim() ?? '',
                }),
            });

            alert(resp.data.mensagem);

            // Reseta estrelas
            allGroups.forEach(g => {
                g.removeAttribute('data-selected');
                g.querySelectorAll('i').forEach(s => { s.className = 'fa-regular fa-star'; });
            });
            if (commentBox) commentBox.value = '';

            window.navigateTo('view-home');

        } catch (err) {
            alert('Erro ao enviar avaliação: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    });


    // ----------------------------------------------------------
    // 7. NOTIFICAÇÕES
    // ----------------------------------------------------------
    const notificationModal = document.getElementById('notification-modal');

    document.getElementById('btn-notifications')?.addEventListener('click', () => {
        notificationModal.classList.add('open');
    });
    document.getElementById('close-notifications')?.addEventListener('click', () => {
        notificationModal.classList.remove('open');
    });


    // ----------------------------------------------------------
    // 8. PERFIL E HEADER
    // ----------------------------------------------------------
    document.getElementById('btn-profile-header')?.addEventListener('click', () => {
        window.navigateTo('view-profile');
    });


    // ----------------------------------------------------------
    // 9. FECHAR MODAIS AO CLICAR NO OVERLAY
    // ----------------------------------------------------------
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', e => {
            if (e.target === modal) modal.classList.remove('open');
        });
    });


    // ----------------------------------------------------------
    // 10. AUTENTICAÇÃO — usa a API real
    // ----------------------------------------------------------
    const loginView  = document.getElementById('view-login');
    const appHeader  = document.getElementById('app-header');
    const bottomNav  = document.getElementById('bottom-nav');

    function showApp() {
        loginView.classList.add('d-none');
        appHeader.classList.remove('d-none');
        mainContent.classList.remove('d-none');
        bottomNav.classList.remove('d-none');
    }

    function showLogin() {
        loginView.classList.remove('d-none');
        appHeader.classList.add('d-none');
        mainContent.classList.add('d-none');
        bottomNav.classList.add('d-none');
    }

    function checkAuth() {
        const token = localStorage.getItem('ru_jwt_token');
        if (!token) { showLogin(); return; }

        const payload = decodeJwtPayload(token);
        if (!payload || payload.exp < Math.floor(Date.now() / 1000)) {
            localStorage.removeItem('ru_jwt_token');
            localStorage.removeItem('ru_user_data'); // Limpa dados extra
            showLogin();
            return;
        }

        appState.jwtPayload = payload;
        showApp();
        window.navigateTo('view-home');

        // --- NOVA LÓGICA DE PREENCHIMENTO DINÂMICO ---
        const userData = JSON.parse(localStorage.getItem('ru_user_data') || '{}');
        const primeiroNome = payload.nome ? payload.nome.split(' ')[0] : 'Estudante';
        const cursoUtilizador = userData.curso || 'UFCAT';

        // 1. Atualiza todas as saudações (Home, Carteira, Avaliação) e remove skeletons
        document.querySelectorAll('.user-first-name, #home-user-name').forEach(el => {
            el.classList.remove('skeleton-text', 'skel-name');
            el.textContent = primeiroNome;
        });

        // 2. Atualiza Sidebar no Desktop
        const sidebarName = document.getElementById('sidebar-name');
        const sidebarRole = document.getElementById('sidebar-role');
        if (sidebarName) sidebarName.textContent = payload.nome;
        if (sidebarRole) sidebarRole.textContent = cursoUtilizador;

        // 3. Atualiza Ecrã de Perfil
        const profileName = document.getElementById('profile-name');
        const profileCourse = document.getElementById('profile-course');
        if (profileName) profileName.textContent = payload.nome;
        if (profileCourse) profileCourse.textContent = cursoUtilizador;

        // Carrega dados reais com skeleton
        renderTransactionsSkeleton();
        refreshWalletData();
        fetchMenuForHome();
        initMenuUI();
    }

    // LOGIN
    // LOGIN
    document.getElementById('btn-login')?.addEventListener('click', async () => {
        const email = document.getElementById('login-email').value.trim();
        const senha = document.getElementById('login-password').value.trim();
        const errorEl = document.getElementById('login-error');
        const btnLogin = document.getElementById('btn-login');

        if (!email || !senha) {
            errorEl.textContent = 'Preencha o e-mail e a senha.'; // Adicionado feedback claro
            errorEl.classList.remove('d-none');
            return;
        }

        btnLogin.classList.add('is-loading');
        errorEl.classList.add('d-none');

        try {
            const resp = await apiFetch('/auth/login', {
                method: 'POST',
                body: JSON.stringify({ email, senha }),
            });

            const token = resp.token ?? resp.data?.token;
            const saldo = resp.data?.usuario?.saldo ?? resp.data?.saldo ?? 0;

            if (!token) {
                console.error('[login] Resposta da API não contém token:', resp);
                errorEl.textContent = 'Erro interno. Token não recebido.';
                errorEl.classList.remove('d-none');
                return;
            }

            localStorage.setItem('ru_jwt_token', token);
            if (resp.data && resp.data.usuario) {
                localStorage.setItem('ru_user_data', JSON.stringify(resp.data.usuario));
            }
            
            appState.user.balance = saldo;
            checkAuth();

        } catch (err) {
            console.error('[login] Erro:', err.message);
            
            // A MAGIA ACONTECE AQUI: Em vez de texto estático, injetamos a mensagem da API
            errorEl.textContent = err.message || 'Erro ao conectar com o servidor.';
            errorEl.classList.remove('d-none');
            
        } finally {
            btnLogin.classList.remove('is-loading');
        }
    });

    // LOGOUT
    document.getElementById('btn-logout')?.addEventListener('click', () => {
        localStorage.removeItem('ru_jwt_token');
        localStorage.removeItem('ru_user_data');
        document.getElementById('login-email').value = '';
        document.getElementById('login-password').value = '';
        appState.user = { name: '', balance: 0 };
        appState.transactions = [];

        // Repõe skeletons para o próximo utilizador não ver dados antigos num flash
        document.querySelectorAll('.user-first-name, #home-user-name').forEach(el => {
            el.classList.add('skeleton-text', 'skel-name');
            el.textContent = '';
        });
        document.querySelectorAll('.balance-display').forEach(el => {
            el.classList.add('skeleton');
            el.textContent = '';
        });

        showLogin();
    });


    // ----------------------------------------------------------
    // 11. RECUPERAÇÃO DE SENHA — usa a API real
    // ----------------------------------------------------------
    const forgotModal      = document.getElementById('forgot-password-modal');
    const forgotStep1      = document.getElementById('forgot-step-1');
    const forgotStep2      = document.getElementById('forgot-step-2');
    const forgotEmailInput = document.getElementById('forgot-email-input');
    const forgotError      = document.getElementById('forgot-error');

    document.getElementById('btn-forgot-password')?.addEventListener('click', () => {
        forgotStep1.classList.remove('d-none');
        forgotStep2.classList.add('d-none');
        if (forgotEmailInput) forgotEmailInput.value = '';
        forgotError?.classList.add('d-none');
        forgotModal.classList.add('open');
    });

    document.getElementById('close-forgot')?.addEventListener('click', () => {
        forgotModal.classList.remove('open');
    });

    document.getElementById('btn-send-reset')?.addEventListener('click', async function () {
        const email = forgotEmailInput?.value.trim();
        if (!email) return;

        this.classList.add('is-loading');
        forgotError?.classList.add('d-none');

        try {
            await apiFetch('/auth/recuperar', {
                method: 'POST',
                body: JSON.stringify({ email }),
            });

            const sentEl = document.getElementById('forgot-email-sent');
            if (sentEl) sentEl.textContent = email;
            forgotStep1.classList.add('d-none');
            forgotStep2.classList.remove('d-none');

        } catch (err) {
            // A API retorna mensagem genérica por segurança.
            // Só mostra erro se for erro de rede/servidor.
            forgotError?.classList.remove('d-none');
        } finally {
            this.classList.remove('is-loading');
        }
    });

    document.getElementById('btn-close-forgot-success')?.addEventListener('click', () => {
        forgotModal.classList.remove('open');
    });


    // ----------------------------------------------------------
    // 12. INICIALIZAÇÃO
    // ----------------------------------------------------------
    checkAuth();
});