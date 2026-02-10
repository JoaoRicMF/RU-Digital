document.addEventListener('DOMContentLoaded', () => {
    
    // --- 1. ESTADO GLOBAL (Simulação de Backend) ---
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

    // Formatador de Moeda
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

    // --- 2. FUNÇÕES DE RENDERIZAÇÃO (UI) ---

    // Atualiza o saldo em todos os lugares da tela
    function updateBalanceUI() {
        const balanceElements = document.querySelectorAll('.balance-display'); // Certifique-se de adicionar essa classe no HTML
        // Caso não tenha adicionado a classe, buscamos pelo contexto (fallback)
        const displays = document.querySelectorAll('.balance-amount');
        
        displays.forEach(el => {
            el.textContent = currencyFormatter.format(appState.user.balance);
        });
    }

    // Renderiza a lista de transações dinamicamente
    function renderTransactions() {
        const listContainer = document.getElementById('transaction-list'); // Adicione este ID no HTML na div pai das transações
        
        // Se não achou o container (caso o usuário não tenha editado o HTML), tenta achar pela classe
        const fallbackContainer = document.querySelector('.transactions-card');
        const targetContainer = listContainer || fallbackContainer;

        if (!targetContainer) return;

        targetContainer.innerHTML = ''; // Limpa a lista atual

        // Ordena por data (mais recente primeiro)
        const sortedTransactions = [...appState.transactions].sort((a, b) => b.date - a.date);

        sortedTransactions.forEach(t => {
            const row = document.createElement('div');
            row.className = `transaction-item ${t.isIncome ? 'income' : 'expense'}`;
            
            row.innerHTML = `
                <div class="trans-info">${dateFormatter(t.date)} | ${t.type}</div>
                <div class="trans-value">${currencyFormatter.format(t.value)}</div>
            `;
            targetContainer.appendChild(row);
        });
    }

    // Inicializa a UI com os dados do estado
    function initUI() {
        updateBalanceUI();
        renderTransactions();
    }

    // --- 3. FUNCIONALIDADES INTERATIVAS ---

    // Lógica de Recarga
    function handleRecharge() {
        const input = window.prompt("Digite o valor da recarga (Ex: 20.00):");
        
        if (input === null) return; // Cancelou

        // Troca vírgula por ponto para validar
        const value = parseFloat(input.replace(',', '.'));

        if (!isNaN(value) && value > 0) {
            // Atualiza Estado
            appState.user.balance += value;
            
            appState.transactions.unshift({
                id: Date.now(),
                date: new Date(), // Data de hoje
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

    // Lógica de Avaliação
    function handleRatingSubmit() {
        // Verifica se há alguma estrela marcada como "solid" (active)
        const hasRating = document.querySelector('.interactive-stars i.fa-solid');
        const commentBox = document.querySelector('.comment-box');

        if (hasRating) {
            alert("Sucesso! Obrigado pelo seu feedback.");
            
            // Resetar formulário
            document.querySelectorAll('.interactive-stars i').forEach(star => {
                star.className = 'fa-regular fa-star'; // Reseta ícone
                star.classList.remove('active'); // Remove classe de controle
            });
            if(commentBox) commentBox.value = "";
            
            //Voltar para Home
            window.navigateTo('view-home');
        } else {
            alert("Por favor, selecione pelo menos uma estrela em algum critério para avaliar.");
        }
    }

    // Lógica do Modal Nutricional
    const nutritionModal = document.getElementById('nutrition-modal');
    
    function toggleModal(show) {
        if (!nutritionModal) return;
        if (show) {
            nutritionModal.classList.add('open');
        } else {
            nutritionModal.classList.remove('open');
        }
    }


    // --- 4. EVENT LISTENERS (Vínculos) ---

    // Botões de Recarga (seleciona todos pela classe nova ou fallback)
    const rechargeBtns = document.querySelectorAll('.btn-recharge, .balance-card .btn-primary');
    rechargeBtns.forEach(btn => {
        btn.onclick = handleRecharge; // Vincula função diretamente
    });

    // Botão de Avaliação
    const submitRatingBtn = document.getElementById('btn-submit-rating') || document.querySelector('#view-rating .btn-primary');
    if (submitRatingBtn) {
        submitRatingBtn.addEventListener('click', handleRatingSubmit);
    }

    // Botão Tabela Nutricional
    const nutriBtn = document.getElementById('btn-nutrition') || document.querySelector('#view-menu .btn-outline');
    if (nutriBtn) {
        nutriBtn.addEventListener('click', () => toggleModal(true));
    }

    // Fechar Modal (X)
    const closeBtn = document.querySelector('.close-modal');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => toggleModal(false));
    }
    // Fechar ao clicar fora
    if (nutritionModal) {
        nutritionModal.addEventListener('click', (e) => {
            if (e.target === nutritionModal) toggleModal(false);
        });
    }


    // --- 5. SISTEMA DE NAVEGAÇÃO E ESTRELAS ---
    
    // Navegação (Tabs)
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.view');

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const targetId = item.getAttribute('data-target');
            navItems.forEach(nav => nav.classList.remove('active'));
            item.classList.add('active');
            views.forEach(view => {
                view.classList.remove('active');
                if(view.id === targetId) view.classList.add('active');
            });
            document.getElementById('main-content').scrollTop = 0;
        });
    });

    window.navigateTo = (viewId) => {
        const targetNav = document.querySelector(`.nav-item[data-target="${viewId}"]`);
        if(targetNav) targetNav.click();
    };

    // Estrelas Interativas
    const criteriaGroups = document.querySelectorAll('.rating-criteria');
    criteriaGroups.forEach(group => {
        const stars = group.querySelectorAll('.interactive-stars i');
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const value = parseInt(this.getAttribute('data-val'));
                stars.forEach(s => {
                    const sVal = parseInt(s.getAttribute('data-val'));
                    if (sVal <= value) {
                        s.className = 'fa-solid fa-star active';
                    } else {
                        s.className = 'fa-regular fa-star';
                    }
                });
            });
        });
    });

    // INICIALIZAÇÃO
    initUI();
});