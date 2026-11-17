<?php
/**
 * Public Customer Storefront API Handler
 * Small Business Sales and Expense Tracking System (Prestige Apple Store Experience)
 *
 * Publicly Accessible Endpoints (No Admin Login Required):
 * - get_store_info      : Store branding, contact information, and announcement settings
 * - list_categories     : Apple product categories with product counts and icon keys
 * - list_products       : Catalog browsing with search, category, condition, sort, and pagination
 * - get_product         : Detailed single product payload with gallery, storage variants, color palette, and tech specs
 * - get_flash_deals     : Curated flash promotion deals with discount calculations and 18h countdown timer
 * - get_wallet_balance  : Customer Apple Coins balance with live DB wallet and recent ledger transactions
 * - topup_coins         : Top-up customer Apple Coins balance with atomic ledger record in wallet_transactions
 * - get_cart            : Retrieve full bag summary, items, free delivery threshold progress, and totals
 * - add_to_cart         : Add item with storage/color variants, validating active state and stock limits
 * - update_cart_qty     : Modify bag item quantity with real-time stock checks
 * - remove_from_cart    : Remove a specific item from visitor bag
 * - clear_cart          : Empty customer bag
 * - get_checkout_data   : Pre-fill checkout with cart, courier options, customer address, and wallet sufficiency audit
 * - place_order         : Atomic checkout transaction creating order, reserving stock, deducting coins or logging COD
 *
 * STRICT INVARIANT:
 * `cost_price` is an internal sensitive metric and is NEVER returned in public storefront payloads.
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Initialize public visitor session with secure cookie parameters
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7-day cart & coin persistence
        'path' => '/',
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/auth.php';

/**
 * Standardized unified JSON envelope
 */
function send_json(bool $success, string $message, $data = null, array $errors = [], int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Extract request payload (supports JSON payloads, POST, and GET)
 */
function get_request_data(): array
{
    $data = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        }
    } else {
        $data = $_POST;
    }
    return array_merge($_GET, $data);
}

/**
 * Helper to resolve dynamic category icons
 */
function get_category_icon_slug(string $name): string
{
    $lower = strtolower($name);
    if (str_contains($lower, 'iphone') || str_contains($lower, 'phone'))
        return 'iphone';
    if (str_contains($lower, 'mac') || str_contains($lower, 'laptop') || str_contains($lower, 'computer'))
        return 'mac';
    if (str_contains($lower, 'ipad') || str_contains($lower, 'tablet'))
        return 'ipad';
    if (str_contains($lower, 'watch'))
        return 'watch';
    if (str_contains($lower, 'airpod') || str_contains($lower, 'audio') || str_contains($lower, 'headphone'))
        return 'airpods';
    if (str_contains($lower, 'access'))
        return 'accessories';
    return 'device';
}

/**
 * Calculate comprehensive shopping bag summary from session state
 */
function get_cart_summary(): array
{
    $cart = $_SESSION['cart'] ?? [];
    if (!is_array($cart)) {
        $cart = [];
        $_SESSION['cart'] = [];
    }

    $currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';
    $subtotal = 0.0;
    $cartCount = 0;
    $items = [];

    foreach ($cart as $key => $item) {
        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
            continue;
        }
        $unitPrice = (float) ($item['unit_price'] ?? 0.0);
        $lineTotal = round($unitPrice * $qty, 2);

        $item['quantity'] = $qty;
        $item['unit_price'] = $unitPrice;
        $item['line_total'] = $lineTotal;
        $item['formatted_unit'] = $currency . number_format($unitPrice, 2);
        $item['formatted_line'] = $currency . number_format($lineTotal, 2);

        $subtotal += $lineTotal;
        $cartCount += $qty;
        $items[] = $item;
    }

    $freeThreshold = 5000.00;
    $deliveryFee = ($subtotal >= $freeThreshold || $cartCount === 0) ? 0.00 : 150.00;
    $freeDeliveryRemaining = max(0.00, round($freeThreshold - $subtotal, 2));
    $freeDeliveryProgressPercent = $subtotal >= $freeThreshold ? 100 : (int) min(100, round(($subtotal / $freeThreshold) * 100));
    $totalAmount = round($subtotal + $deliveryFee, 2);

    return [
        'items' => $items,
        'cart_count' => $cartCount,
        'subtotal' => $subtotal,
        'formatted_subtotal' => $currency . number_format($subtotal, 2),
        'delivery_fee' => $deliveryFee,
        'formatted_delivery_fee' => $deliveryFee === 0.00 ? 'FREE' : $currency . number_format($deliveryFee, 2),
        'free_delivery_threshold' => $freeThreshold,
        'formatted_threshold' => $currency . number_format($freeThreshold, 2),
        'free_delivery_remaining' => $freeDeliveryRemaining,
        'formatted_remaining' => $currency . number_format($freeDeliveryRemaining, 2),
        'free_delivery_progress_percent' => $freeDeliveryProgressPercent,
        'total_amount' => $totalAmount,
        'formatted_total' => $currency . number_format($totalAmount, 2),
        'currency_symbol' => $currency
    ];
}

/**
 * Resolve or initialize Customer Wallet in MySQL database
 */
function resolve_customer_wallet(PDO $db, int $customerId = 1): array
{
    // 1. Verify that customer exists in customers table
    $stmtCust = $db->prepare("SELECT id, name, email, phone, wallet_balance, status FROM customers WHERE id = :cid AND deleted_at IS NULL LIMIT 1");
    $stmtCust->execute([':cid' => $customerId]);
    $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);

    if (!$cust) {
        // Customer ID is invalid or was reset. Fallback to customer 1 if exists.
        $stmtC1 = $db->query("SELECT id, name, email, phone, wallet_balance, status FROM customers WHERE id = 1 AND deleted_at IS NULL LIMIT 1");
        $cust1 = $stmtC1->fetch(PDO::FETCH_ASSOC);
        if ($cust1) {
            $customerId = 1;
            $cust = $cust1;
            $_SESSION['customer_id'] = 1;
            $_SESSION['customer_name'] = $cust1['name'];
            $_SESSION['customer_email'] = $cust1['email'];
            $_SESSION['customer_phone'] = $cust1['phone'];
            $_SESSION['customer_coins'] = (float) $cust1['wallet_balance'];
        } else {
            // Re-seed VIP Customer 1 with official bcrypt hash and zero coin balance
            $initialCoins = 0.00;
            $vipPassHash = '$2y$12$NSmpFphyjz4LiFXkwANcse3jgJKjfNrlse0dgWJQUT0hRmTag1dR.'; // Password123!
            $stmtMakeCust = $db->prepare("
                INSERT INTO customers (id, name, email, phone, password_hash, wallet_balance, status, created_at)
                VALUES (1, 'Prestige VIP Shopper', 'vip@prestigeapple.ph', '+63 917 800 2775', :pass, 0.00, 'active', NOW())
                ON DUPLICATE KEY UPDATE wallet_balance = VALUES(wallet_balance)
            ");
            $stmtMakeCust->execute([':pass' => $vipPassHash]);
            $customerId = 1;
            $cust = [
                'id' => 1,
                'name' => 'Prestige VIP Shopper',
                'email' => 'vip@prestigeapple.ph',
                'phone' => '+63 917 800 2775',
                'wallet_balance' => 0.00,
                'status' => 'active'
            ];
            $_SESSION['customer_id'] = 1;
            $_SESSION['customer_name'] = 'Prestige VIP Shopper';
            $_SESSION['customer_email'] = 'vip@prestigeapple.ph';
            $_SESSION['customer_phone'] = '+63 917 800 2775';
            $_SESSION['customer_coins'] = 0.00;
        }
    }

    // 2. Fetch or create customer wallet
    $stmt = $db->prepare("
        SELECT id, customer_id, coin_balance, is_frozen, created_at, updated_at 
        FROM customer_wallets 
        WHERE customer_id = :cid 
        LIMIT 1
    ");
    $stmt->execute([':cid' => $customerId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wallet) {
        $initialCoins = isset($cust['wallet_balance']) ? (float) $cust['wallet_balance'] : 0.00;
        $stmtIns = $db->prepare("
            INSERT INTO customer_wallets (customer_id, coin_balance, is_frozen, created_at)
            VALUES (:cid, :coins, 0, NOW())
        ");
        $stmtIns->execute([
            ':cid' => $customerId,
            ':coins' => $initialCoins
        ]);
        $walletId = (int) $db->lastInsertId();

        if ($initialCoins > 0) {
            $stmtTrans = $db->prepare("
                INSERT INTO wallet_transactions (
                    wallet_id, transaction_type, amount, balance_before, balance_after,
                    payment_gateway, reference_code, notes, created_at
                ) VALUES (
                    :wid, 'TOP_UP', :amt, 0.00, :after, 'SYSTEM', 'INIT-WELCOME-BONUS',
                    'Welcome Prestige Apple VIP Bonus Coins', NOW()
                )
            ");
            $stmtTrans->execute([
                ':wid' => $walletId,
                ':amt' => $initialCoins,
                ':after' => $initialCoins
            ]);
        }

        $wallet = [
            'id' => $walletId,
            'customer_id' => $customerId,
            'coin_balance' => $initialCoins,
            'is_frozen' => 0
        ];
    } else {
        // Sync session with authoritative DB wallet balance
        $_SESSION['customer_coins'] = (float) $wallet['coin_balance'];
    }

    return $wallet;
}

/**
 * Retrieve current Prestige Coins to PHP conversion rate
 * Default: 1.00 (1 Coin = ₱1.00 PHP)
 */
function get_coin_conversion_rate(PDO $db): float
{
    try {
        $stmt = $db->prepare("SELECT setting_value FROM store_settings WHERE setting_key = 'coins_conversion_rate' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false && is_numeric($val) && (float) $val > 0) {
            return (float) $val;
        }
    } catch (\Throwable $e) {
    }
    return 1.00;
}

/**
 * Generate dynamic storage variants and price deltas based on device family
 */
function get_product_storage_options(array $product, float $basePrice): array
{
    $categoryName = strtolower($product['category_name'] ?? '');
    $baseStorage = trim((string) ($product['storage'] ?? ''));
    $currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

    if (str_contains($categoryName, 'airpod') || str_contains($categoryName, 'access') || str_contains($categoryName, 'audio')) {
        return [
            [
                'label' => 'Standard',
                'storage' => 'Standard',
                'price' => $basePrice,
                'formatted_price' => $currency . number_format($basePrice, 2),
                'price_delta' => 0.0,
                'is_base' => true
            ]
        ];
    }

    if (str_contains($categoryName, 'watch')) {
        return [
            [
                'label' => 'GPS (41mm)',
                'storage' => '41mm GPS',
                'price' => $basePrice,
                'formatted_price' => $currency . number_format($basePrice, 2),
                'price_delta' => 0.0,
                'is_base' => true
            ],
            [
                'label' => 'GPS (45mm)',
                'storage' => '45mm GPS',
                'price' => $basePrice + 2500.0,
                'formatted_price' => $currency . number_format($basePrice + 2500.0, 2),
                'price_delta' => 2500.0,
                'is_base' => false
            ],
            [
                'label' => 'GPS + Cellular (45mm)',
                'storage' => '45mm Cellular',
                'price' => $basePrice + 6000.0,
                'formatted_price' => $currency . number_format($basePrice + 6000.0, 2),
                'price_delta' => 6000.0,
                'is_base' => false
            ]
        ];
    }

    if (str_contains($categoryName, 'mac')) {
        $tiers = [
            '256GB' => 0.0,
            '512GB' => 12000.0,
            '1TB' => 24000.0,
            '2TB' => 48000.0
        ];
    } elseif (str_contains($categoryName, 'ipad')) {
        $tiers = [
            '64GB' => -3000.0,
            '128GB' => 0.0,
            '256GB' => 6000.0,
            '512GB' => 18000.0
        ];
    } else {
        $tiers = [
            '128GB' => 0.0,
            '256GB' => 6000.0,
            '512GB' => 18000.0,
            '1TB' => 30000.0
        ];
    }

    $baseKey = '128GB';
    foreach ($tiers as $tierKey => $delta) {
        if (stripos($baseStorage, $tierKey) !== false) {
            $baseKey = $tierKey;
            break;
        }
    }
    $baseTierOffset = $tiers[$baseKey] ?? 0.0;

    $options = [];
    foreach ($tiers as $tierKey => $delta) {
        $relativeDelta = (float) ($delta - $baseTierOffset);
        $variantPrice = max(100.0, round($basePrice + $relativeDelta, 2));
        $options[] = [
            'label' => $tierKey,
            'storage' => $tierKey,
            'price' => $variantPrice,
            'formatted_price' => $currency . number_format($variantPrice, 2),
            'price_delta' => $relativeDelta,
            'is_base' => ($tierKey === $baseKey)
        ];
    }
    return $options;
}

/**
 * Generate device color options with matching Apple hex color palette
 */
function get_product_color_options(array $product): array
{
    $rawColor = trim((string) ($product['color'] ?? ''));
    if ($rawColor !== '') {
        $colorNames = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawColor)))));
    } else {
        $colorNames = ['Space Black'];
    }

    $paletteMap = [
        'Natural Titanium' => '#9e9a93',
        'Black Titanium' => '#2c2d30',
        'White Titanium' => '#f4f3f0',
        'Blue Titanium' => '#394653',
        'Desert Titanium' => '#c7b299',
        'Space Black' => '#1f2022',
        'Space Gray' => '#4d4f53',
        'Silver' => '#e3e4e6',
        'Starlight' => '#f0ece1',
        'Midnight' => '#1e232d',
        'Deep Purple' => '#4f4459',
        'Alpine Green' => '#505c54',
        'Sierra Blue' => '#9bb5ce',
        'Product(RED)' => '#e11d48',
        'Pink' => '#f9d5dc',
        'Teal' => '#73959c',
        'Ultramarine' => '#3c4f76',
        'Gold' => '#eed2b2',
        'Yellow' => '#e8ddaa',
        'Blue' => '#546e8c',
        'Green' => '#6b7a69',
        'White' => '#f8f9fa',
        'Black' => '#1f2022',
        'Standard' => '#1f2022'
    ];

    $colors = [];
    foreach ($colorNames as $idx => $cName) {
        $hex = '#1f2022';
        foreach ($paletteMap as $name => $h) {
            if (stripos($cName, $name) !== false) {
                $hex = $h;
                break;
            }
        }
        $colors[] = [
            'name' => $cName,
            'hex' => $hex,
            'is_default' => ($idx === 0)
        ];
    }
    return $colors;
}

/**
 * Generate technical specifications by Apple category
 */
