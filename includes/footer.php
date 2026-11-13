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

    <!-- Internal Team Chat Slide-Out Drawer -->
    <?php include __DIR__ . '/chat_drawer.php'; ?>

    <!-- Core Centralized JavaScript API Helper & Chat Controller -->
    <script>window.BASE_URL = '<?= BASE_URL ?>';</script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
    if (typeof ApexCharts === 'undefined') {
        document.write('<script src="<?= BASE_URL ?>/assets/js/apexcharts.min.js"><\/script>');
    }
    </script>
    <script src="<?= BASE_URL ?>/assets/js/api.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/charts.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/chat.js?v=<?= time() ?>"></script>

    <!-- Mobile Drawer Toggle Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const backdrop = document.getElementById('sidebarBackdrop');
            if (backdrop) {
                backdrop.addEventListener('click', () => {
                    if (typeof window.closeSidebar === 'function') {
                        window.closeSidebar();
                    }
                });
            }
        });
    </script>
</body>
</html>

