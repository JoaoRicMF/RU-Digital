document.addEventListener('DOMContentLoaded', () => {
    
    // 1. ESTADO GLOBAL
    const appState = {
        user: { name: "João", balance: 24.00 },
        transactions: [
            { id: 1, date: '02 FEV', type: 'Refeição - Almoço', value: 4.00, isIncome: false },
            { id: 2, date: '01 FEV', type: 'Recarga - PIX', value: 20.00, isIncome: true },
            { id: 3, date: '31 JAN', type: 'Refeição - Jantar', value: 4.00, isIncome: false },
            { id: 4, date: '31 JAN', type: 'Refeição - Almoço', value: 4.00, isIncome: false },
            { id: 5, date: '28 JAN', type: 'Refeição - Almoço', value: 4.00, isIncome: false },
            { id: 6, date: '29 JAN', type: 'Recarga - Cartão', value: 20.00, isIncome: true },
        ]
    };

    const currencyFormatter = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    // 2. RENDERIZAÇÃO
    function updateBalanceUI() {
        document.querySelectorAll('.balance-display').forEach(el => {
            el.textContent = currencyFormatter.format(appState.user.balance);
        });
    }
    function renderTransactionsSkeleton() {
        const listContainer = document.getElementById('transaction-list');
        if (!listContainer) return;
        
        listContainer.innerHTML = `
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
            <div class="skeleton-row"><div class="skel-box skel-large"></div><div class="skel-box skel-small"></div></div>
        `;
    }

    function renderTransactions() {
        const listContainer = document.getElementById('transaction-list');
        if (!listContainer) return;
        listContainer.innerHTML = ''; 

        appState.transactions.forEach(t => {
            const row = document.createElement('div');
            row.className = `transaction-item ${t.isIncome ? 'trans-income' : 'trans-expense'}`;
            row.innerHTML = `${t.date} | ${t.type} - ${currencyFormatter.format(t.value)}`;
            listContainer.appendChild(row);
        });
    }

    function initUI() {
        updateBalanceUI();
        renderTransactions();
    }

    // 3. NAVEGAÇÃO
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.view');
    const mainContent = document.getElementById('main-content');

    window.navigateTo = function(targetId) {
        navItems.forEach(nav => nav.classList.toggle('active', nav.getAttribute('data-target') === targetId));
        views.forEach(view => view.classList.toggle('active', view.id === targetId));
        if (mainContent) mainContent.scrollTop = 0;
    };

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const targetId = item.getAttribute('data-target');
            if (targetId) window.navigateTo(targetId);
        });
    });

    // 4. FUNCIONALIDADES
    // --- Lógica do Modal Nutricional ---
    const nutritionModal = document.getElementById('nutrition-modal');
    document.getElementById('btn-nutrition')?.addEventListener('click', () => {
        nutritionModal.classList.add('open');
    });
    
    // Modifiquei para buscar especificamente o botão fechar do modal nutricional
    document.querySelector('#nutrition-modal .close-modal')?.addEventListener('click', () => {
        nutritionModal.classList.remove('open');
    });

    // --- Lógica do Novo Modal de Recarga ---
    const rechargeModal = document.getElementById('recharge-modal');
    const rechargeInput = document.getElementById('recharge-input-value');
    
    // 1. Abrir Modal de Recarga ao clicar nos botões "Fazer Recarga"
    document.querySelectorAll('.btn-recharge').forEach(btn => {
        btn.addEventListener('click', () => {
            if(rechargeInput) rechargeInput.value = ''; // Limpa o campo sempre que abrir
            rechargeModal.classList.add('open');
        });
    });

    // 2. Fechar Modal de Recarga no 'X'
    document.getElementById('close-recharge')?.addEventListener('click', () => {
        rechargeModal.classList.remove('open');
    });

    // 3. Processar a Recarga ao clicar em "Confirmar"
    document.getElementById('btn-confirm-recharge')?.addEventListener('click', function() {
        if (!rechargeInput || !rechargeInput.value) return;
        const value = parseFloat(rechargeInput.value.replace(',', '.')); 
        
        if (!isNaN(value) && value > 0) {
            const btn = this; // O botão que foi clicado
            
            // 1. Ativa o estado de "Loading" (bloqueia e mostra o spinner)
            btn.classList.add('is-loading');
            
            // 2. Simula o tempo de resposta da rede (ex: 1.5 segundos)
            setTimeout(() => {
                // Remove o loading do botão
                btn.classList.remove('is-loading');
                
                // Atualiza o Estado Global
                appState.user.balance += value;
                appState.transactions.unshift({
                    id: Date.now(), date: 'HOJE', type: 'Recarga - App', value: value, isIncome: true
                });
                
                // Atualiza Saldo na UI e fecha o modal
                updateBalanceUI(); 
                rechargeModal.classList.remove('open');
                
                // Dispara o Skeleton no Extrato antes de mostrar o Extrato real
                renderTransactionsSkeleton();
                setTimeout(() => {
                    renderTransactions(); // Mostra as transações reais 800ms depois
                }, 800); 
                
                // Alerta de Sucesso (um pequeno delay para evitar travar a UI)
                setTimeout(() => {
                    alert(`Recarga de ${currencyFormatter.format(value)} realizada com sucesso!`);
                }, 100);
                
            }, 1500); // Fim da simulação de 1.5s
            
        } else {
            alert("Valor inválido.");
        }
    });

    // --- Fechar qualquer modal ao clicar fora dele (Overlay) ---
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            // Garante que o clique foi no fundo escuro, e não no conteúdo branco
            if (e.target === modal) {
                modal.classList.remove('open');
            }
        });
    });

    // --- Interações do Cabeçalho (Header) ---
    
    // 1. Botão de Perfil: Redireciona para a aba de perfil
    const btnProfileHeader = document.getElementById('btn-profile-header');
    if (btnProfileHeader) {
        btnProfileHeader.addEventListener('click', () => {
            window.navigateTo('view-profile'); // Reaproveita a função de navegação existente
        });
    }

    // 2. Botão de Sino: Abre e fecha o modal de notificações
    const notificationModal = document.getElementById('notification-modal');
    const btnNotifications = document.getElementById('btn-notifications');
    const closeNotifications = document.getElementById('close-notifications');

    if (btnNotifications) {
        btnNotifications.addEventListener('click', () => {
            notificationModal.classList.add('open');
        });
    }

    if (closeNotifications) {
        closeNotifications.addEventListener('click', () => {
            notificationModal.classList.remove('open');
        });
    }

    // 5. AUTENTICAÇÃO
    const VALID_USER = { email: "joao@ufcat.edu.br", password: "123456" };
    const loginView = document.getElementById('view-login'), appHeader = document.getElementById('app-header'), bottomNav = document.getElementById('bottom-nav');
    const btnSubmitRating = document.getElementById('btn-submit-rating');

    if (btnSubmitRating) {
        btnSubmitRating.addEventListener('click', () => {
            const allGroups = document.querySelectorAll('.interactive-stars');
            const commentBox = document.querySelector('.comment-box');
            let answeredCount = 0;
            
            // Verifica quantas categorias receberam uma nota
            allGroups.forEach(g => {
                if (g.getAttribute('data-selected')) {
                    answeredCount++;
                }
            });

            // Validação: Exige que pelo menos uma categoria tenha sido avaliada
            if (answeredCount > 0) {
                // Aqui no futuro enviaria os dados (nota e comentário) para a API do backend
                const commentText = commentBox ? commentBox.value.trim() : "";
                console.log("Feedback pronto para envio:", { notasDadas: answeredCount, comentario: commentText });

                alert("Avaliação enviada com sucesso! O RU agradece o seu feedback.");
                
                // Reseta as estrelas para o estado inicial
                allGroups.forEach(g => {
                    g.removeAttribute('data-selected');
                    g.querySelectorAll('i').forEach(s => {
                        s.className = 'fa-regular fa-star';
                    });
                });
                
                // Limpa a caixa de texto
                if (commentBox) commentBox.value = "";
                
                // Navega de volta para o Início
                window.navigateTo('view-home');
            } else {
                alert("Por favor, avalie pelo menos um critério clicando nas estrelas antes de enviar.");
            }
        });
    }

    function checkAuth() {
        if (localStorage.getItem('ru_digital_logged_in') === 'true') {
            loginView.classList.add('d-none'); appHeader.classList.remove('d-none'); mainContent.classList.remove('d-none'); bottomNav.classList.remove('d-none'); window.navigateTo('view-home');
        } else {
            loginView.classList.remove('d-none'); appHeader.classList.add('d-none'); mainContent.classList.add('d-none'); bottomNav.classList.add('d-none');
        }
    }

    document.getElementById('btn-login')?.addEventListener('click', () => {
        if (document.getElementById('login-email').value.trim() === VALID_USER.email && document.getElementById('login-password').value.trim() === VALID_USER.password) {
            localStorage.setItem('ru_digital_logged_in', 'true'); checkAuth();
        } else {
            document.getElementById('login-error').classList.remove('d-none');
        }
    });

    document.getElementById('btn-logout')?.addEventListener('click', () => {
        localStorage.removeItem('ru_digital_logged_in'); 
        document.getElementById('login-email').value = ''; document.getElementById('login-password').value = '';
        checkAuth();
    });

    document.querySelectorAll('.interactive-stars').forEach(group => {
        const stars = group.querySelectorAll('i');
        
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const val = parseInt(this.getAttribute('data-val'));
                
                stars.forEach(s => {
                    const sVal = parseInt(s.getAttribute('data-val'));
                    if (sVal <= val) {
                        s.className = 'fa-solid fa-star active';
                        
                        // Efeito tátil "Pop" (Micro-interação)
                        s.classList.add('star-animating');
                        setTimeout(() => s.classList.remove('star-animating'), 300);
                    } else {
                        s.className = 'fa-regular fa-star';
                    }
                });
                
                // Guarda a nota escolhida num atributo data-selected do contentor pai
                group.setAttribute('data-selected', val);
            });
        });
    });

    checkAuth(); initUI();

    // --- Lógica de "Esqueci minha Senha" ---
    const forgotModal       = document.getElementById('forgot-password-modal');
    const forgotStep1       = document.getElementById('forgot-step-1');
    const forgotStep2       = document.getElementById('forgot-step-2');
    const forgotEmailInput  = document.getElementById('forgot-email-input');
    const forgotError       = document.getElementById('forgot-error');
    const forgotEmailSent   = document.getElementById('forgot-email-sent');
    const VALID_RECOVERY_EMAIL = 'joao@ufcat.edu.br';

    document.getElementById('btn-forgot-password')?.addEventListener('click', () => {
        // Reseta o modal para o passo 1
        forgotStep1.classList.remove('d-none');
        forgotStep2.classList.add('d-none');
        if (forgotEmailInput) forgotEmailInput.value = '';
        if (forgotError) forgotError.classList.add('d-none');
        forgotModal.classList.add('open');
    });

    document.getElementById('close-forgot')?.addEventListener('click', () => {
        forgotModal.classList.remove('open');
    });

    document.getElementById('btn-send-reset')?.addEventListener('click', function() {
        const email = forgotEmailInput?.value.trim();
        if (!email) return;

        if (email !== VALID_RECOVERY_EMAIL) {
            forgotError?.classList.remove('d-none');
            return;
        }

        // Simula envio: mostra loading no botão
        this.classList.add('is-loading');
        forgotError?.classList.add('d-none');

        setTimeout(() => {
            this.classList.remove('is-loading');
            if (forgotEmailSent) forgotEmailSent.textContent = email;
            forgotStep1.classList.add('d-none');
            forgotStep2.classList.remove('d-none');
        }, 1500);
    });

    document.getElementById('btn-close-forgot-success')?.addEventListener('click', () => {
        forgotModal.classList.remove('open');
    });

    renderTransactionsSkeleton();

setTimeout(() => {
        // Remove a classe skeleton dos textos (Saldo e Cardápio)
        document.querySelectorAll('.skeleton').forEach(el => {
            el.classList.remove('skeleton');
        });

        // Remove skeleton dos textos inline (nome e cardápio)
        const nameEl = document.getElementById('home-user-name');
        if (nameEl) {
            nameEl.classList.remove('skeleton-text', 'skel-name');
            nameEl.textContent = appState.user.name;
        }
        const menuEl = document.getElementById('home-menu-text');
        if (menuEl) {
            menuEl.classList.remove('skeleton-text', 'skel-menu');
            menuEl.textContent = 'Frango Grelhado, Purê e Salada';
        }
        
        // Injeta os dados "reais" após o carregamento
        updateBalanceUI();
        renderTransactions(); 
    }, 1200);
});