function get_product_tech_specs(array $product): array
{
    $rawSpecs = $product['specs'] ?? '';
    $parsed = [];
    if (!empty($rawSpecs)) {
        $decoded = json_decode($rawSpecs, true);
        if (is_array($decoded)) {
            $parsed = $decoded;
        }
    }

    $name = $product['name'] ?? '';
    $categoryName = strtolower($product['category_name'] ?? '');

    $display = $parsed['Display'] ?? $parsed['display'] ?? '';
    if (empty($display)) {
        if (str_contains($name, 'iPhone 16 Pro Max')) {
            $display = '6.9-inch Super Retina XDR display with ProMotion (120Hz), Always-On, Dynamic Island, and 2,000 nits peak outdoor brightness.';
        } elseif (str_contains($name, 'iPhone 16 Pro')) {
            $display = '6.3-inch Super Retina XDR display with ProMotion (120Hz), Always-On, Dynamic Island, and latest-generation Ceramic Shield.';
        } elseif (str_contains($categoryName, 'iphone')) {
            $display = 'Super Retina XDR OLED display with True Tone, HDR display, and Dynamic Island.';
        } elseif (str_contains($categoryName, 'mac')) {
            $display = 'Liquid Retina XDR display with Extreme Dynamic Range, 1,000,000:1 contrast ratio, and ProMotion up to 120Hz.';
        } elseif (str_contains($categoryName, 'ipad')) {
            $display = 'Ultra Retina XDR display with tandem OLED technology, P3 wide color, and True Tone.';
        } elseif (str_contains($categoryName, 'watch')) {
            $display = 'Always-On Retina LTPO OLED display with up to 3,000 nits peak brightness and edge-to-edge sapphire crystal.';
        } else {
            $display = 'Precision acoustic cavity engineering with custom high-excursion Apple drivers.';
        }
    }

    $processor = $parsed['Processor'] ?? $parsed['processor'] ?? '';
    if (empty($processor)) {
        if (str_contains($name, '16 Pro')) {
            $processor = 'Apple A18 Pro chip with 6-core CPU, 6-core GPU, and 16-core Neural Engine.';
        } elseif (str_contains($name, '16')) {
            $processor = 'Apple A18 chip with 6-core CPU and 5-core GPU with hardware-accelerated ray tracing.';
        } elseif (str_contains($name, 'M4')) {
            $processor = 'Apple M4 chip with 10-core CPU, 10-core GPU, and hardware-accelerated mesh shading.';
        } elseif (str_contains($name, 'M3')) {
            $processor = 'Apple M3 Pro / Max chip with next-generation GPU architecture and dynamic caching.';
        } elseif (str_contains($categoryName, 'watch')) {
            $processor = 'Apple S9 / S10 SiP with 64-bit dual-core processor and 4-core Neural Engine.';
        } elseif (str_contains($categoryName, 'airpod')) {
            $processor = 'Apple H2 headphone chip delivering advanced computational audio and Pro Active Noise Cancellation.';
        } else {
            $processor = 'Apple Silicon high-efficiency processor with unified memory architecture.';
        }
    }

    $camera = $parsed['Camera System'] ?? $parsed['camera'] ?? '';
    if (empty($camera)) {
        if (str_contains($name, 'Pro')) {
            $camera = 'Pro camera system: 48MP Fusion main with sensor-shift OIS, 48MP Ultra Wide with macro, and 12MP 5x Telephoto optical zoom.';
        } elseif (str_contains($categoryName, 'iphone')) {
            $camera = 'Advanced dual-camera system: 48MP Main with 2x Telephoto quality and 12MP Ultra Wide with macro.';
        } elseif (str_contains($categoryName, 'mac')) {
            $camera = '1080p FaceTime HD camera with advanced image signal processor and Center Stage.';
        } elseif (str_contains($categoryName, 'ipad')) {
            $camera = '12MP Wide back camera with 4K video recording and 12MP landscape Ultra Wide front camera.';
        } else {
            $camera = 'Integrated sensor array with computational photography and TrueDepth face verification.';
        }
    }

    $battery = $parsed['Battery & Power'] ?? $parsed['battery'] ?? '';
    if (empty($battery)) {
        if (str_contains($categoryName, 'iphone')) {
            $battery = 'All-day battery life: Up to 29 hours video playback. Fast-charge capable up to 50% charge in 30 minutes with MagSafe.';
        } elseif (str_contains($categoryName, 'mac')) {
            $battery = 'Up to 22 hours battery life on a single charge. Fast-charging capable with USB-C Power Adapter and MagSafe 3.';
        } elseif (str_contains($categoryName, 'ipad')) {
            $battery = 'Built-in 38.99-watt-hour rechargeable lithium-polymer battery. Up to 10 hours continuous web browsing on Wi-Fi.';
        } elseif (str_contains($categoryName, 'watch')) {
            $battery = 'Up to 36 hours normal use, up to 72 hours in Low Power Mode. Fast charge from 0 to 80% in 45 minutes.';
        } elseif (str_contains($categoryName, 'airpod')) {
            $battery = 'Up to 6 hours listening time with ANC enabled. Up to 30 hours total listening time with MagSafe Charging Case.';
        } else {
            $battery = 'Extended rechargeable battery system with USB-C universal fast charging.';
        }
    }

    $box = $parsed['Box Contents'] ?? $parsed['box'] ?? '';
    if (empty($box)) {
        if (str_contains($categoryName, 'iphone')) {
            $box = $product['name'] . ', USB-C Charge Cable (1m), Documentation & Apple Stickers.';
        } elseif (str_contains($categoryName, 'mac')) {
            $box = $product['name'] . ', USB-C to MagSafe 3 Cable (2m), USB-C Power Adapter, Documentation.';
        } elseif (str_contains($categoryName, 'ipad')) {
            $box = $product['name'] . ', USB-C Charge Cable (1m), 20W USB-C Power Adapter, Quick Start Guide.';
        } elseif (str_contains($categoryName, 'watch')) {
            $box = $product['name'] . ' Case, Apple Watch Band, Magnetic Fast Charger to USB-C Cable (1m).';
        } elseif (str_contains($categoryName, 'airpod')) {
            $box = $product['name'] . ', MagSafe Charging Case (USB-C), Silicone ear tips (four sizes), Documentation.';
        } else {
            $box = $product['name'] . ', Quick Start Guide, Official Apple Warranty Documentation.';
        }
    }

    return [
        'Display' => $display,
        'Processor' => $processor,
        'Camera System' => $camera,
        'Battery & Power' => $battery,
        'Box Contents' => $box
    ];
}

$input = get_request_data();
$action = trim((string) ($input['action'] ?? ''));
$db = get_db();
sync_admin_storefront_session($db);
$currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

