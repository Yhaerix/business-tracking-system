<?php
/**
 * Global Layout Footer & Scripts
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);
?>
            </main> <!-- End page-content -->
        </div> <!-- End app-main -->
    </div> <!-- End app-container -->

    <!-- Core Centralized JavaScript API Helper -->
    <script src="<?= BASE_URL ?>/assets/js/api.js"></script>

    <!-- Mobile Drawer Toggle Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('appSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');

            if (menuToggle && sidebar && backdrop) {
                menuToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('open');
                    backdrop.classList.toggle('active');
                });

                backdrop.addEventListener('click', () => {
                    sidebar.classList.remove('open');
                    backdrop.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>

