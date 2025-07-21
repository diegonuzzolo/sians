// Script base per esempio: conferma logout, alert personalizzati, future ajax
document.addEventListener("DOMContentLoaded", function () {
    const logoutBtn = document.getElementById("logout-btn");
    if (logoutBtn) {
        logoutBtn.addEventListener("click", function (e) {
            if (!confirm("Sei sicuro di voler uscire?")) {
                e.preventDefault();
            }
        });
    }

    // Altri script qui...
});