try {
    switch ($action) {
        // =====================================================================
        // 1. STORE BRANDING & INFO
        // =====================================================================
        case 'get_store_info':
            $stmt = $db->query("SELECT setting_key, setting_value FROM store_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $settings = [
                'store_name' => 'Prestige Apple Store',
                'contact_email' => 'support@prestigeapple.ph',
                'contact_phone' => '+63 917 800 2775',
                'currency_symbol' => $currency,
                'currency_code' => defined('APP_CURRENCY_CODE') ? APP_CURRENCY_CODE : 'PHP',
                'announcement' => 'Official Apple Authorised Reseller • Free Express Delivery on orders over ₱5,000 • 0% Installment available',
                'coins_name' => 'Prestige Coins',
                'coins_conversion_rate' => '1.00',
                'coins_enabled' => '1'
            ];

            foreach ($rows as $row) {
                if (isset($settings[$row['setting_key']]) && !empty($row['setting_value'])) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            }

            $conversionRate = get_coin_conversion_rate($db);
            $settings['coins_conversion_rate'] = $conversionRate;

            // Sync wallet balance: only resolve if customer is authenticated, otherwise default to 0.00
            $customerId = (int) ($_SESSION['customer_id'] ?? 0);
            if ($customerId > 0) {
                $wallet = resolve_customer_wallet($db, $customerId);
                $coinBalance = (float) $wallet['coin_balance'];
            } else {
                $coinBalance = (float) ($_SESSION['customer_coins'] ?? 0.00);
            }
            $_SESSION['customer_coins'] = $coinBalance;

            $settings['customer_coins'] = $coinBalance;
            $settings['currency_equivalent'] = round($coinBalance * $conversionRate, 2);
            $settings['formatted_coins'] = number_format($coinBalance, 2) . ' Coins';
            $settings['formatted_currency_equivalent'] = $currency . number_format($coinBalance * $conversionRate, 2);

            send_json(true, 'Store information loaded successfully', $settings);
            break;

        // =====================================================================
        // 2. CATEGORIES
        // =====================================================================
        case 'list_categories':
            $stmt = $db->query("
                SELECT c.id, c.name, c.description,
                       COUNT(p.id) AS product_count
                FROM categories c
                LEFT JOIN products p ON c.id = p.category_id 
                     AND p.deleted_at IS NULL 
                     AND p.status = 'active'
                GROUP BY c.id
                ORDER BY c.id ASC
            ");
            $rawCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $categories = [];
            foreach ($rawCategories as $cat) {
                $categories[] = [
                    'id' => (int) $cat['id'],
                    'name' => $cat['name'],
                    'description' => $cat['description'] ?? '',
                    'icon_key' => get_category_icon_slug($cat['name']),
                    'product_count' => (int) $cat['product_count']
                ];
            }

            send_json(true, 'Categories retrieved successfully', [
                'categories' => $categories
            ]);
            break;

        // =====================================================================
        // 3. PRODUCT CATALOG BROWSING (ZERO COST_PRICE LEAKAGE)
        // =====================================================================
        case 'list_products':
            $categoryId = !empty($input['category_id']) ? (int) $input['category_id'] : null;
            $search = trim((string) ($input['search'] ?? ''));
            $condition = trim((string) ($input['condition'] ?? 'all'));
            $sort = trim((string) ($input['sort'] ?? 'popular'));
            $page = max(1, (int) ($input['page'] ?? 1));
            $limit = min(48, max(1, (int) ($input['limit'] ?? 12)));
            $offset = ($page - 1) * $limit;

            $where = [
                'p.deleted_at IS NULL',
                "p.status = 'active'"
            ];
            $params = [];

            if ($categoryId !== null && $categoryId > 0) {
                $where[] = 'p.category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }

            if ($search !== '') {
                $terms = array_filter(preg_split('/\s+/', $search));
                $termClauses = [];
                $ti = 0;
                foreach ($terms as $term) {
                    $subClauses = [];
                    foreach (['name', 'description', 'specs', 'color', 'storage'] as $col) {
                        $paramKey = ":s_{$col}_{$ti}";
                        $subClauses[] = "p.{$col} LIKE {$paramKey}";
                        $params[$paramKey] = '%' . $term . '%';
                    }
                    $termClauses[] = '(' . implode(' OR ', $subClauses) . ')';
                    $ti++;
                }
                if (!empty($termClauses)) {
                    $where[] = '(' . implode(' AND ', $termClauses) . ')';
                }
            }

            if ($condition === 'brand_new') {
                $where[] = "p.condition_type LIKE '%Brand New%'";
            } elseif ($condition === 'refurbished') {
                $where[] = "p.condition_type LIKE '%Refurbished%'";
            } elseif ($condition === 'open_box') {
                $where[] = "p.condition_type LIKE '%Open Box%'";
            }

            $whereSql = implode(' AND ', $where);

            switch ($sort) {
                case 'price_asc':
                    $orderBy = 'p.selling_price ASC, p.id DESC';
                    break;
                case 'price_desc':
                    $orderBy = 'p.selling_price DESC, p.id DESC';
                    break;
                case 'newest':
                    $orderBy = 'p.created_at DESC, p.id DESC';
                    break;
                case 'popular':
                default:
                    $orderBy = 'db_units_sold DESC, p.id DESC';
                    break;
            }

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
            $stmtCount->execute($params);
            $totalRecords = (int) $stmtCount->fetchColumn();
            $totalPages = (int) ceil($totalRecords / $limit);

            // Invariant: cost_price is strictly omitted
            $sql = "
                SELECT p.id, p.category_id, c.name AS category_name,
                       p.sku, p.name, p.description, p.specs, p.color, p.storage,
                       p.condition_type, p.warranty,
                       p.selling_price, p.current_stock,
                       (SELECT image_path 
                        FROM product_images 
                        WHERE product_id = p.id 
                        ORDER BY is_primary DESC, display_order ASC, id ASC 
                        LIMIT 1) AS primary_image,
                       COALESCE((
                           SELECT SUM(oi.quantity)
                           FROM order_items oi
                           JOIN orders o ON oi.order_id = o.id
                           WHERE oi.product_id = p.id
                             AND o.deleted_at IS NULL
                             AND o.order_status != 'CANCELLED'
                       ), 0) AS db_units_sold,
                       p.created_at
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ";

            $stmtProducts = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmtProducts->bindValue($k, $v);
            }
            $stmtProducts->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmtProducts->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmtProducts->execute();

            $rows = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);
            $products = [];

            foreach ($rows as $p) {
                $id = (int) $p['id'];
                $sellingPrice = (float) $p['selling_price'];
                $currentStock = (int) $p['current_stock'];

                $dbUnitsSold = (int) $p['db_units_sold'];
                $unitsSold = $dbUnitsSold > 0 ? $dbUnitsSold : (25 + (($id * 19) % 85));
                $rating = min(5.0, round(4.7 + (($id * 7) % 4) * 0.1, 1));
                $reviewsCount = 18 + (($id * 37) % 160);

                $primaryImg = $p['primary_image'];
                if (empty($primaryImg)) {
                    $primaryImg = 'assets/images/placeholder-apple.svg';
                }

                $products[] = [
                    'id' => $id,
                    'category_id' => (int) $p['category_id'],
                    'category_name' => $p['category_name'],
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'description' => $p['description'] ?? '',
                    'specs' => $p['specs'] ?? '',
                    'color' => $p['color'] ?? 'Standard',
                    'storage' => $p['storage'] ?? '',
                    'condition_type' => $p['condition_type'] ?? 'Brand New Sealed',
                    'warranty' => $p['warranty'] ?? '1 Year Official Apple Warranty',
                    'selling_price' => $sellingPrice,
                    'formatted_price' => $currency . number_format($sellingPrice, 2),
                    'current_stock' => $currentStock,
                    'in_stock' => $currentStock > 0,
                    'primary_image' => $primaryImg,
                    'rating' => $rating,
                    'reviews_count' => $reviewsCount,
                    'units_sold' => $unitsSold,
                    'created_at' => $p['created_at']
                ];
            }

            send_json(true, 'Products retrieved successfully', [
                'products' => $products,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_records' => $totalRecords,
                    'total_pages' => $totalPages
                ]
            ]);
            break;

        // =====================================================================
        // 4. SINGLE PRODUCT DETAIL WITH COMPLETE GALLERY & VARIANTS
        // =====================================================================
        case 'get_product':
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['id' => 'Invalid ID'], 422);
            }

            // Invariant: no cost_price
            $stmt = $db->prepare("
                SELECT p.id, p.category_id, c.name AS category_name,
                       p.sku, p.name, p.description, p.specs, p.color, p.storage,
                       p.condition_type, p.warranty,
                       p.selling_price, p.current_stock, p.created_at
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE p.id = :id
                  AND p.deleted_at IS NULL
                  AND p.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                send_json(false, 'Product not found or unavailable', null, [], 404);
            }

            $stmtImages = $db->prepare("
                SELECT id, image_path, is_primary, display_order
                FROM product_images
                WHERE product_id = :product_id
                ORDER BY is_primary DESC, display_order ASC, id ASC
            ");
            $stmtImages->execute([':product_id' => $id]);
            $images = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

            $sellingPrice = (float) $p['selling_price'];
            $currentStock = (int) $p['current_stock'];
            $rating = min(5.0, round(4.7 + (($id * 7) % 4) * 0.1, 1));
            $reviewsCount = 18 + (($id * 37) % 160);

            $stmtRelated = $db->prepare("
                SELECT p.id, p.name, p.selling_price, p.storage, p.color,
                       (SELECT image_path 
                        FROM product_images 
                        WHERE product_id = p.id 
                        ORDER BY is_primary DESC, display_order ASC, id ASC 
                        LIMIT 1) AS primary_image
                FROM products p
                WHERE p.category_id = :category_id
                  AND p.id != :self_id
                  AND p.deleted_at IS NULL
                  AND p.status = 'active'
                ORDER BY p.id DESC
                LIMIT 4
            ");
            $stmtRelated->execute([
                ':category_id' => (int) $p['category_id'],
                ':self_id' => $id
            ]);
            $rawRelated = $stmtRelated->fetchAll(PDO::FETCH_ASSOC);

            $related = [];
            foreach ($rawRelated as $rel) {
                $relPrice = (float) $rel['selling_price'];
                $related[] = [
                    'id' => (int) $rel['id'],
                    'name' => $rel['name'],
                    'storage' => $rel['storage'] ?? '',
                    'color' => $rel['color'] ?? '',
                    'selling_price' => $relPrice,
                    'formatted_price' => $currency . number_format($relPrice, 2),
                    'primary_image' => !empty($rel['primary_image']) ? $rel['primary_image'] : 'assets/images/placeholder-apple.svg'
                ];
            }

            $storageOptions = get_product_storage_options($p, $sellingPrice);
            $colorOptions = get_product_color_options($p);
            $techSpecs = get_product_tech_specs($p);

            send_json(true, 'Product details retrieved successfully', [
                'product' => [
                    'id' => $id,
                    'category_id' => (int) $p['category_id'],
                    'category_name' => $p['category_name'],
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'description' => $p['description'] ?? '',
                    'specs' => $p['specs'] ?? '',
                    'color' => $p['color'] ?? 'Standard',
                    'storage' => $p['storage'] ?? '',
                    'condition_type' => $p['condition_type'] ?? 'Brand New Sealed',
                    'warranty' => $p['warranty'] ?? '1 Year Official Apple Warranty',
                    'selling_price' => $sellingPrice,
                    'formatted_price' => $currency . number_format($sellingPrice, 2),
                    'current_stock' => $currentStock,
                    'in_stock' => $currentStock > 0,
                    'rating' => $rating,
                    'reviews_count' => $reviewsCount,
                    'images' => $images,
                    'storage_options' => $storageOptions,
                    'color_options' => $colorOptions,
                    'tech_specs' => $techSpecs,
                    'related' => $related
                ]
            ]);
            break;

        // =====================================================================
        // 5. FLASH DEALS & PROMOTIONS (18-HOUR COUNTDOWN TIMER)
        // =====================================================================
        case 'get_flash_deals':
            $stmt = $db->query("
                SELECT p.id, p.name, p.selling_price, p.current_stock, p.storage, p.color, p.condition_type,
                       (SELECT image_path 
                        FROM product_images 
                        WHERE product_id = p.id 
                        ORDER BY is_primary DESC, display_order ASC, id ASC 
                        LIMIT 1) AS primary_image,
                       COALESCE((
                           SELECT SUM(oi.quantity)
                           FROM order_items oi
                           JOIN orders o ON oi.order_id = o.id
                           WHERE oi.product_id = p.id
                             AND o.deleted_at IS NULL
                             AND o.order_status != 'CANCELLED'
                       ), 0) AS db_units_sold
                FROM products p
                WHERE p.deleted_at IS NULL
                  AND p.status = 'active'
                  AND p.current_stock > 0
                ORDER BY p.selling_price DESC, p.id DESC
                LIMIT 6
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cycleDuration = 64800; // 18 hours
            $secondsElapsed = time() % $cycleDuration;
            $countdownSeconds = $cycleDuration - $secondsElapsed;

            $deals = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $dealPrice = (float) $row['selling_price'];
                $stockLeft = (int) $row['current_stock'];

                $discountPercent = 10 + (($id * 3) % 9);
                $originalPrice = round($dealPrice / (1 - ($discountPercent / 100)), 2);
                $savingsAmount = round($originalPrice - $dealPrice, 2);

                $dbSold = (int) $row['db_units_sold'];
                $unitsSold = $dbSold > 0 ? $dbSold : (35 + (($id * 17) % 70));
                $claimedPercent = min(92, max(45, (int) round(($unitsSold / ($unitsSold + $stockLeft)) * 100)));

                $primaryImg = $row['primary_image'];
                if (empty($primaryImg)) {
                    $primaryImg = 'assets/images/placeholder-apple.svg';
                }

                $deals[] = [
                    'id' => $id,
                    'name' => $row['name'],
                    'storage' => $row['storage'] ?? '',
                    'color' => $row['color'] ?? '',
                    'condition_type' => $row['condition_type'] ?? 'Brand New Sealed',
                    'primary_image' => $primaryImg,
                    'selling_price' => $dealPrice,
                    'formatted_price' => $currency . number_format($dealPrice, 2),
                    'original_price' => $originalPrice,
                    'formatted_orig' => $currency . number_format($originalPrice, 2),
                    'discount_percent' => $discountPercent,
                    'savings_amount' => $savingsAmount,
                    'savings_badge' => 'SAVE ' . $currency . number_format($savingsAmount, 0),
                    'stock_left' => $stockLeft,
                    'units_sold' => $unitsSold,
                    'claimed_percent' => $claimedPercent
                ];
            }

            send_json(true, 'Flash deals retrieved successfully', [
                'countdown_seconds' => $countdownSeconds,
                'cycle_label' => 'Flash Sale Ends In',
                'deals' => $deals
            ]);
            break;

        // =====================================================================
        // 6. CUSTOMER COINS WALLET BALANCE (PHASE 5 DB SYNC)
        // =====================================================================
        case 'get_wallet_balance':
            $rate = get_coin_conversion_rate($db);
            $customerId = (int) ($_SESSION['customer_id'] ?? 0);
            if ($customerId <= 0) {
                // Visitor guest starts with 0.00
                $_SESSION['customer_coins'] = 0.00;
                send_json(true, 'Customer wallet balance retrieved', [
                    'coins' => 0.00,
                    'formatted_coins' => '0.00 Coins',
                    'conversion_rate' => $rate,
                    'currency_equivalent' => 0.00,
                    'formatted_equivalent' => $currency . '0.00',
                    'currency_symbol' => $currency,
                    'recent_transactions' => []
                ]);
            }

            $wallet = resolve_customer_wallet($db, $customerId);
            $coinBalance = (float) $wallet['coin_balance'];

            // Sync session
            $_SESSION['customer_coins'] = $coinBalance;
            $currencyEq = round($coinBalance * $rate, 2);

            // Fetch recent wallet transactions
            $stmtTrans = $db->prepare("
                SELECT id, transaction_type, amount, balance_before, balance_after,
                       payment_gateway, reference_code, notes, created_at
                FROM wallet_transactions
                WHERE wallet_id = :wid
                ORDER BY created_at DESC, id DESC
                LIMIT 5
            ");
            $stmtTrans->execute([':wid' => $wallet['id']]);
            $recent = $stmtTrans->fetchAll(PDO::FETCH_ASSOC);

            send_json(true, 'Customer wallet balance retrieved', [
                'coins' => $coinBalance,
                'formatted_coins' => number_format($coinBalance, 2) . ' Coins',
                'conversion_rate' => $rate,
                'currency_equivalent' => $currencyEq,
                'formatted_equivalent' => $currency . number_format($currencyEq, 2),
                'currency_symbol' => $currency,
                'recent_transactions' => $recent
            ]);
            break;

        // =====================================================================
        // 7. TOP-UP CUSTOMER COINS (PHASE 5 ATOMIC DB LEDGER)
        // =====================================================================
        case 'topup_coins':
            $amountRaw = $input['amount'] ?? 0;
            $amount = (float) $amountRaw; // Amount in PHP being deposited
            $paymentMethod = strtoupper(trim((string) ($input['payment_method'] ?? 'GCASH')));
            $referenceNumber = trim((string) ($input['reference_number'] ?? ''));

            if (!in_array($paymentMethod, ['GCASH', 'MAYA', 'BANK_TRANSFER'], true)) {
                $paymentMethod = 'GCASH';
            }

            if ($amount <= 0) {
                send_json(false, 'Please specify a valid top-up amount greater than 0.', null, [
                    'amount' => 'Top-up amount must be greater than 0'
                ], 422);
            }

            if ($amount > 1000000) {
                send_json(false, 'Maximum top-up amount allowed per transaction is ₱1,000,000.', null, [
                    'amount' => 'Exceeds maximum top-up limit'
                ], 422);
            }

            if ($referenceNumber === '') {
                $referenceNumber = 'TOP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
            }

            $customerId = (int) ($_SESSION['customer_id'] ?? 1);
            $conversionRate = get_coin_conversion_rate($db);
            $coinsToAdd = round($amount / $conversionRate, 2);

            $db->beginTransaction();

            $stmtLock = $db->prepare("
                SELECT id, coin_balance, is_frozen 
                FROM customer_wallets 
                WHERE customer_id = :cid 
                FOR UPDATE
            ");
            $stmtLock->execute([':cid' => $customerId]);
            $wallet = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$wallet) {
                $stmtNewW = $db->prepare("
                    INSERT INTO customer_wallets (customer_id, coin_balance, is_frozen, created_at)
                    VALUES (:cid, 0.00, 0, NOW())
                ");
                $stmtNewW->execute([':cid' => $customerId]);
                $walletId = (int) $db->lastInsertId();
                $wallet = ['id' => $walletId, 'coin_balance' => 0.00, 'is_frozen' => 0];
            } else {
                $walletId = (int) $wallet['id'];
            }

            if ((int) $wallet['is_frozen'] === 1) {
                $db->rollBack();
                send_json(false, 'Your Prestige VIP wallet is currently suspended. Please contact customer care.', null, [], 403);
            }

            $balanceBefore = (float) $wallet['coin_balance'];
            $balanceAfter = round($balanceBefore + $coinsToAdd, 2);

            // Update wallet balance
            $stmtUpdateW = $db->prepare("
                UPDATE customer_wallets 
                SET coin_balance = :balance, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpdateW->execute([
                ':balance' => $balanceAfter,
                ':id' => $walletId
            ]);

            // Insert transaction ledger record
            $rateDisplay = number_format($conversionRate, 2);
            $notes = "Prestige Coins top-up of " . number_format($coinsToAdd, 2) . " coins (" . $currency . number_format($amount, 2) . " PHP at " . $currency . $rateDisplay . "/coin via {$paymentMethod})";
            $stmtTrans = $db->prepare("
                INSERT INTO wallet_transactions (
                    wallet_id, transaction_type, amount, balance_before, balance_after,
                    payment_gateway, reference_code, notes, created_at
                ) VALUES (
                    :wid, 'TOP_UP', :amt, :before, :after,
                    :gateway, :ref, :notes, NOW()
                )
            ");
            $stmtTrans->execute([
                ':wid' => $walletId,
                ':amt' => $coinsToAdd,
                ':before' => $balanceBefore,
                ':after' => $balanceAfter,
                ':gateway' => $paymentMethod,
                ':ref' => $referenceNumber,
                ':notes' => $notes
            ]);

            $db->commit();

            $_SESSION['customer_coins'] = $balanceAfter;

            send_json(true, 'Top-up successful! Apple Coins have been added to your wallet balance.', [
                'added_amount' => $amount,
                'paid_amount_php' => $amount,
                'formatted_paid' => $currency . number_format($amount, 2),
                'conversion_rate' => $conversionRate,
                'added_coins' => $coinsToAdd,
                'formatted_added' => number_format($coinsToAdd, 2) . ' Coins',
                'new_balance' => $balanceAfter,
                'formatted_balance' => number_format($balanceAfter, 2) . ' Coins',
                'currency_equivalent' => round($balanceAfter * $conversionRate, 2),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
                'currency_symbol' => $currency
            ]);
            break;

        // =====================================================================
        // 8. SHOPPING BAG LIFECYCLE
        // =====================================================================
        case 'get_cart':
            send_json(true, 'Shopping bag retrieved successfully', get_cart_summary());
            break;

        case 'add_to_cart':
            $productId = (int) ($input['product_id'] ?? 0);
            $quantity = max(1, (int) ($input['quantity'] ?? 1));
            $storage = trim((string) ($input['storage'] ?? ''));
            $color = trim((string) ($input['color'] ?? ''));

            if ($productId <= 0) {
                send_json(false, 'Valid Product ID is required', null, ['product_id' => 'Invalid ID'], 422);
            }

            // Query active product (strict cost_price exclusion)
            $stmt = $db->prepare("
                SELECT p.id, p.category_id, c.name AS category_name,
                       p.sku, p.name, p.storage, p.color, p.condition_type, p.warranty,
                       p.selling_price, p.current_stock,
                       (SELECT image_path 
                        FROM product_images 
                        WHERE product_id = p.id 
                        ORDER BY is_primary DESC, display_order ASC, id ASC 
                        LIMIT 1) AS primary_image
                FROM products p
                JOIN categories c ON p.category_id = c.id
                WHERE p.id = :id
                  AND p.deleted_at IS NULL
                  AND p.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':id' => $productId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                send_json(false, 'Product not found or currently unavailable.', null, [], 404);
            }

            $currentStock = (int) $p['current_stock'];
            if ($currentStock <= 0) {
                send_json(false, 'Sorry, this product is currently out of stock.', null, [
                    'current_stock' => 'Out of stock'
                ], 422);
            }

            $basePrice = (float) $p['selling_price'];
            $selectedStorage = $storage !== '' ? $storage : ($p['storage'] ?: 'Standard');
            $selectedColor = $color !== '' ? $color : ($p['color'] ?: 'Standard');

            $storageOptions = get_product_storage_options($p, $basePrice);
            $variantPrice = $basePrice;
            foreach ($storageOptions as $opt) {
                if (strcasecmp($opt['storage'], $selectedStorage) === 0) {
                    $variantPrice = (float) $opt['price'];
                    break;
                }
            }

            $clientItemKey = trim((string) ($input['item_key'] ?? ''));
            if ($clientItemKey !== '') {
                $itemKey = $clientItemKey;
            } else {
                $itemKey = 'item_' . $productId . '_' . substr(md5(strtolower($selectedStorage) . '_' . strtolower($selectedColor)), 0, 10);
            }

            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $existingQty = isset($_SESSION['cart'][$itemKey]) ? (int) $_SESSION['cart'][$itemKey]['quantity'] : 0;
            $newQty = $existingQty + $quantity;

            if ($newQty > $currentStock) {
                send_json(
                    false,
                    "Cannot add {$quantity} more unit(s). Only {$currentStock} unit(s) are available in stock (" . ($existingQty > 0 ? "{$existingQty} already in your bag" : "") . ").",
                    null,
                    ['quantity' => 'Exceeds available inventory'],
                    422
                );
            }

            $primaryImg = $p['primary_image'];
            if (empty($primaryImg)) {
                $primaryImg = 'assets/images/placeholder-apple.svg';
            }

            $_SESSION['cart'][$itemKey] = [
                'item_key' => $itemKey,
                'product_id' => $productId,
                'name' => $p['name'],
                'sku' => $p['sku'],
                'primary_image' => $primaryImg,
                'storage' => $selectedStorage,
                'color' => $selectedColor,
                'unit_price' => $variantPrice,
                'quantity' => $newQty,
                'max_stock' => $currentStock,
                'condition_type' => $p['condition_type'] ?? 'Brand New Sealed',
                'warranty' => $p['warranty'] ?? '1 Year Official Apple Warranty'
            ];

            send_json(true, 'Item added to your shopping bag!', get_cart_summary());
            break;

        case 'update_cart_qty':
            $itemKey = trim((string) ($input['item_key'] ?? ''));
            $quantity = (int) ($input['quantity'] ?? 0);
            $productId = (int) ($input['product_id'] ?? 0);
            $storage = trim((string) ($input['storage'] ?? ''));
            $color = trim((string) ($input['color'] ?? ''));

            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            // 1. Resolve key directly or by searching session items
            $resolvedKey = null;
            if ($itemKey !== '' && isset($_SESSION['cart'][$itemKey])) {
                $resolvedKey = $itemKey;
            } else {
                foreach ($_SESSION['cart'] as $k => $ci) {
                    if ($itemKey !== '' && (($ci['item_key'] ?? '') === $itemKey || (string) $k === $itemKey)) {
                        $resolvedKey = $k;
                        break;
                    }
                    if ($productId > 0 && (int) ($ci['product_id'] ?? 0) === $productId) {
                        $matchStorage = ($storage === '' || strcasecmp($ci['storage'] ?? '', $storage) === 0);
                        $matchColor = ($color === '' || strcasecmp($ci['color'] ?? '', $color) === 0);
                        if ($matchStorage && $matchColor) {
                            $resolvedKey = $k;
                            break;
                        }
                    }
                }
            }

            // 2. Self-heal from DB if item was present in client localStorage but session was lost
            if ($resolvedKey === null && $productId > 0 && $quantity > 0) {
                $stmtH = $db->prepare("
                    SELECT p.id, p.name, p.sku, p.selling_price, p.current_stock, p.status, p.deleted_at, p.condition_type, p.warranty,
                           (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, display_order ASC, id ASC LIMIT 1) AS primary_image
                    FROM products p
                    WHERE p.id = :id AND p.deleted_at IS NULL AND p.status = 'active'
                    LIMIT 1
                ");
                $stmtH->execute([':id' => $productId]);
                $pH = $stmtH->fetch(PDO::FETCH_ASSOC);
                if ($pH) {
                    $useKey = $itemKey !== '' ? $itemKey : ('item_' . $productId . '_' . substr(md5(strtolower($storage) . '_' . strtolower($color)), 0, 10));
                    $basePriceH = (float) $pH['selling_price'];
                    $storageOptionsH = get_product_storage_options($pH, $basePriceH);
                    $vPriceH = $basePriceH;
                    foreach ($storageOptionsH as $opt) {
                        if (strcasecmp($opt['storage'], $storage) === 0) {
                            $vPriceH = (float) $opt['price'];
                            break;
                        }
                    }
                    $primaryImgH = $pH['primary_image'] ?: 'assets/images/placeholder-apple.svg';
                    $_SESSION['cart'][$useKey] = [
                        'item_key' => $useKey,
                        'product_id' => $productId,
                        'name' => $pH['name'],
                        'sku' => $pH['sku'],
                        'primary_image' => $primaryImgH,
                        'storage' => $storage !== '' ? $storage : 'Standard',
                        'color' => $color !== '' ? $color : 'Standard',
                        'unit_price' => $vPriceH,
                        'quantity' => $quantity,
                        'max_stock' => (int) $pH['current_stock'],
                        'condition_type' => $pH['condition_type'] ?? 'Brand New Sealed',
                        'warranty' => $pH['warranty'] ?? '1 Year Official Apple Warranty'
                    ];
                    $resolvedKey = $useKey;
                }
            }

            if ($resolvedKey === null) {
                send_json(false, 'Item not found in shopping bag.', null, ['item_key' => 'Invalid key'], 404);
            }

            $itemKey = $resolvedKey;

            if ($quantity <= 0) {
                unset($_SESSION['cart'][$itemKey]);
                send_json(true, 'Item removed from your shopping bag.', get_cart_summary());
            }

            $item = $_SESSION['cart'][$itemKey];
            $productId = (int) ($item['product_id'] ?? 0);

            $stmt = $db->prepare("
                SELECT current_stock, status, deleted_at 
                FROM products 
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $productId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p || $p['deleted_at'] !== null || $p['status'] !== 'active') {
                unset($_SESSION['cart'][$itemKey]);
                send_json(false, 'This product is no longer available and has been removed from your bag.', get_cart_summary(), [], 422);
            }

            $availableStock = (int) $p['current_stock'];
            if ($quantity > $availableStock) {
                send_json(
                    false,
                    "Cannot set quantity to {$quantity}. Only {$availableStock} unit(s) are available in stock.",
                    null,
                    ['quantity' => 'Exceeds available stock'],
                    422
                );
            }

            $_SESSION['cart'][$itemKey]['quantity'] = $quantity;
            $_SESSION['cart'][$itemKey]['max_stock'] = $availableStock;

            send_json(true, 'Bag quantity updated successfully.', get_cart_summary());
            break;

        case 'remove_from_cart':
            $itemKey = trim((string) ($input['item_key'] ?? ''));
            if ($itemKey === '') {
                send_json(false, 'Item key is required.', null, ['item_key' => 'Required'], 422);
            }

            if (isset($_SESSION['cart'][$itemKey])) {
                unset($_SESSION['cart'][$itemKey]);
            } else if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
                foreach ($_SESSION['cart'] as $k => $ci) {
                    if (($ci['item_key'] ?? '') === $itemKey || (string) $k === $itemKey) {
                        unset($_SESSION['cart'][$k]);
                        break;
                    }
                }
            }

            send_json(true, 'Item removed from your shopping bag.', get_cart_summary());
            break;

        case 'sync_cart':
            // Synchronize client-side localStorage bag into server-side session
            $rawItems = $input['items'] ?? [];
            if (!is_array($rawItems)) {
                $rawItems = [];
            }

            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            if (!empty($rawItems)) {
                // Collect product IDs from incoming items
                $incomingPids = [];
                foreach ($rawItems as $it) {
                    $pid = (int) ($it['id'] ?? $it['product_id'] ?? 0);
                    if ($pid > 0) {
                        $incomingPids[] = $pid;
                    }
                }

                $productsMap = [];
                if (!empty($incomingPids)) {
                    $inPlaceholders = implode(',', array_fill(0, count($incomingPids), '?'));
                    $stmtP = $db->prepare("
                        SELECT p.id, p.name, p.sku, p.selling_price, p.current_stock, p.storage, p.color,
                               p.condition_type, p.warranty,
                               COALESCE((SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1), 'assets/images/placeholder-apple.svg') as primary_image
                        FROM products p
                        WHERE p.id IN ({$inPlaceholders}) AND p.status = 'active'
                    ");
                    $stmtP->execute($incomingPids);
                    while ($pRow = $stmtP->fetch(PDO::FETCH_ASSOC)) {
                        $productsMap[(int) $pRow['id']] = $pRow;
                    }
                }

                foreach ($rawItems as $it) {
                    $pid = (int) ($it['id'] ?? $it['product_id'] ?? 0);
                    if ($pid <= 0 || !isset($productsMap[$pid])) {
                        continue;
                    }

                    $p = $productsMap[$pid];
                    $currentStock = (int) $p['current_stock'];
                    if ($currentStock <= 0) {
                        continue;
                    }

                    $selectedStorage = trim((string) ($it['storage'] ?? $p['storage'] ?? 'Standard'));
                    $selectedColor = trim((string) ($it['color'] ?? $p['color'] ?? 'Standard'));
                    $quantity = max(1, min((int) ($it['quantity'] ?? 1), $currentStock));

                    $itemKey = trim((string) ($it['key'] ?? $it['item_key'] ?? ''));
                    if ($itemKey === '') {
                        $itemKey = 'item_' . $pid . '_' . substr(md5(strtolower($selectedStorage) . '_' . strtolower($selectedColor)), 0, 10);
                    }

                    $basePrice = (float) $p['selling_price'];
                    $storageOptions = get_product_storage_options($p, $basePrice);
                    $variantPrice = $basePrice;
                    foreach ($storageOptions as $opt) {
                        if (strcasecmp($opt['storage'], $selectedStorage) === 0) {
                            $variantPrice = (float) $opt['price'];
                            break;
                        }
                    }

                    $_SESSION['cart'][$itemKey] = [
                        'item_key' => $itemKey,
                        'product_id' => $pid,
                        'name' => $p['name'],
                        'sku' => $p['sku'],
                        'primary_image' => $p['primary_image'],
                        'storage' => $selectedStorage,
                        'color' => $selectedColor,
                        'unit_price' => $variantPrice,
                        'quantity' => $quantity,
                        'max_stock' => $currentStock,
                        'condition_type' => $p['condition_type'] ?? 'Brand New Sealed',
                        'warranty' => $p['warranty'] ?? '1 Year Official Apple Warranty'
                    ];
                }
            }

            send_json(true, 'Shopping bag synchronized with server.', get_cart_summary());
            break;

        case 'clear_cart':
            $_SESSION['cart'] = [];
            send_json(true, 'Your shopping bag has been cleared.', get_cart_summary());
            break;

        // =====================================================================
        // 9. CHECKOUT DATA & COURIER EVALUATION (PHASE 5)
        // =====================================================================
        case 'get_checkout_data':
            $cart = get_cart_summary();
            $subtotal = (float) $cart['subtotal'];

            // Courier options matrix
            $standardFree = ($subtotal >= 5000.00);
            $standardFee = $standardFree ? 0.00 : 150.00;
            $expressFee = 350.00;

            $couriers = [
                'STANDARD' => [
                    'code' => 'STANDARD',
                    'name' => 'Standard Insured Courier',
                    'eta' => '2-3 Business Days',
                    'fee' => $standardFee,
                    'formatted_fee' => $standardFree ? 'FREE' : $currency . number_format($standardFee, 2),
                    'is_free' => $standardFree,
                    'description' => 'Nationwide door-to-door insured delivery with live SMS tracking.'
                ],
                'EXPRESS' => [
                    'code' => 'EXPRESS',
                    'name' => 'Metro Manila Same-Day Express',
                    'eta' => 'Within 4-6 Hours',
                    'fee' => $expressFee,
                    'formatted_fee' => $currency . number_format($expressFee, 2),
                    'is_free' => false,
                    'description' => 'Dedicated VIP courier dispatch for Metro Manila deliveries.'
                ]
            ];

            // Resolve wallet and evaluation
            $conversionRate = get_coin_conversion_rate($db);
            $customerId = (int) ($_SESSION['customer_id'] ?? 1);
            $wallet = resolve_customer_wallet($db, $customerId);
            $customerId = (int) $wallet['customer_id'];
            $coinBalance = (float) $wallet['coin_balance'];
            $_SESSION['customer_coins'] = $coinBalance;

            // Balance evaluation for Standard Courier
            $requiredStandardPhp = round($subtotal + $standardFee, 2);
            $requiredStandardCoins = round($requiredStandardPhp / $conversionRate, 2);
            $purchasingPowerPhp = round($coinBalance * $conversionRate, 2);
            $isSufficient = ($coinBalance >= $requiredStandardCoins);
            $shortageCoins = max(0.00, round($requiredStandardCoins - $coinBalance, 2));
            $shortagePhp = max(0.00, round($shortageCoins * $conversionRate, 2));

            // Customer profile with resilient fallback
            $stmtCust = $db->prepare("
                SELECT c.id, c.name, c.phone, c.email,
                       a.address_line1, a.address_line2, a.city, a.province_state, a.postal_code
                FROM customers c
                LEFT JOIN customer_addresses a ON c.id = a.customer_id AND a.is_default = 1
                WHERE c.id = :cid
                LIMIT 1
            ");
            $stmtCust->execute([':cid' => $customerId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);

            $customerProfile = [
                'customer_id' => $customerId,
                'name' => $custRow['name'] ?? ($_SESSION['customer_name'] ?? 'Prestige VIP Member'),
                'phone' => $custRow['phone'] ?? ($_SESSION['customer_phone'] ?? '09171234567'),
                'email' => $custRow['email'] ?? ($_SESSION['customer_email'] ?? 'vip@prestigeapple.ph'),
                'street_address' => $custRow['address_line1'] ?? 'Unit 12B, Prestige Tower, 1200 F. Ortigas Jr. Rd',
                'province' => $custRow['province_state'] ?? 'Metro Manila',
                'city' => $custRow['city'] ?? 'Pasig City',
                'barangay' => 'San Antonio',
                'landmark' => $custRow['address_line2'] ?? 'Across Emerald Avenue'
            ];

            send_json(true, 'Checkout data prepared successfully', [
                'cart' => $cart,
                'couriers' => $couriers,
                'wallet' => [
                    'coin_balance' => $coinBalance,
                    'formatted_balance' => number_format($coinBalance, 2) . ' Coins',
                    'conversion_rate' => $conversionRate,
                    'purchasing_power' => $purchasingPowerPhp,
                    'formatted_power' => $currency . number_format($purchasingPowerPhp, 2),
                    'is_sufficient' => $isSufficient,
                    'required_amount' => $requiredStandardPhp,
                    'formatted_required' => $currency . number_format($requiredStandardPhp, 2),
                    'required_coins' => $requiredStandardCoins,
                    'formatted_required_coins' => number_format($requiredStandardCoins, 2) . ' Coins',
                    'shortage_amount' => $shortagePhp,
                    'formatted_shortage' => $currency . number_format($shortagePhp, 2),
                    'shortage_coins' => $shortageCoins,
                    'currency_symbol' => $currency
                ],
                'customer' => $customerProfile
            ]);
            break;

        // =====================================================================
        // 10. ATOMIC ORDER CREATION (PHASE 5)
        // =====================================================================
        case 'place_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            // Customer Authentication Check (Guests must sign in to place an order)
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Customer authentication required. Please sign in or create an account to finalize your order.', [
                    'redirect' => 'login.php?redirect=checkout.php&reason=auth_required'
                ], ['auth' => 'Customer authentication required'], 401);
            }

            $cart = get_cart_summary();
            if (empty($cart['items']) || $cart['cart_count'] <= 0) {
                send_json(false, 'Your shopping bag is empty. Please add items before checking out.', null, [], 422);
            }

            // Input sanitization & validation
            $customerName = trim((string) ($input['customer_name'] ?? ''));
            $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
            $customerEmail = trim((string) ($input['customer_email'] ?? ''));
            $province = trim((string) ($input['province'] ?? ''));
            $city = trim((string) ($input['city'] ?? ''));
            $barangay = trim((string) ($input['barangay'] ?? ''));
            $streetAddress = trim((string) ($input['street_address'] ?? ''));
            $landmark = trim((string) ($input['landmark'] ?? ''));
            $orderNotes = trim((string) ($input['order_notes'] ?? ''));
            $courierCode = strtoupper(trim((string) ($input['courier_code'] ?? 'STANDARD')));
            $paymentMethod = strtoupper(trim((string) ($input['payment_method'] ?? 'PRESTIGE_COINS')));

            $errors = [];
            if ($customerName === '')
                $errors['customer_name'] = 'Customer name is required';
            if ($customerPhone === '')
                $errors['customer_phone'] = 'Mobile phone number is required';
            if ($province === '')
                $errors['province'] = 'Province is required';
            if ($city === '')
                $errors['city'] = 'City is required';
            if ($barangay === '')
                $errors['barangay'] = 'Barangay is required';
            if ($streetAddress === '')
                $errors['street_address'] = 'Street address is required';

            if (!in_array($paymentMethod, ['PRESTIGE_COINS', 'COD'], true)) {
                $errors['payment_method'] = 'Invalid payment method. Choose Prestige Coins or Cash on Delivery.';
            }

            if (!empty($errors)) {
                send_json(false, 'Please correct the highlighted form errors.', null, $errors, 422);
            }

            // Delivery fee and courier resolution
            $subtotal = (float) $cart['subtotal'];
            if ($courierCode === 'EXPRESS') {
                $deliveryFee = 350.00;
                $courierName = 'Metro Manila Same-Day Express';
            } else {
                $deliveryFee = ($subtotal >= 5000.00) ? 0.00 : 150.00;
                $courierName = 'Standard Insured Courier';
            }

            $grandTotal = round($subtotal + $deliveryFee, 2);
            $fullAddress = trim($streetAddress . ($landmark !== '' ? ' (' . $landmark . ')' : '') . ', Brgy. ' . $barangay . ', ' . $city . ', ' . $province);

            // Execute atomic transaction
            $db->beginTransaction();

            // 1. Re-verify real-time inventory with row-level locks
            $lockedProducts = [];
            $stmtProdLock = $db->prepare("
                SELECT id, name, sku, cost_price, selling_price, current_stock, status, deleted_at 
                FROM products 
                WHERE id = :id 
                FOR UPDATE
            ");

            foreach ($cart['items'] as $item) {
                $pid = (int) $item['product_id'];
                $reqQty = (int) $item['quantity'];

                $stmtProdLock->execute([':id' => $pid]);
                $pRow = $stmtProdLock->fetch(PDO::FETCH_ASSOC);

                if (!$pRow || $pRow['deleted_at'] !== null || $pRow['status'] !== 'active') {
                    $db->rollBack();
                    send_json(false, "Item '{$item['name']}' is no longer active in our store.", null, [], 422);
                }

                $availableStock = (int) $pRow['current_stock'];
                if ($reqQty > $availableStock) {
                    $db->rollBack();
                    send_json(
                        false,
                        "Insufficient stock for '{$pRow['name']}'. Only {$availableStock} unit(s) remaining.",
                        null,
                        ['stock' => "Requested {$reqQty}, available: {$availableStock}"],
                        422
                    );
                }

                $lockedProducts[$pid] = $pRow;
            }

            // 2. Customer and Wallet Verification
            $customerId = (int) ($_SESSION['customer_id'] ?? 0);
            $stmtCustFind = $db->prepare("SELECT id FROM customers WHERE id = :cid AND deleted_at IS NULL LIMIT 1");
            $stmtCustFind->execute([':cid' => $customerId]);
            if (!$stmtCustFind->fetchColumn()) {
                $stmtC1 = $db->query("SELECT id FROM customers WHERE id = 1 AND deleted_at IS NULL LIMIT 1");
                if ($stmtC1->fetchColumn()) {
                    $customerId = 1;
                    $_SESSION['customer_id'] = 1;
                } else {
                    $db->rollBack();
                    send_json(false, 'Customer account could not be verified. Please sign in again.', [
                        'redirect' => 'login.php?redirect=checkout.php&reason=auth_required'
                    ], ['auth' => 'Invalid customer session'], 401);
                }
            }

            // Sync latest delivery info on customer record
            $stmtUpCust = $db->prepare("
                UPDATE customers 
                SET name = :name, phone = :phone, email = COALESCE(:email, email), updated_at = NOW() 
                WHERE id = :cid
            ");
            $stmtUpCust->execute([
                ':name' => $customerName,
                ':phone' => $customerPhone,
                ':email' => $customerEmail !== '' ? $customerEmail : null,
                ':cid' => $customerId
            ]);

            // Save address
            $stmtAddr = $db->prepare("
                INSERT INTO customer_addresses (
                    customer_id, address_line1, address_line2, city, province_state, is_default, created_at
                ) VALUES (
                    :cid, :a1, :a2, :city, :prov, 1, NOW()
                )
            ");
            $stmtAddr->execute([
                ':cid' => $customerId,
                ':a1' => $streetAddress,
                ':a2' => $landmark !== '' ? $landmark : null,
                ':city' => $city,
                ':prov' => $province
            ]);

            // 3. Payment Processing
            $walletId = null;
            $orderPaymentStatus = 'PENDING_COLLECTION';

            if ($paymentMethod === 'PRESTIGE_COINS') {
                $conversionRate = get_coin_conversion_rate($db);
                $coinsRequired = round($grandTotal / $conversionRate, 2);

                $stmtWalletLock = $db->prepare("
                    SELECT id, coin_balance, is_frozen 
                    FROM customer_wallets 
                    WHERE customer_id = :cid 
                    FOR UPDATE
                ");
                $stmtWalletLock->execute([':cid' => $customerId]);
                $wallet = $stmtWalletLock->fetch(PDO::FETCH_ASSOC);

                if (!$wallet) {
                    $wallet = resolve_customer_wallet($db, $customerId);
                    $stmtWalletLock->execute([':cid' => $customerId]);
                    $wallet = $stmtWalletLock->fetch(PDO::FETCH_ASSOC);
                }

                $walletId = (int) $wallet['id'];
                $currentCoins = (float) $wallet['coin_balance'];

                if ($currentCoins < $coinsRequired) {
                    $shortageCoins = round($coinsRequired - $currentCoins, 2);
                    $shortagePhp = round($shortageCoins * $conversionRate, 2);
                    $db->rollBack();
                    send_json(
                        false,
                        "Insufficient Prestige Coins balance. You need " . number_format($shortageCoins, 2) . " more coins (" . $currency . number_format($shortagePhp, 2) . ") to complete this order.",
                        null,
                        [
                            'coins' => 'Insufficient balance',
                            'shortage' => $shortagePhp,
                            'shortage_coins' => $shortageCoins,
                            'shortage_php' => $shortagePhp
                        ],
                        422
                    );
                }

                $balanceBefore = $currentCoins;
                $balanceAfter = round($balanceBefore - $coinsRequired, 2);

                $stmtDeduct = $db->prepare("
                    UPDATE customer_wallets 
                    SET coin_balance = :after, updated_at = NOW() 
                    WHERE id = :wid
                ");
                $stmtDeduct->execute([
                    ':after' => $balanceAfter,
                    ':wid' => $walletId
                ]);

                $_SESSION['customer_coins'] = $balanceAfter;
                $orderPaymentStatus = 'COLLECTED';
            }

            // 4. Generate Order Numbers
            $orderNumber = 'PAS-2026-' . strtoupper(substr(uniqid(), -5));
            $trackingNumber = 'PAS-TRK-' . mt_rand(100000, 999999);

            // 5. Insert Order
            $stmtOrder = $db->prepare("
                INSERT INTO orders (
                    order_number, user_id, customer_id, customer_name, customer_phone,
                    delivery_address, delivery_notes, courier_name, tracking_number,
                    shipping_status, subtotal, discount_amount, delivery_fee, total_amount,
                    order_status, payment_method, payment_status, created_at, updated_at
                ) VALUES (
                    :order_number, 1, :customer_id, :customer_name, :customer_phone,
                    :delivery_address, :delivery_notes, :courier_name, :tracking_number,
                    'NOT_SHIPPED', :subtotal, 0.00, :delivery_fee, :total_amount,
                    'CONFIRMED', :payment_method, :payment_status, NOW(), NOW()
                )
            ");
            $stmtOrder->execute([
                ':order_number' => $orderNumber,
                ':customer_id' => $customerId,
                ':customer_name' => $customerName,
                ':customer_phone' => $customerPhone,
                ':delivery_address' => $fullAddress,
                ':delivery_notes' => $orderNotes !== '' ? $orderNotes : null,
                ':courier_name' => $courierName,
                ':tracking_number' => $trackingNumber,
                ':subtotal' => $subtotal,
                ':delivery_fee' => $deliveryFee,
                ':total_amount' => $grandTotal,
                ':payment_method' => $paymentMethod,
                ':payment_status' => $orderPaymentStatus
            ]);
            $orderId = (int) $db->lastInsertId();

            // 6. Record Ledger Entry if Paid by Coins
            if ($paymentMethod === 'PRESTIGE_COINS' && $walletId !== null) {
                $stmtTransPay = $db->prepare("
                    INSERT INTO wallet_transactions (
                        wallet_id, transaction_type, amount, balance_before, balance_after,
                        reference_order_id, payment_gateway, reference_code, notes, created_at
                    ) VALUES (
                        :wid, 'ORDER_PAYMENT', :amt, :before, :after,
                        :order_id, 'PRESTIGE_COINS', :ref, :notes, NOW()
                    )
                ");
                $stmtTransPay->execute([
                    ':wid' => $walletId,
                    ':amt' => $coinsRequired,
                    ':before' => $balanceBefore,
                    ':after' => $balanceAfter,
                    ':order_id' => $orderId,
                    ':ref' => 'PAY-' . $orderNumber,
                    ':notes' => "Prestige VIP payment of " . number_format($coinsRequired, 2) . " coins for Order {$orderNumber} (" . $currency . number_format($grandTotal, 2) . " PHP at " . $currency . number_format($conversionRate, 2) . "/coin)"
                ]);
            }

            // 7. Record COD tracking if Cash on Delivery
            if ($paymentMethod === 'COD') {
                $stmtCod = $db->prepare("
                    INSERT INTO cod_payments (order_id, amount_due, amount_collected, status, created_at)
                    VALUES (:order_id, :amount_due, 0.00, 'PENDING', NOW())
                ");
                $stmtCod->execute([
                    ':order_id' => $orderId,
                    ':amount_due' => $grandTotal
                ]);
            }

            // 8. Order Items, Inventory Stock Deduction, and Movement Ledger
            $stmtItem = $db->prepare("
                INSERT INTO order_items (
                    order_id, product_id, product_name, unit_cost, unit_price, quantity, line_total, created_at
                ) VALUES (
                    :order_id, :product_id, :product_name, :unit_cost, :unit_price, :quantity, :line_total, NOW()
                )
            ");

            $stmtStockDec = $db->prepare("
                UPDATE products 
                SET current_stock = current_stock - :qty, updated_at = NOW() 
                WHERE id = :id
            ");

            $stmtMove = $db->prepare("
                INSERT INTO inventory_movements (
                    product_id, user_id, movement_type, quantity, stock_before, stock_after,
                    reference_type, reference_id, notes, created_at
                ) VALUES (
                    :product_id, 1, 'SALE', :quantity, :stock_before, :stock_after,
                    'ONLINE_ORDER', :reference_id, :notes, NOW()
                )
            ");

            foreach ($cart['items'] as $item) {
                $pid = (int) $item['product_id'];
                $qty = (int) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $lineTotal = round($unitPrice * $qty, 2);

                $pData = $lockedProducts[$pid];
                $internalCost = (float) $pData['cost_price'];
                $stockBefore = (int) $pData['current_stock'];
                $stockAfter = $stockBefore - $qty;

                // Insert item line
                $stmtItem->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $pid,
                    ':product_name' => $item['name'] . ($item['storage'] ? ' (' . $item['storage'] . ')' : '') . ($item['color'] ? ' - ' . $item['color'] : ''),
                    ':unit_cost' => $internalCost, // Strictly internal bookkeeping
                    ':unit_price' => $unitPrice,
                    ':quantity' => $qty,
                    ':line_total' => $lineTotal
                ]);

                // Decrement inventory
                $stmtStockDec->execute([
                    ':qty' => $qty,
                    ':id' => $pid
                ]);

                // Record movement audit
                $stmtMove->execute([
                    ':product_id' => $pid,
                    ':quantity' => -$qty,
                    ':stock_before' => $stockBefore,
                    ':stock_after' => $stockAfter,
                    ':reference_id' => $orderId,
                    ':notes' => "Online Storefront Order {$orderNumber}"
                ]);
            }

            // 9. System Notification for Admin/Staff
            $stmtNotif = $db->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, reference_type, reference_id, is_read, created_at
                ) VALUES (
                    1, 'SYSTEM', :title, :message, 'orders', :ref_id, 0, NOW()
                )
            ");
            $stmtNotif->execute([
                ':title' => "New Order Placed: #{$orderNumber}",
                ':message' => "Order #{$orderNumber} placed for {$currency}" . number_format($grandTotal, 2) . " via {$paymentMethod}.",
                ':ref_id' => $orderId
            ]);

            // 10. System Audit Log
            audit_log('orders', 'ORDER_CREATE', $orderId, null, [
                'order_number' => $orderNumber,
                'customer_name' => $customerName,
                'total_amount' => $grandTotal,
                'payment_method' => $paymentMethod,
                'items_count' => count($cart['items'])
            ]);

            // 11. Initial Order Note
            $stmtNote = $db->prepare("
                INSERT INTO order_notes (order_id, user_id, note, created_at)
                VALUES (:order_id, 1, :note, NOW())
            ");
            $stmtNote->execute([
                ':order_id' => $orderId,
                ':note' => "Order placed via online storefront. Payment method: {$paymentMethod}. Courier: {$courierName}."
            ]);

            // Clear shopping bag
            $_SESSION['cart'] = [];
            $_SESSION['last_order'] = [
                'order_number' => $orderNumber,
                'tracking_number' => $trackingNumber,
                'total_amount' => $grandTotal,
                'payment_method' => $paymentMethod
            ];

            // Commit atomic transaction
            $db->commit();

            send_json(true, 'Your order has been placed successfully!', [
                'order_number' => $orderNumber,
                'tracking_number' => $trackingNumber,
                'total_amount' => $grandTotal,
                'formatted_total' => $currency . number_format($grandTotal, 2),
                'payment_method' => $paymentMethod,
                'payment_status' => ($paymentMethod === 'PRESTIGE_COINS' ? 'PAID' : 'PENDING_COLLECTION'),
                'redirect_url' => 'order-success.php?order=' . urlencode($orderNumber)
            ], [], 201);
            break;

        // =====================================================================
        // 11. PUBLIC CUSTOMER ORDER TRACKER (PHASE 6)
        // =====================================================================
        case 'track_order':
            $orderRef = trim((string) ($input['order_number'] ?? $input['tracking_code'] ?? $input['query'] ?? ''));

            if ($orderRef === '') {
                send_json(false, 'Please provide an Order Reference or Tracking Number.', null, [
                    'order_number' => 'Order reference or tracking code is required'
                ], 422);
            }

            $refId = ctype_digit($orderRef) ? (int) $orderRef : 0;

            if ($refId > 0) {
                $stmtOrder = $db->prepare("
                    SELECT o.id, o.order_number, o.customer_name, o.customer_phone,
                           o.delivery_address, o.delivery_notes, o.courier_name, o.tracking_number,
                           o.shipping_status, o.subtotal, o.delivery_fee, o.total_amount,
                           o.order_status, o.payment_method, o.payment_status,
                           o.created_at, o.dispatched_at, o.delivered_at, o.cancelled_at, o.cancellation_reason
                    FROM orders o
                    WHERE (o.order_number = :ref OR o.tracking_number = :ref_trk OR o.id = :ref_id)
                      AND o.deleted_at IS NULL
                    LIMIT 1
                ");
                $stmtOrder->execute([
                    ':ref' => $orderRef,
                    ':ref_trk' => $orderRef,
                    ':ref_id' => $refId
                ]);
            } else {
                $stmtOrder = $db->prepare("
                    SELECT o.id, o.order_number, o.customer_name, o.customer_phone,
                           o.delivery_address, o.delivery_notes, o.courier_name, o.tracking_number,
                           o.shipping_status, o.subtotal, o.delivery_fee, o.total_amount,
                           o.order_status, o.payment_method, o.payment_status,
                           o.created_at, o.dispatched_at, o.delivered_at, o.cancelled_at, o.cancellation_reason
                    FROM orders o
                    WHERE (o.order_number = :ref OR o.tracking_number = :ref_trk)
                      AND o.deleted_at IS NULL
                    LIMIT 1
                ");
                $stmtOrder->execute([
                    ':ref' => $orderRef,
                    ':ref_trk' => $orderRef
                ]);
            }
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                send_json(false, "No order found matching reference '{$orderRef}'. Please verify the order number and try again.", null, [
                    'order_number' => 'Order not found'
                ], 404);
            }

            $orderId = (int) $order['id'];

            // Fetch order items (Zero cost_price leakage invariant)
            $stmtItems = $db->prepare("
                SELECT oi.id, oi.product_id, oi.product_name, oi.unit_price, oi.quantity, oi.line_total,
                       p.storage, p.color, p.sku, p.condition_type, p.warranty,
                       (SELECT image_path FROM product_images WHERE product_id = oi.product_id ORDER BY is_primary DESC, display_order ASC, id ASC LIMIT 1) AS primary_image
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :order_id
                ORDER BY oi.id ASC
            ");
            $stmtItems->execute([':order_id' => $orderId]);
            $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rawItems as $it) {
                $img = $it['primary_image'];
                if (empty($img)) {
                    $img = 'assets/images/placeholder-apple.svg';
                }
                $uPrice = (float) $it['unit_price'];
                $lTotal = (float) $it['line_total'];
                $items[] = [
                    'product_id' => (int) $it['product_id'],
                    'product_name' => $it['product_name'],
                    'storage' => $it['storage'] ?: 'Standard',
                    'color' => $it['color'] ?: 'Standard',
                    'sku' => $it['sku'] ?: 'APPLE-DEVICE',
                    'condition_type' => $it['condition_type'] ?: 'Brand New Sealed',
                    'warranty' => $it['warranty'] ?: '1 Year Official Apple Warranty',
                    'primary_image' => $img,
                    'quantity' => (int) $it['quantity'],
                    'unit_price' => $uPrice,
                    'line_total' => $lTotal,
                    'formatted_unit_price' => $currency . number_format($uPrice, 2),
                    'formatted_line_total' => $currency . number_format($lTotal, 2)
                ];
            }

            // Milestone state resolution (1 to 5)
            $status = strtoupper((string) $order['order_status']);
            $shipStatus = strtoupper((string) ($order['shipping_status'] ?? 'NOT_SHIPPED'));
            $isCancelled = ($status === 'CANCELLED' || $status === 'RETURNED');
            $isDelivered = ($status === 'DELIVERED');

            $currentStep = 1;
            $statusBadgeText = 'Order Placed — Verified';

            if ($status === 'CONFIRMED') {
                $currentStep = 2;
                $statusBadgeText = 'Packed & Quality Checked';
            } elseif ($status === 'DISPATCHED') {
                if ($shipStatus === 'OUT_FOR_DELIVERY') {
                    $currentStep = 4;
                    $statusBadgeText = 'Out for Delivery — Rider En Route';
                } else {
                    $currentStep = 3;
                    $statusBadgeText = 'In Transit with Courier';
                }
            } elseif ($status === 'DELIVERED') {
                $currentStep = 5;
                $statusBadgeText = 'Delivered & Completed';
            } elseif ($isCancelled) {
                $currentStep = 1;
                $statusBadgeText = ($status === 'RETURNED') ? 'Returned to Sender' : 'Order Cancelled';
            }

            // Mask phone number for security (e.g. 0917****567)
            $rawPhone = (string) $order['customer_phone'];
            $maskedPhone = $rawPhone;
            if (strlen($rawPhone) >= 7) {
                $maskedPhone = substr($rawPhone, 0, 4) . '****' . substr($rawPhone, -3);
            }

            // Courier and tracking url
            $courierName = $order['courier_name'] ?: 'Standard Insured Courier (J&T Express)';
            $trackingNo = $order['tracking_number'] ?: 'PAS-TRK-' . mt_rand(100000, 999999);
            $trackingUrl = '#';
            if (stripos($courierName, 'J&T') !== false) {
                $trackingUrl = 'https://www.jtexpress.ph/index/query/gzquery.html?bills=' . urlencode($trackingNo);
            } elseif (stripos($courierName, 'LBC') !== false) {
                $trackingUrl = 'https://www.lbcexpress.com/track/?tracking_no=' . urlencode($trackingNo);
            } elseif (stripos($courierName, 'Flash') !== false) {
                $trackingUrl = 'https://www.flashexpress.ph/tracking/?se=' . urlencode($trackingNo);
            }

            // Payment specifics
            $paymentMethod = strtoupper((string) $order['payment_method']);
            $isCoins = ($paymentMethod === 'PRESTIGE_COINS');
            $totalAmount = (float) $order['total_amount'];
            $codAmountDue = $isCoins ? 0.00 : $totalAmount;

            $createdAtTs = strtotime($order['created_at']);
            $formattedCreated = date('M d, Y h:i A', $createdAtTs);
            $estimatedDelivery = date('M d', $createdAtTs + (2 * 86400)) . ' - ' . date('M d, Y', $createdAtTs + (4 * 86400));

            $dispatchedTime = !empty($order['dispatched_at']) ? date('M d, Y h:i A', strtotime($order['dispatched_at'])) : ($currentStep >= 3 ? 'In Progress' : 'Pending');
            $deliveredTime = !empty($order['delivered_at']) ? date('M d, Y h:i A', strtotime($order['delivered_at'])) : ($currentStep >= 5 ? 'Completed' : 'Pending');

            // 5 Milestone definitions
            $milestones = [
                [
                    'step' => 1,
                    'title' => 'Order Placed',
                    'description' => $isCoins ? 'Verified & Paid via Prestige Coins' : 'Order Placed (Cash on Delivery)',
                    'timestamp' => $formattedCreated,
                    'is_complete' => true,
                    'is_current' => ($currentStep === 1 && !$isCancelled)
                ],
                [
                    'step' => 2,
                    'title' => 'Packed & Quality Checked',
                    'description' => 'Apple serial verified and tamper-proof sealed',
                    'timestamp' => ($currentStep >= 2) ? date('M d, Y', $createdAtTs) . ' (Quality Checked)' : 'Pending',
                    'is_complete' => ($currentStep >= 2),
                    'is_current' => ($currentStep === 2 && !$isCancelled)
                ],
                [
                    'step' => 3,
                    'title' => 'Handed to Courier',
                    'description' => "Transferred to {$courierName} ({$trackingNo})",
                    'timestamp' => $dispatchedTime,
                    'is_complete' => ($currentStep >= 3),
                    'is_current' => ($currentStep === 3 && !$isCancelled)
                ],
                [
                    'step' => 4,
                    'title' => 'Out for Delivery',
                    'description' => 'Courier courier rider out for direct delivery',
                    'timestamp' => ($currentStep >= 4) ? 'Today' : 'Pending',
                    'is_complete' => ($currentStep >= 4),
                    'is_current' => ($currentStep === 4 && !$isCancelled)
                ],
                [
                    'step' => 5,
                    'title' => 'Delivered & Completed',
                    'description' => $isCoins ? 'Signed and received by customer' : 'Package received & COD cash collected',
                    'timestamp' => $deliveredTime,
                    'is_complete' => ($currentStep >= 5),
                    'is_current' => ($currentStep === 5 && !$isCancelled)
                ]
            ];

            $orderPayload = [
                'id' => $orderId,
                'order_number' => $order['order_number'],
                'order_status' => $status,
                'shipping_status' => $shipStatus,
                'status_badge_text' => $statusBadgeText,
                'current_step' => $currentStep,
                'is_cancelled' => $isCancelled,
                'is_delivered' => $isDelivered,
                'customer_name' => $order['customer_name'],
                'customer_phone_masked' => $maskedPhone,
                'delivery_address' => $order['delivery_address'],
                'delivery_notes' => $order['delivery_notes'] ?: 'Standard insured delivery',
                'courier_name' => $courierName,
                'tracking_number' => $trackingNo,
                'tracking_url' => $trackingUrl,
                'payment_method' => $paymentMethod,
                'payment_method_label' => $isCoins ? '🪙 Prestige Coins' : '💵 Cash on Delivery (COD)',
                'is_coins' => $isCoins,
                'is_coins_paid' => $isCoins,
                'is_cod' => !$isCoins,
                'payment_status' => $order['payment_status'],
                'payment_status_label' => $isCoins ? 'Paid in Full' : ($isDelivered ? 'Cash Collected' : 'To Collect Upon Delivery'),
                'cod_amount_to_collect' => $codAmountDue,
                'formatted_cod_to_collect' => $currency . number_format($codAmountDue, 2),
                'subtotal' => (float) $order['subtotal'],
                'delivery_fee' => (float) $order['delivery_fee'],
                'total_amount' => $totalAmount,
                'formatted_subtotal' => $currency . number_format((float) $order['subtotal'], 2),
                'formatted_delivery_fee' => (float) $order['delivery_fee'] > 0 ? $currency . number_format((float) $order['delivery_fee'], 2) : 'FREE',
                'formatted_total' => $currency . number_format($totalAmount, 2),
                'created_at' => $order['created_at'],
                'formatted_created_at' => $formattedCreated,
                'estimated_delivery' => $estimatedDelivery,
                'milestones' => $milestones,
                'items' => $items
            ];

            send_json(true, 'Order tracking details retrieved successfully', ['order' => $orderPayload]);
            break;

        // =====================================================================
        // 9. CUSTOMER AUTHENTICATION & PROFILE SUITE
        // =====================================================================
        case 'customer_register':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $name = trim((string) ($input['name'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $phone = trim((string) ($input['phone'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');

            $errors = [];
            if (strlen($name) < 2) {
                $errors['name'] = 'Full name must be at least 2 characters long';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'A valid email address is required';
            }
            if (strlen($phone) < 10) {
                $errors['phone'] = 'A valid mobile phone number is required';
            }
            if (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters long';
            }
            if ($confirmPassword !== '' && $password !== $confirmPassword) {
                $errors['confirm_password'] = 'Passwords do not match';
            }

            if (!empty($errors)) {
                send_json(false, 'Please correct the validation errors below.', null, $errors, 422);
            }

            // Check if email already registered
            $stmtCheck = $db->prepare("SELECT id FROM customers WHERE email = :email AND deleted_at IS NULL LIMIT 1");
            $stmtCheck->execute([':email' => $email]);
            if ($stmtCheck->fetchColumn()) {
                send_json(false, 'An account with this email address already exists. Please sign in.', null, [
                    'email' => 'Email address already in use'
                ], 422);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $initialBonus = 0.00; // Starting Prestige Coins balance is 0.00 for new customers

            // Generate 6-digit OTP for email verification on registration
            $otpCode = sprintf('%06d', random_int(100000, 999999));
            $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser';

            // Invalidate any older unconsumed registration OTPs for this email
            $stmtInv = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE email = :email AND user_type = 'customer' AND purpose = 'register' AND is_used = 0");
            $stmtInv->execute([':email' => $email]);

            $stmtOtp = $db->prepare("
                INSERT INTO auth_otps 
                    (user_type, user_id, email, otp_code_hash, otp_code_plain, purpose, expires_at, ip_address, user_agent)
                VALUES 
                    ('customer', 0, :email, :hash, :plain, 'register', :expires, :ip, :ua)
            ");
            $stmtOtp->execute([
                ':email' => $email,
                ':hash' => $otpHash,
                ':plain' => $otpCode,
                ':expires' => $expiresAt,
                ':ip' => $ipAddress,
                ':ua' => $userAgent
            ]);

            // Dispatch OTP to the registering email
            send_otp_email($email, $name, $otpCode, 10);

            // Store pending registration in visitor session
            $_SESSION['pending_customer_otp'] = [
                'purpose' => 'register',
                'customer_id' => 0,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => $passwordHash,
                'time' => time()
            ];

            send_json(true, 'A 6-digit verification code has been sent to ' . mask_email($email) . ' to verify your account.', [
                'require_otp' => true,
                'purpose' => 'register',
                'masked_email' => mask_email($email)
            ]);
            break;

        case 'customer_login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $identifier = trim((string) ($input['login_identifier'] ?? $input['email'] ?? $input['phone'] ?? ''));
            $password = (string) ($input['password'] ?? '');

            if ($identifier === '') {
                send_json(false, 'Please enter your email or mobile phone number.', null, [
                    'login_identifier' => 'Email or mobile phone is required'
                ], 422);
            }
            if ($password === '') {
                send_json(false, 'Please enter your password.', null, [
                    'password' => 'Password is required'
                ], 422);
            }

            $stmt = $db->prepare("
                SELECT id, name, email, phone, password_hash, wallet_balance, status
                FROM customers
                WHERE (email = :id OR phone = :id_phone) AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $identifier, ':id_phone' => $identifier]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cust || empty($cust['password_hash']) || !password_verify($password, $cust['password_hash'])) {
                send_json(false, 'Invalid email/phone or password. Please verify your credentials and try again.', null, [
                    'credentials' => 'Invalid email or password'
                ], 401);
            }

            if (($cust['status'] ?? 'active') !== 'active') {
                send_json(false, 'Your Prestige account is currently suspended. Please contact customer care.', null, [
                    'status' => 'Account inactive'
                ], 403);
            }

            $customerId = (int) $cust['id'];
            $custEmail = trim((string) ($cust['email'] ?? ''));

            // If customer has a registered email, enforce OTP authentication to that registered email
            if (!empty($custEmail)) {
                $otpCode = sprintf('%06d', random_int(100000, 999999));
                $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
                $expiresAt = date('Y-m-d H:i:s', time() + 600);
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser';

                // Invalidate older unconsumed login OTPs for this customer
                $stmtInv = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE user_id = :uid AND user_type = 'customer' AND purpose = 'login' AND is_used = 0");
                $stmtInv->execute([':uid' => $customerId]);

                $stmtOtp = $db->prepare("
                    INSERT INTO auth_otps 
                        (user_type, user_id, email, otp_code_hash, otp_code_plain, purpose, expires_at, ip_address, user_agent)
                    VALUES 
                        ('customer', :uid, :email, :hash, :plain, 'login', :expires, :ip, :ua)
                ");
                $stmtOtp->execute([
                    ':uid' => $customerId,
                    ':email' => $custEmail,
                    ':hash' => $otpHash,
                    ':plain' => $otpCode,
                    ':expires' => $expiresAt,
                    ':ip' => $ipAddress,
                    ':ua' => $userAgent
                ]);

                // Dispatch OTP directly to the registered email address
                send_otp_email($custEmail, $cust['name'], $otpCode, 10);

                // Set pending login state in session
                $_SESSION['pending_customer_otp'] = [
                    'purpose' => 'login',
                    'customer_id' => $customerId,
                    'name' => $cust['name'],
                    'email' => $custEmail,
                    'phone' => $cust['phone'],
                    'time' => time()
                ];

                send_json(true, 'A 6-digit verification code has been sent to your registered email (' . mask_email($custEmail) . ').', [
                    'require_otp' => true,
                    'purpose' => 'login',
                    'masked_email' => mask_email($custEmail)
                ]);
            }

            // Fallback for customer without email (direct sign in)
            $stmtLoginAt = $db->prepare("UPDATE customers SET last_login_at = NOW() WHERE id = :id");
            $stmtLoginAt->execute([':id' => $customerId]);

            $stmtW = $db->prepare("SELECT coin_balance FROM customer_wallets WHERE customer_id = :cid LIMIT 1");
            $stmtW->execute([':cid' => $customerId]);
            $wCoin = $stmtW->fetchColumn();

            $coins = ($wCoin !== false) ? (float) $wCoin : (float) ($cust['wallet_balance'] ?? 0.00);
            $conversionRate = get_coin_conversion_rate($db);

            $_SESSION['customer_id'] = $customerId;
            $_SESSION['customer_name'] = $cust['name'];
            $_SESSION['customer_email'] = $cust['email'];
            $_SESSION['customer_phone'] = $cust['phone'];
            $_SESSION['customer_coins'] = $coins;
            $_SESSION['active_store_identity'] = 'customer';
            $_SESSION['is_admin_store_account'] = false;

            send_json(true, 'Welcome back, ' . htmlspecialchars($cust['name'], ENT_QUOTES, 'UTF-8') . '!', [
                'customer' => [
                    'id' => $customerId,
                    'name' => $cust['name'],
                    'email' => $cust['email'],
                    'phone' => $cust['phone'],
                    'coins' => $coins,
                    'formatted_coins' => number_format($coins, 2) . ' Coins',
                    'conversion_rate' => $conversionRate,
                    'currency_equivalent' => round($coins * $conversionRate, 2)
                ]
            ]);
            break;

        // =====================================================================
        // CUSTOMER 6-DIGIT OTP VERIFICATION (LOGIN & REGISTRATION)
        // =====================================================================
        case 'verify_customer_otp':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $otpCode = trim((string) ($input['otp_code'] ?? ''));
            if (empty($otpCode) || !preg_match('/^[0-9]{6}$/', $otpCode)) {
                send_json(false, 'Please enter a valid 6-digit verification code.', null, [
                    'otp_code' => '6-digit numeric code required'
                ], 422);
            }

            $pending = $_SESSION['pending_customer_otp'] ?? null;
            if (!$pending || empty($pending['purpose'])) {
                send_json(false, 'Verification session expired. Please sign in or register again.', null, [
                    'session' => 'Expired'
                ], 401);
            }

            $purpose = $pending['purpose'];
            $email = $pending['email'];

            // Query active OTP
            $stmtOtp = $db->prepare("
                SELECT id, otp_code_hash, otp_code_plain, attempts, max_attempts, expires_at 
                FROM auth_otps 
                WHERE email = :email AND user_type = 'customer' AND purpose = :purpose AND is_used = 0 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmtOtp->execute([':email' => $email, ':purpose' => $purpose]);
            $otp = $stmtOtp->fetch(PDO::FETCH_ASSOC);

            if (!$otp) {
                send_json(false, 'No active verification code found. Please request a new code.', null, [], 401);
            }

            if (strtotime($otp['expires_at']) < time()) {
                $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otp['id']]);
                send_json(false, 'Verification code has expired. Please request a new code.', null, [], 401);
            }

            if ((int) $otp['attempts'] >= (int) $otp['max_attempts']) {
                $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otp['id']]);
                send_json(false, 'Too many failed attempts. This code has been invalidated. Please request a new code.', null, [], 401);
            }

            // Check match
            $matched = password_verify($otpCode, $otp['otp_code_hash']) || ($otpCode === $otp['otp_code_plain']);

            if (!$matched) {
                $newAtt = (int) $otp['attempts'] + 1;
                $db->prepare("UPDATE auth_otps SET attempts = :att WHERE id = :id")->execute([':att' => $newAtt, ':id' => $otp['id']]);
                $rem = max(0, (int) $otp['max_attempts'] - $newAtt);
                send_json(false, 'Invalid verification code.' . ($rem > 0 ? " ({$rem} attempt(s) remaining)" : ''), null, [], 401);
            }

            // Code valid: Consume OTP
            $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id")->execute([':id' => $otp['id']]);

            $conversionRate = get_coin_conversion_rate($db);

            // =========================
            // SUB-BRANCH: LOGIN OTP
            // =========================
            if ($purpose === 'login') {
                $customerId = (int) $pending['customer_id'];
                $db->prepare("UPDATE customers SET last_login_at = NOW() WHERE id = :id")->execute([':id' => $customerId]);

                $stmtCust = $db->prepare("SELECT id, name, email, phone, wallet_balance, status FROM customers WHERE id = :id LIMIT 1");
                $stmtCust->execute([':id' => $customerId]);
                $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);

                $wallet = resolve_customer_wallet($db, $customerId);
                $coins = (float) $wallet['coin_balance'];

                $_SESSION['customer_id'] = $customerId;
                $_SESSION['customer_name'] = $cust['name'];
                $_SESSION['customer_email'] = $cust['email'];
                $_SESSION['customer_phone'] = $cust['phone'];
                $_SESSION['customer_coins'] = $coins;
                $_SESSION['customer_just_logged_in'] = true;
                $_SESSION['active_store_identity'] = 'customer';
                $_SESSION['is_admin_store_account'] = false;

                unset($_SESSION['pending_customer_otp']);

                // Send Welcome / Sign-in Alert Email to registered email
                send_login_welcome_email($cust['email'], $cust['name'], 'Prestige Customer', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser'
                ]);

                send_json(true, 'Welcome back, ' . htmlspecialchars($cust['name'], ENT_QUOTES, 'UTF-8') . '!', [
                    'customer' => [
                        'id' => $customerId,
                        'name' => $cust['name'],
                        'email' => $cust['email'],
                        'phone' => $cust['phone'],
                        'coins' => $coins,
                        'formatted_coins' => number_format($coins, 2) . ' Coins',
                        'conversion_rate' => $conversionRate,
                        'currency_equivalent' => round($coins * $conversionRate, 2)
                    ]
                ]);
            }

            // =========================
            // SUB-BRANCH: REGISTER OTP
            // =========================
            if ($purpose === 'register') {
                $name = $pending['name'];
                $phone = $pending['phone'];
                $passwordHash = $pending['password_hash'];
                $initialBonus = 0.00;

                $db->beginTransaction();
                try {
                    $stmtPhone = $db->prepare("SELECT id FROM customers WHERE phone = :phone AND deleted_at IS NULL LIMIT 1");
                    $stmtPhone->execute([':phone' => $phone]);
                    $existingId = $stmtPhone->fetchColumn();

                    if ($existingId) {
                        $customerId = (int) $existingId;
                        $stmtUp = $db->prepare("
                            UPDATE customers 
                            SET name = :name, email = :email, password_hash = :hash, 
                                email_verified_at = NOW(), last_login_at = NOW(), updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmtUp->execute([
                            ':name' => $name,
                            ':email' => $email,
                            ':hash' => $passwordHash,
                            ':id' => $customerId
                        ]);
                    } else {
                        $stmtIns = $db->prepare("
                            INSERT INTO customers (name, email, phone, password_hash, wallet_balance, email_verified_at, last_login_at, status, created_at, updated_at)
                            VALUES (:name, :email, :phone, :hash, :bal, NOW(), NOW(), 'active', NOW(), NOW())
                        ");
                        $stmtIns->execute([
                            ':name' => $name,
                            ':email' => $email,
                            ':phone' => $phone,
                            ':hash' => $passwordHash,
                            ':bal' => $initialBonus
                        ]);
                        $customerId = (int) $db->lastInsertId();
                    }

                    // Create/sync customer wallet
                    $stmtWIns = $db->prepare("
                        INSERT INTO customer_wallets (customer_id, coin_balance, is_frozen, created_at, updated_at)
                        VALUES (:cid, :bal, 0, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE updated_at = NOW()
                    ");
                    $stmtWIns->execute([':cid' => $customerId, ':bal' => $initialBonus]);

                    $db->commit();

                    $_SESSION['customer_id'] = $customerId;
                    $_SESSION['customer_name'] = $name;
                    $_SESSION['customer_email'] = $email;
                    $_SESSION['customer_phone'] = $phone;
                    $_SESSION['customer_coins'] = $initialBonus;
                    $_SESSION['customer_just_logged_in'] = true;
                    $_SESSION['active_store_identity'] = 'customer';
                    $_SESSION['is_admin_store_account'] = false;

                    unset($_SESSION['pending_customer_otp']);

                    // Send Welcome & Account Verified Email
                    send_login_welcome_email($email, $name, 'Verified Prestige Customer', [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser'
                    ]);

                    send_json(true, 'Welcome to Prestige Apple Store! Your account has been verified and created.', [
                        'customer' => [
                            'id' => $customerId,
                            'name' => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'coins' => $initialBonus,
                            'formatted_coins' => number_format($initialBonus, 2) . ' Coins',
                            'conversion_rate' => $conversionRate,
                            'currency_equivalent' => round($initialBonus * $conversionRate, 2)
                        ]
                    ], [], 201);
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
            }
            break;

        // =====================================================================
        // RESEND CUSTOMER OTP
        // =====================================================================
        case 'resend_customer_otp':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $pending = $_SESSION['pending_customer_otp'] ?? null;
            if (!$pending || empty($pending['email']) || empty($pending['purpose'])) {
                send_json(false, 'No active verification session. Please sign in or register again.', null, [], 401);
            }

            $cooldown = 60;
            $elapsed = time() - (int) ($pending['time'] ?? 0);
            if ($elapsed < $cooldown) {
                $rem = $cooldown - $elapsed;
                send_json(false, "Please wait {$rem} second(s) before requesting a new code.", null, [
                    'cooldown' => $rem
                ], 429);
            }

            $email = $pending['email'];
            $name = $pending['name'];
            $purpose = $pending['purpose'];
            $customerId = (int) ($pending['customer_id'] ?? 0);

            $newOtp = sprintf('%06d', random_int(100000, 999999));
            $otpHash = password_hash($newOtp, PASSWORD_BCRYPT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser';

            $stmtInv = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE email = :email AND user_type = 'customer' AND purpose = :purpose AND is_used = 0");
            $stmtInv->execute([':email' => $email, ':purpose' => $purpose]);

            $stmtOtp = $db->prepare("
                INSERT INTO auth_otps 
                    (user_type, user_id, email, otp_code_hash, otp_code_plain, purpose, expires_at, ip_address, user_agent)
                VALUES 
                    ('customer', :uid, :email, :hash, :plain, :purpose, :expires, :ip, :ua)
            ");
            $stmtOtp->execute([
                ':uid' => $customerId,
                ':email' => $email,
                ':hash' => $otpHash,
                ':plain' => $newOtp,
                ':purpose' => $purpose,
                ':expires' => $expiresAt,
                ':ip' => $ipAddress,
                ':ua' => $userAgent
            ]);

            send_otp_email($email, $name, $newOtp, 10);
            $_SESSION['pending_customer_otp']['time'] = time();

            send_json(true, 'A fresh 6-digit verification code has been dispatched to your email.', [
                'masked_email' => mask_email($email)
            ]);
            break;

        // =====================================================================
        // CUSTOMER FORGOT PASSWORD (REQUEST RESET OTP)
        // =====================================================================
        case 'customer_forgot_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $email = trim((string) ($input['email'] ?? $input['login_identifier'] ?? ''));
            if ($email === '') {
                send_json(false, 'Please enter your registered email address.', null, [
                    'email' => 'Email address is required'
                ], 422);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_json(false, 'Please enter a valid email address.', null, [
                    'email' => 'Invalid email address format'
                ], 422);
            }

            $stmtCust = $db->prepare("
                SELECT id, name, email, phone, status
                FROM customers
                WHERE email = :email AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmtCust->execute([':email' => $email]);
            $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);

            if (!$cust) {
                send_json(false, 'No Prestige account was found matching that email address.', null, [
                    'email' => 'Account not found'
                ], 404);
            }

            if (($cust['status'] ?? 'active') !== 'active') {
                send_json(false, 'This Prestige account is currently suspended. Please contact customer care.', null, [
                    'status' => 'Account inactive'
                ], 403);
            }

            $customerId = (int) $cust['id'];
            $custEmail = trim((string) ($cust['email'] ?? ''));

            if (empty($custEmail)) {
                send_json(false, 'No email address is linked to this account for password reset. Please contact support@prestigeapple.ph.', null, [], 422);
            }

            $otpCode = sprintf('%06d', random_int(100000, 999999));
            $otpHash = password_hash($otpCode, PASSWORD_BCRYPT);
            $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser';

            // Invalidate older unconsumed password_reset OTPs for this customer
            $stmtInv = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE user_id = :uid AND user_type = 'customer' AND purpose = 'password_reset' AND is_used = 0");
            $stmtInv->execute([':uid' => $customerId]);

            $stmtOtp = $db->prepare("
                INSERT INTO auth_otps 
                    (user_type, user_id, email, otp_code_hash, otp_code_plain, purpose, expires_at, ip_address, user_agent)
                VALUES 
                    ('customer', :uid, :email, :hash, :plain, 'password_reset', :expires, :ip, :ua)
            ");
            $stmtOtp->execute([
                ':uid' => $customerId,
                ':email' => $custEmail,
                ':hash' => $otpHash,
                ':plain' => $otpCode,
                ':expires' => $expiresAt,
                ':ip' => $ipAddress,
                ':ua' => $userAgent
            ]);

            send_otp_email($custEmail, $cust['name'], $otpCode, 15);

            $_SESSION['pending_password_reset'] = [
                'customer_id' => $customerId,
                'email' => $custEmail,
                'name' => $cust['name'],
                'time' => time()
            ];

            send_json(true, 'A 6-digit password reset code has been sent to ' . mask_email($custEmail) . '.', [
                'masked_email' => mask_email($custEmail),
                'email' => $custEmail
            ]);
            break;

        // =====================================================================
        // CUSTOMER RESET PASSWORD WITH OTP
        // =====================================================================
        case 'customer_reset_password_with_otp':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'Method not allowed. Use POST.', null, [], 405);
            }

            $otpCode = trim((string) ($input['otp_code'] ?? ''));
            $newPassword = (string) ($input['new_password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');

            $pending = $_SESSION['pending_password_reset'] ?? null;
            if (!$pending || empty($pending['customer_id']) || empty($pending['email'])) {
                send_json(false, 'Password reset session expired or invalid. Please request a new code.', null, [], 401);
            }

            if (strlen($otpCode) !== 6 || !ctype_digit($otpCode)) {
                send_json(false, 'Please provide a valid 6-digit verification code.', null, [
                    'otp_code' => 'Invalid code'
                ], 422);
            }

            if (strlen($newPassword) < 6) {
                send_json(false, 'New password must be at least 6 characters.', null, [
                    'new_password' => 'Password too short'
                ], 422);
            }

            if ($newPassword !== $confirmPassword) {
                send_json(false, 'Password confirmation does not match.', null, [
                    'confirm_password' => 'Passwords do not match'
                ], 422);
            }

            $customerId = (int) $pending['customer_id'];

            // Find matching active OTP
            $stmtFind = $db->prepare("
                SELECT id, otp_code_hash
                FROM auth_otps
                WHERE user_id = :uid
                  AND user_type = 'customer'
                  AND purpose = 'password_reset'
                  AND is_used = 0
                  AND expires_at > NOW()
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtFind->execute([':uid' => $customerId]);
            $otpRecord = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$otpRecord || !password_verify($otpCode, $otpRecord['otp_code_hash'])) {
                send_json(false, 'Invalid or expired verification code. Please check the code and try again.', null, [
                    'otp_code' => 'Invalid code'
                ], 422);
            }

            // Mark OTP used
            $stmtUsed = $db->prepare("UPDATE auth_otps SET is_used = 1 WHERE id = :id");
            $stmtUsed->execute([':id' => (int) $otpRecord['id']]);

            // Update customer password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtUp = $db->prepare("UPDATE customers SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
            $stmtUp->execute([':hash' => $newHash, ':id' => $customerId]);

            unset($_SESSION['pending_password_reset']);

            send_json(true, 'Your password has been successfully reset! You can now sign in with your new password.');
            break;

        case 'customer_logout':
            unset($_SESSION['customer_id']);
            unset($_SESSION['customer_name']);
            unset($_SESSION['customer_email']);
            unset($_SESSION['customer_phone']);
            unset($_SESSION['is_admin_store_account']);
            $_SESSION['active_store_identity'] = 'guest';
            // Note: $_SESSION['cart'] is preserved for guest shopping experience
            $_SESSION['customer_coins'] = 0.00;

            if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === false && $_SERVER['REQUEST_METHOD'] === 'GET') {
                header('Location: index.php');
                exit;
            }

            send_json(true, 'Signed out of your Prestige account successfully.', ['redirect' => 'index.php']);
            break;

        case 'customer_profile':
            $conversionRate = get_coin_conversion_rate($db);
            if (!empty($_SESSION['customer_id'])) {
                $cid = (int) $_SESSION['customer_id'];
                $stmt = $db->prepare("SELECT id, name, email, phone, wallet_balance, created_at FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([':id' => $cid]);
                $c = $stmt->fetch(PDO::FETCH_ASSOC);

                $coins = (float) ($_SESSION['customer_coins'] ?? ($c['wallet_balance'] ?? 0.00));

                send_json(true, 'Customer profile retrieved', [
                    'is_authenticated' => true,
                    'customer' => [
                        'id' => $cid,
                        'name' => $c ? $c['name'] : ($_SESSION['customer_name'] ?? 'Prestige Customer'),
                        'email' => $c ? $c['email'] : ($_SESSION['customer_email'] ?? ''),
                        'phone' => $c ? $c['phone'] : ($_SESSION['customer_phone'] ?? ''),
                        'coins' => $coins,
                        'formatted_coins' => number_format($coins, 2) . ' Coins',
                        'conversion_rate' => $conversionRate,
                        'currency_equivalent' => round($coins * $conversionRate, 2)
                    ]
                ]);
            } else {
                send_json(true, 'Guest browsing mode', [
                    'is_authenticated' => false,
                    'customer' => null
                ]);
            }
            break;

        // =====================================================================
        // 13. CUSTOMER ACCOUNT & PROFILE HUB
        // =====================================================================
        case 'customer_get_account':
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to view account details.', ['redirect' => 'login.php?redirect=account.php'], [], 401);
            }

            $cid = (int) $_SESSION['customer_id'];
            $stmt = $db->prepare("
                SELECT id, name, email, phone, wallet_balance, last_login_at, created_at 
                FROM customers 
                WHERE id = :cid AND deleted_at IS NULL 
                LIMIT 1
            ");
            $stmt->execute([':cid' => $cid]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                send_json(false, 'Customer account not found.', null, [], 404);
            }

            // Sync wallet
            $wallet = resolve_customer_wallet($db, $cid);
            $coins = (float) $wallet['coin_balance'];
            $_SESSION['customer_coins'] = $coins;
            $conversionRate = get_coin_conversion_rate($db);

            // Fetch default shipping address
            $addrStmt = $db->prepare("
                SELECT id, address_line1, address_line2, city, province_state, postal_code, is_default 
                FROM customer_addresses 
                WHERE customer_id = :cid AND is_default = 1 
                LIMIT 1
            ");
            $addrStmt->execute([':cid' => $cid]);
            $defaultAddress = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // Fetch summary stats
            $orderCountStmt = $db->prepare("
                SELECT COUNT(*) as total_orders, 
                       COALESCE(SUM(CASE WHEN order_status = 'DELIVERED' THEN total_amount ELSE 0 END), 0.00) as delivered_spend
                FROM orders 
                WHERE customer_id = :cid AND deleted_at IS NULL
            ");
            $orderCountStmt->execute([':cid' => $cid]);
            $stats = $orderCountStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_orders' => 0, 'delivered_spend' => 0.00];

            send_json(true, 'Account details loaded successfully.', [
                'customer' => [
                    'id' => (int) $customer['id'],
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'],
                    'wallet_balance' => $coins,
                    'formatted_coins' => number_format($coins, 2) . ' Coins',
                    'conversion_rate' => $conversionRate,
                    'currency_equivalent' => round($coins * $conversionRate, 2),
                    'last_login_at' => $customer['last_login_at'] ? date('M d, Y h:i A', strtotime($customer['last_login_at'])) : 'Recent',
                    'member_since' => date('M Y', strtotime($customer['created_at'])),
                    'total_orders' => (int) $stats['total_orders'],
                    'delivered_spend' => $currency . number_format((float) $stats['delivered_spend'], 2)
                ],
                'default_address' => $defaultAddress,
                'currency' => $currency
            ]);
            break;

        case 'customer_update_profile':
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to update profile.', ['redirect' => 'login.php'], [], 401);
            }

            $cid = (int) $_SESSION['customer_id'];
            $name = trim((string) ($input['name'] ?? ''));
            $phone = trim((string) ($input['phone'] ?? ''));

            $errors = [];
            if (strlen($name) < 2 || strlen($name) > 100) {
                $errors['name'] = 'Full name must be between 2 and 100 characters.';
            }
            if (strlen($phone) < 7 || strlen($phone) > 30) {
                $errors['phone'] = 'Please enter a valid phone number (e.g. 0917 123 4567).';
            }

            if (!empty($errors)) {
                send_json(false, 'Validation failed. Please correct the highlighted fields.', null, $errors, 422);
            }

            // Update customers table
            $stmtUp = $db->prepare("UPDATE customers SET name = :name, phone = :phone, updated_at = NOW() WHERE id = :cid");
            $stmtUp->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':cid' => $cid
            ]);

            $_SESSION['customer_name'] = $name;
            $_SESSION['customer_phone'] = $phone;

            // Handle default delivery address upsert if provided
            $line1 = trim((string) ($input['address_line1'] ?? ''));
            $city = trim((string) ($input['city'] ?? ''));
            $province = trim((string) ($input['province_state'] ?? ''));
            $postal = trim((string) ($input['postal_code'] ?? ''));
            $line2 = trim((string) ($input['address_line2'] ?? ''));

            if (!empty($line1) && !empty($city) && !empty($province)) {
                $checkAddr = $db->prepare("SELECT id FROM customer_addresses WHERE customer_id = :cid AND is_default = 1 LIMIT 1");
                $checkAddr->execute([':cid' => $cid]);
                $existingAddr = $checkAddr->fetch(PDO::FETCH_ASSOC);

                if ($existingAddr) {
                    $updateAddr = $db->prepare("
                        UPDATE customer_addresses 
                        SET address_line1 = :l1, address_line2 = :l2, city = :city, province_state = :prov, postal_code = :post, updated_at = NOW() 
                        WHERE id = :id
                    ");
                    $updateAddr->execute([
                        ':l1' => $line1,
                        ':l2' => $line2,
                        ':city' => $city,
                        ':prov' => $province,
                        ':post' => $postal,
                        ':id' => $existingAddr['id']
                    ]);
                } else {
                    $insertAddr = $db->prepare("
                        INSERT INTO customer_addresses (customer_id, address_line1, address_line2, city, province_state, postal_code, is_default, created_at)
                        VALUES (:cid, :l1, :l2, :city, :prov, :post, 1, NOW())
                    ");
                    $insertAddr->execute([
                        ':cid' => $cid,
                        ':l1' => $line1,
                        ':l2' => $line2,
                        ':city' => $city,
                        ':prov' => $province,
                        ':post' => $postal
                    ]);
                }
            }

            // Audit log
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, module, old_values, new_values)
                VALUES (1, 'CUSTOMER_PROFILE_UPDATE', 'store_customers', :old, :new)
            ")->execute([
                        ':old' => json_encode(['customer_id' => $cid]),
                        ':new' => json_encode(['name' => $name, 'phone' => $phone, 'city' => $city])
                    ]);

            send_json(true, 'Your profile details and delivery address were updated successfully.', [
                'name' => $name,
                'phone' => $phone
            ]);
            break;

        case 'customer_change_password':
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to change password.', ['redirect' => 'login.php'], [], 401);
            }

            $cid = (int) $_SESSION['customer_id'];
            $currentPass = (string) ($input['current_password'] ?? '');
            $newPass = (string) ($input['new_password'] ?? '');
            $confirmPass = (string) ($input['confirm_password'] ?? '');

            $errors = [];
            if (empty($currentPass)) {
                $errors['current_password'] = 'Please enter your current password.';
            }
            if (strlen($newPass) < 6) {
                $errors['new_password'] = 'New password must be at least 6 characters.';
            }
            if ($newPass !== $confirmPass) {
                $errors['confirm_password'] = 'New password and confirmation password do not match.';
            }

            if (!empty($errors)) {
                send_json(false, 'Password validation failed.', null, $errors, 422);
            }

            // Fetch stored password hash
            $stmt = $db->prepare("SELECT password_hash FROM customers WHERE id = :cid LIMIT 1");
            $stmt->execute([':cid' => $cid]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cust || empty($cust['password_hash']) || !password_verify($currentPass, $cust['password_hash'])) {
                send_json(false, 'The current password you entered is incorrect.', null, [
                    'current_password' => 'Incorrect current password'
                ], 401);
            }

            // Hash new password using bcrypt cost 12
            $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtUp = $db->prepare("UPDATE customers SET password_hash = :hash, updated_at = NOW() WHERE id = :cid");
            $stmtUp->execute([
                ':hash' => $newHash,
                ':cid' => $cid
            ]);

            // Audit log
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, module, old_values, new_values)
                VALUES (1, 'CUSTOMER_PASSWORD_CHANGE', 'store_customers', :old, :new)
            ")->execute([
                        ':old' => json_encode(['customer_id' => $cid]),
                        ':new' => json_encode(['status' => 'password_updated_successfully'])
                    ]);

            send_json(true, 'Your account password has been changed successfully.');
            break;

        case 'customer_list_orders':
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to view orders.', ['redirect' => 'login.php?redirect=account.php'], [], 401);
            }

            $cid = (int) $_SESSION['customer_id'];
            $statusFilter = strtolower(trim((string) ($input['status'] ?? 'all')));

            $sql = "
                SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.delivery_address,
                       o.courier_name, o.tracking_number, o.shipping_status, o.subtotal, o.discount_amount,
                       o.delivery_fee, o.total_amount, o.order_status, o.payment_method, o.payment_status,
                       o.dispatched_at, o.delivered_at, o.created_at
                FROM orders o
                WHERE o.customer_id = :cid AND o.deleted_at IS NULL
            ";

            if ($statusFilter === 'active') {
                $sql .= " AND o.order_status IN ('PENDING', 'CONFIRMED', 'DISPATCHED')";
            } elseif ($statusFilter === 'delivered') {
                $sql .= " AND o.order_status = 'DELIVERED'";
            } elseif ($statusFilter === 'cancelled') {
                $sql .= " AND o.order_status = 'CANCELLED'";
            }

            $sql .= " ORDER BY o.id DESC LIMIT 50";

            $stmt = $db->prepare($sql);
            $stmt->execute([':cid' => $cid]);
            $rawOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch items for each order
            $orders = [];
            foreach ($rawOrders as $ord) {
                $orderId = (int) $ord['id'];
                $itemStmt = $db->prepare("
                    SELECT oi.id, oi.product_id, oi.product_name, oi.unit_price, oi.quantity, oi.line_total,
                           COALESCE(p.sku, '') as sku, 
                           COALESCE(p.color, '') as color, 
                           COALESCE(p.storage, '') as storage,
                           (SELECT image_path FROM product_images WHERE product_id = oi.product_id ORDER BY is_primary DESC, id ASC LIMIT 1) as image_path
                    FROM order_items oi
                    LEFT JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = :oid
                    ORDER BY oi.id ASC
                ");
                $itemStmt->execute([':oid' => $orderId]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                $formattedItems = [];
                foreach ($items as $item) {
                    $specParts = array_filter([$item['storage'] ?? '', $item['color'] ?? '']);
                    $variantSpecs = implode(' • ', $specParts);

                    $formattedItems[] = [
                        'product_id' => (int) $item['product_id'],
                        'product_name' => $item['product_name'],
                        'sku' => $item['sku'],
                        'unit_price' => (float) $item['unit_price'],
                        'formatted_unit' => $currency . number_format((float) $item['unit_price'], 2),
                        'quantity' => (int) $item['quantity'],
                        'line_total' => (float) $item['line_total'],
                        'formatted_line' => $currency . number_format((float) $item['line_total'], 2),
                        'variant_specs' => $variantSpecs,
                        'image_url' => !empty($item['image_path']) ? '../' . $item['image_path'] : null
                    ];
                }

                $isCoins = (strtoupper($ord['payment_method']) === 'PRESTIGE_COINS');
                $isCod = (strtoupper($ord['payment_method']) === 'COD');

                $orders[] = [
                    'id' => $orderId,
                    'order_number' => $ord['order_number'],
                    'order_status' => $ord['order_status'],
                    'shipping_status' => $ord['shipping_status'],
                    'payment_method' => $ord['payment_method'],
                    'is_coins_paid' => $isCoins,
                    'is_cod' => $isCod,
                    'total_amount' => (float) $ord['total_amount'],
                    'formatted_total' => $currency . number_format((float) $ord['total_amount'], 2),
                    'delivery_fee' => (float) $ord['delivery_fee'],
                    'courier_name' => $ord['courier_name'] ?: 'Prestige Insured Express',
                    'tracking_number' => $ord['tracking_number'] ?: '',
                    'item_count' => count($formattedItems),
                    'first_product' => $formattedItems[0] ?? null,
                    'items' => $formattedItems,
                    'created_at' => date('M d, Y h:i A', strtotime($ord['created_at'])),
                    'date_short' => date('M d, Y', strtotime($ord['created_at'])),
                    'track_url' => 'track-order.php?query=' . urlencode($ord['order_number'])
                ];
            }

            send_json(true, 'Order history retrieved successfully.', [
                'total' => count($orders),
                'orders' => $orders
            ]);
            break;

        case 'customer_recent_shipments':
            // Fast endpoint specifically for 1-Click Shipment Tracking Card Selector on track-order.php
            if (empty($_SESSION['customer_id'])) {
                send_json(true, 'Guest mode', ['shipments' => []]);
            }

            $cid = (int) $_SESSION['customer_id'];
            $stmt = $db->prepare("
                SELECT o.id, o.order_number, o.courier_name, o.tracking_number, o.shipping_status, 
                       o.order_status, o.total_amount, o.created_at,
                       (SELECT product_name FROM order_items WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as first_item_name,
                       (SELECT CONCAT_WS(' • ', p.color, p.storage) FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id ORDER BY oi.id ASC LIMIT 1) as first_item_specs,
                       (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as total_items,
                       (SELECT pi.image_path FROM order_items oi JOIN product_images pi ON oi.product_id = pi.product_id WHERE oi.order_id = o.id ORDER BY pi.is_primary DESC, pi.id ASC LIMIT 1) as thumb_path
                FROM orders o
                WHERE o.customer_id = :cid AND o.deleted_at IS NULL
                ORDER BY o.id DESC
                LIMIT 5
            ");
            $stmt->execute([':cid' => $cid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $shipments = [];
            foreach ($rows as $row) {
                $shipments[] = [
                    'order_number' => $row['order_number'],
                    'first_item_name' => $row['first_item_name'] ?: 'Apple Genuine Device',
                    'first_item_specs' => $row['first_item_specs'] ?: '',
                    'total_items' => (int) $row['total_items'],
                    'courier_name' => $row['courier_name'] ?: 'Prestige Insured Logistics',
                    'tracking_number' => $row['tracking_number'] ?: '',
                    'shipping_status' => $row['shipping_status'],
                    'order_status' => $row['order_status'],
                    'total_amount' => $currency . number_format((float) $row['total_amount'], 2),
                    'date_placed' => date('M d, Y', strtotime($row['created_at'])),
                    'image_url' => !empty($row['thumb_path']) ? '../' . $row['thumb_path'] : null
                ];
            }

            send_json(true, 'Recent shipments loaded.', [
                'shipments' => $shipments
            ]);
            break;

        // =====================================================================
        // CUSTOMER SUPPORT & STORE INQUIRIES CHAT
        // =====================================================================
        case 'get_chat_unread_count':
            if (empty($_SESSION['customer_id'])) {
                send_json(true, 'Guest mode', ['unread' => 0]);
            }
            $cid = (int) $_SESSION['customer_id'];
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM customer_messages 
                WHERE customer_id = :cid 
                  AND sender_type = 'admin' 
                  AND is_read = 0
            ");
            $stmt->execute([':cid' => $cid]);
            $unreadCount = (int) $stmt->fetchColumn();

            send_json(true, 'Unread count fetched', ['unread' => $unreadCount]);
            break;

        case 'get_my_messages':
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to view messages.', ['redirect' => 'login.php'], [], 401);
            }
            $cid = (int) $_SESSION['customer_id'];
            $afterId = (int) ($input['after_id'] ?? 0);

            $query = "
                SELECT m.id, m.sender_type, m.message, m.product_id, m.order_id, m.is_read, m.created_at,
                       p.name AS product_name, p.selling_price, p.current_stock, p.color,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) as product_image
                FROM customer_messages m
                LEFT JOIN products p ON m.product_id = p.id
                WHERE m.customer_id = :cid
            ";
            $params = [':cid' => $cid];
            if ($afterId > 0) {
                $query .= " AND m.id > :after_id";
                $params[':after_id'] = $afterId;
            }
            $query .= " ORDER BY m.id ASC LIMIT 100";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mark received admin messages as read by customer
            $readStmt = $db->prepare("
                UPDATE customer_messages 
                SET is_read = 1, read_at = NOW() 
                WHERE customer_id = :cid 
                  AND sender_type = 'admin' 
                  AND is_read = 0
            ");
            $readStmt->execute([':cid' => $cid]);

            $currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';
            $messages = array_map(function ($r) use ($currency) {
                $isMe = ($r['sender_type'] === 'customer');
                return [
                    'id'             => (int) $r['id'],
                    'is_me'          => $isMe,
                    'sender_name'    => $isMe ? 'You' : 'Prestige Apple Store',
                    'message'        => $r['message'],
                    'time_formatted' => date('h:i A', strtotime($r['created_at'])),
                    'date_formatted' => date('M d, Y', strtotime($r['created_at'])),
                    'product'        => !empty($r['product_id']) ? [
                        'id'         => (int) $r['product_id'],
                        'name'       => $r['product_name'],
                        'price'      => $currency . number_format((float) $r['selling_price'], 2),
                        'stock'      => (int) $r['current_stock'],
                        'color'      => $r['color'] ?? '',
                        'image_url'  => !empty($r['product_image']) ? '../' . $r['product_image'] : null
                    ] : null
                ];
            }, $rows);

            send_json(true, 'Messages loaded successfully.', [
                'customer_name' => $_SESSION['customer_name'] ?? 'Valued Customer',
                'messages'      => $messages
            ]);
            break;

        case 'send_message_to_store':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_json(false, 'POST method required', null, [], 405);
            }
            if (empty($_SESSION['customer_id'])) {
                send_json(false, 'Sign in required to message the store.', ['redirect' => 'login.php'], [], 401);
            }
            $cid = (int) $_SESSION['customer_id'];
            $msgText = trim((string) ($input['message'] ?? ''));
            $productId = !empty($input['product_id']) ? (int) $input['product_id'] : null;

            if (empty($msgText)) {
                send_json(false, 'Message cannot be empty.', null, ['message' => 'Required'], 422);
            }

            // Verify product if provided
            $productData = null;
            if ($productId !== null) {
                $chkP = $db->prepare("SELECT id, name, selling_price, current_stock, color FROM products WHERE id = :pid AND deleted_at IS NULL LIMIT 1");
                $chkP->execute([':pid' => $productId]);
                $productData = $chkP->fetch(PDO::FETCH_ASSOC);
                if (!$productData) $productId = null;
            }

            $stmt = $db->prepare("
                INSERT INTO customer_messages (customer_id, sender_type, staff_id, message, product_id, is_read, created_at)
                VALUES (:cid, 'customer', NULL, :msg, :pid, 0, NOW())
            ");
            $stmt->execute([
                ':cid' => $cid,
                ':msg' => $msgText,
                ':pid' => $productId
            ]);

            $newId = (int) $db->lastInsertId();
            $currency = defined('APP_CURRENCY') ? APP_CURRENCY : '₱';

            send_json(true, 'Message sent to store support.', [
                'id'             => $newId,
                'is_me'          => true,
                'sender_name'    => 'You',
                'message'        => $msgText,
                'time_formatted' => date('h:i A'),
                'product'        => $productData ? [
                    'id'    => (int) $productData['id'],
                    'name'  => $productData['name'],
                    'price' => $currency . number_format((float) $productData['selling_price'], 2),
                    'stock' => (int) $productData['current_stock']
                ] : null
            ]);
            break;

        default:
            send_json(false, 'Unknown or invalid store action requested.', null, [
                'action' => 'Invalid action parameter'
            ], 400);
            break;
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Store Handler Database Error] ' . $e->getMessage());
    send_json(false, 'A database error occurred while processing your order. Please try again.', null, [], 500);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[Store Handler System Error] ' . $e->getMessage());
    send_json(false, 'An unexpected server error occurred.', null, [], 500);
}
