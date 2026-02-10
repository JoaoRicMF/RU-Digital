document.addEventListener('DOMContentLoaded', () => {
    
    // --- 1. Lógica de Navegação (Tabs) ---
    const navItems = document.querySelectorAll('.nav-item');
    const views = document.querySelectorAll('.view');

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const targetId = item.getAttribute('data-target');

            // Atualiza estado visual da Nav
            navItems.forEach(nav => nav.classList.remove('active'));
            item.classList.add('active');

            // Alterna o Conteúdo
            views.forEach(view => {
                view.classList.remove('active');
                if(view.id === targetId) {
                    view.classList.add('active');
                }
            });
            
            // Scroll para o topo ao trocar de aba
            document.getElementById('main-content').scrollTop = 0;
        });
    });

    // Função global para navegação via botões internos (ex: "Ver cardápio completo")
    window.navigateTo = (viewId) => {
        // Encontra o botão da nav correspondente e dispara o clique
        const targetNav = document.querySelector(`.nav-item[data-target="${viewId}"]`);
        if(targetNav) targetNav.click();
    };


    // --- 2. Lógica de Avaliação (Estrelas Interativas) ---
    const criteriaGroups = document.querySelectorAll('.rating-criteria');

    criteriaGroups.forEach(group => {
        const stars = group.querySelectorAll('.interactive-stars i');
        
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const value = parseInt(this.getAttribute('data-val'));
                
                // Preencher estrelas até o valor clicado
                stars.forEach(s => {
                    const sVal = parseInt(s.getAttribute('data-val'));
                    
                    if (sVal <= value) {
                        s.classList.remove('fa-regular');
                        s.classList.add('fa-solid', 'active');
                    } else {
                        s.classList.remove('fa-solid', 'active');
                        s.classList.add('fa-regular');
                    }
                });
            });
        });
    });
});