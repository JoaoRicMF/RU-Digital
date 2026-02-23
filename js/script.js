document.addEventListener('DOMContentLoaded', () => {
    
    // ==========================================================================
    // 1. ESTADO GLOBAL (Simulação de Backend)
    // ==========================================================================
    const appState = {
        user: {
            name: "João",
            balance: 24.00
        },
        transactions: [
            { id: 1, date: new Date('2026-02-01'), type: 'Recarga - PIX', value: 20.00, isIncome: true },
            { id: 2, date: new Date('2026-01-31'), type: 'Refeição - Jantar', value: 4.00, isIncome: false },
            { id: 3, date: new Date('2026-01-31'), type: 'Refeição - Almoço', value: 4.00, isIncome: false },
        ]
    };

    // Formatador de Moeda (BRL)
    const currencyFormatter = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    });

    // Formatador de Data (Ex: 02 FEV)
    const dateFormatter = (dateObj) => {
        const day = String(dateObj.getDate()).padStart(2, '0');
        const month = dateObj.toLocaleString('pt-BR', { month: 'short' }).toUpperCase().replace('.', '');
        return `${day} ${month}`;
    };

    // ==========================================================================
    // 2. FUNÇÕES DE RENDERIZAÇÃO (UI)
    // ==========================================================================

    // Atualiza o saldo na tela inicial
    function updateBalanceUI() {
        const balanceDisplays = document.querySelectorAll('.balance-display');
        if (!balanceDisplays.length) return; // Proteção contra quebra

        balanceDisplays.forEach(el => {
            el.textContent = currencyFormatter.format(appState.user.balance);
        });
    }

    // Renderiza a lista do extrato/carteira
    function renderTransactions() {
        const listContainer = document.getElementById('transaction-list');
        if (!listContainer) return; // Proteção contra quebra

        listContainer.innerHTML = ''; // Limpa a lista atual

        // Ordena por data (mais recente primeiro)
        const sortedTransactions = [...appState.transactions].sort((a, b) => b.date - a.date);

        sortedTransactions.forEach(t => {
            const row = document.createElement('div');
            row.className = `transaction-item ${t.isIncome ? 'income' : 'expense'}`;
            
            // Adiciona sinal de + ou - dependendo do tipo da transação
            const prefix = t.isIncome ? '+' : '-';

            row.innerHTML = `
                <div class="trans-info">${dateFormatter(t.date)} | ${t.type}</div>
                <div class="trans-value">${prefix} ${currencyFormatter.format(t.value)}</div>
            `;
            listContainer.appendChild(row);
        });
    }

    // Inicializa a interface com os dados do Estado
    function initUI() {
        updateBalanceUI();
        renderTransactions();
    }

    // ==========================================================================
    // 3. SISTEMA DE NAVEGAÇÃO
    // ==========================================================================
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.view');
    const mainContent = document.getElementById('main-content');

    function navigateTo(targetId) {
        // Atualiza estilo dos botões da nav
        navItems.forEach(nav => {
            nav.classList.toggle('active', nav.getAttribute('data-target') === targetId);
        });

        // Alterna a exibição das views (seções)
        views.forEach(view => {
            view.classList.toggle('active', view.id === targetId);
        });

        // Reseta o scroll ao mudar de aba
        if (mainContent) mainContent.scrollTop = 0;
    }

    // Vincula clique aos itens do menu inferior
    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const targetId = item.getAttribute('data-target');
            if (targetId) navigateTo(targetId);
        });
    });

    // Expondo globalmente caso precise navegar por botões internos (ex: após avaliar)
    window.navigateTo = navigateTo;

    // ==========================================================================
    // 4. FUNCIONALIDADES INTERATIVAS
    // ==========================================================================

    // --- Lógica de Recarga ---
    function handleRecharge() {
        const input = window.prompt("Digite o valor da recarga (Ex: 20.00):");
        if (input === null) return; // Usuário cancelou

        const value = parseFloat(input.replace(',', '.')); // Aceita vírgula ou ponto

        if (!isNaN(value) && value > 0) {
            // Atualiza Estado
            appState.user.balance += value;
            appState.transactions.unshift({
                id: Date.now(),
                date: new Date(),
                type: 'Recarga - App',
                value: value,
                isIncome: true
            });

            // Atualiza UI
            updateBalanceUI();
            renderTransactions();
            
            alert(`Recarga de ${currencyFormatter.format(value)} realizada com sucesso!`);
        } else {
            alert("Valor inválido. Por favor, insira um número positivo.");
        }
    }

    const rechargeBtns = document.querySelectorAll('.btn-recharge');
    rechargeBtns.forEach(btn => btn.addEventListener('click', handleRecharge));

    // --- Lógica de Avaliação (Estrelas) ---
    const stars = document.querySelectorAll('.interactive-stars i');
    let currentRating = 0; // Estado local da avaliação

    stars.forEach(star => {
        star.addEventListener('click', function() {
            currentRating = parseInt(this.getAttribute('data-val'));
            
            // Pinta as estrelas dinamicamente
            stars.forEach(s => {
                const sVal = parseInt(s.getAttribute('data-val'));
                if (sVal <= currentRating) {
                    s.className = 'fa-solid fa-star active';
                } else {
                    s.className = 'fa-regular fa-star';
                }
            });
        });
    });

    // --- Envio da Avaliação ---
    const submitRatingBtn = document.getElementById('btn-submit-rating');
    const commentBox = document.querySelector('.comment-box');

    if (submitRatingBtn) {
        submitRatingBtn.addEventListener('click', () => {
            if (currentRating > 0) {
                alert("Avaliação enviada com sucesso! Obrigado pelo seu feedback.");
                
                // Reseta o formulário
                currentRating = 0;
                stars.forEach(s => s.className = 'fa-regular fa-star');
                if (commentBox) commentBox.value = "";
                
                // Retorna para a Home
                navigateTo('view-home');
            } else {
                alert("Por favor, selecione uma nota clicando nas estrelas antes de enviar.");
            }
        });
    }

    // --- Lógica do Modal Nutricional ---
    const nutritionModal = document.getElementById('nutrition-modal');
    const btnNutrition = document.getElementById('btn-nutrition');
    const closeBtn = document.querySelector('.close-modal');
    
    function toggleModal(show) {
        if (!nutritionModal) return;
        nutritionModal.classList.toggle('open', show);
    }

    if (btnNutrition) btnNutrition.addEventListener('click', () => toggleModal(true));
    if (closeBtn) closeBtn.addEventListener('click', () => toggleModal(false));
    
    // Fechar ao clicar fora do modal
    if (nutritionModal) {
        nutritionModal.addEventListener('click', (e) => {
            if (e.target === nutritionModal) toggleModal(false);
        });
    }

    // ==========================================================================
    // 5. BOOTSTRAP (Start)
    // ==========================================================================
    initUI();
});