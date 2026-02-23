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
    document.getElementById('btn-confirm-recharge')?.addEventListener('click', () => {
        const inputValue = rechargeInput.value;
        if (!inputValue) return; // Se estiver vazio, não faz nada
        
        // Aceita vírgula ou ponto
        const value = parseFloat(inputValue.replace(',', '.')); 

        if (!isNaN(value) && value > 0) {
            // Atualiza Estado
            appState.user.balance += value;
            appState.transactions.unshift({
                id: Date.now(), 
                date: 'HOJE', 
                type: 'Recarga - App', 
                value: value, 
                isIncome: true
            });
            
            // Atualiza UI
            updateBalanceUI(); 
            renderTransactions();
            
            // Fecha modal e avisa
            rechargeModal.classList.remove('open');
            alert(`Recarga de ${currencyFormatter.format(value)} realizada com sucesso!`);
        } else {
            alert("Valor inválido. Por favor, insira um número maior que zero.");
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

    checkAuth(); initUI();
});