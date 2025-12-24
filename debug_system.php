<?php
/**
 * Debug System - Complete Version
 * ตรวจสอบระบบทั้งหมด
 */
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$userId = $_GET['user_id'] ?? 13;
$botId = $_GET['bot_id'] ?? $_SESSION['current_bot_id'] ?? 1;

echo "<h1>🔍 Debug System - Complete</h1>";
echo "<style>body{font-family:sans-serif;padding:20px;max-width:1400px;margin:auto}
.box{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px;margin:15px 0;box-shadow:0 2px 4px rgba(0,0,0,0.05)}
.success{color:#059669}.error{color:#DC2626}.info{color:#2563EB}
h2{color:#1F2937;border-bottom:2px solid #10B981;padding-bottom:8px}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border:1px solid #E5E7EB;text-align:left}
th{background:#F9FAFB}pre{background:#F3F4F6;padding:10px;border-radius:8px;overflow-x:auto;font-size:12px}</style>";

echo "<p>User ID: <strong>$userId</strong> | Bot ID: <strong>$botId</strong></p>";
echo "<p><a href='?user_id=$userId&bot_id=$botId'>Refresh</a> | <a href='run_all_fixes.php'>Run Fixes</a></p>";

// ==========================================
// 1. DATABASE TABLES
// ==========================================
echo "<div class='box'><h2>1. 📊 Database Tables</h2>";
$requiredTables = [
    'users', 'messages', 'line_accounts', 'tags', 'user_tags', 'user_tag_assignments',
    'user_points', 'points_history', 'rewards', 'business_categories', 'business_items',
    'cart_items', 'transactions', 'transaction_items', 'payment_slips', 'rich_menus', 'shop_settings'
];

echo "<table><tr><th>Table</th><th>Status</th><th>Rows</th><th>Columns</th></tr>";
foreach ($requiredTables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        $stmt = $db->query("SHOW COLUMNS FROM $table");
        $cols = count($stmt->fetchAll());
        echo "<tr><td>$table</td><td class='success'>✅ OK</td><td>$count</td><td>$cols</td></tr>";
    } catch (Exception $e) {
        echo "<tr><td>$table</td><td class='error'>❌ Missing</td><td>-</td><td>-</td></tr>";
    }
}
echo "</table></div>";

// ==========================================
// 2. USER INFO
// ==========================================
echo "<div class='box'><h2>2. 👤 User Info (ID: $userId)</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo "<table>";
        foreach ($user as $k => $v) {
            $v = htmlspecialchars(substr($v ?? '', 0, 100));
            echo "<tr><th>$k</th><td>$v</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ User not found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 3. USER TAGS
// ==========================================
echo "<div class='box'><h2>3. 🏷️ User Tags</h2>";

// From user_tag_assignments
echo "<h4>user_tag_assignments:</h4>";
try {
    $stmt = $db->prepare("SELECT uta.*, ut.name as tag_name, ut.color 
                          FROM user_tag_assignments uta 
                          LEFT JOIN user_tags ut ON uta.tag_id = ut.id 
                          WHERE uta.user_id = ?");
    $stmt->execute([$userId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($tags) {
        echo "<table><tr><th>ID</th><th>Tag ID</th><th>Tag Name</th><th>Color</th><th>Assigned By</th><th>Created</th></tr>";
        foreach ($tags as $t) {
            echo "<tr><td>{$t['id']}</td><td>{$t['tag_id']}</td><td>{$t['tag_name']}</td><td>{$t['color']}</td><td>{$t['assigned_by']}</td><td>{$t['created_at']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No tags assigned</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}

// Also check with tags table
echo "<h4>With tags table:</h4>";
try {
    $stmt = $db->prepare("SELECT t.*, uta.assigned_by 
                          FROM tags t 
                          JOIN user_tag_assignments uta ON t.id = uta.tag_id 
                          WHERE uta.user_id = ?");
    $stmt->execute([$userId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($tags) {
        echo "<pre>" . print_r($tags, true) . "</pre>";
    } else {
        echo "<p class='info'>ℹ️ No tags from tags table</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 4. LOYALTY POINTS
// ==========================================
echo "<div class='box'><h2>4. 💎 Loyalty Points</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM user_points WHERE user_id = ?");
    $stmt->execute([$userId]);
    $points = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($points) {
        echo "<table>";
        foreach ($points as $k => $v) {
            echo "<tr><th>$k</th><td>$v</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No points record</p>";
    }
    
    // History
    echo "<h4>Points History (last 5):</h4>";
    $stmt = $db->prepare("SELECT * FROM points_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($history) {
        echo "<table><tr><th>ID</th><th>Points</th><th>Type</th><th>Description</th><th>Created</th></tr>";
        foreach ($history as $h) {
            echo "<tr><td>{$h['id']}</td><td>{$h['points']}</td><td>{$h['type']}</td><td>{$h['description']}</td><td>{$h['created_at']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 5. TRANSACTIONS / ORDERS
// ==========================================
echo "<div class='box'><h2>5. 📦 Transactions / Orders</h2>";
try {
    $stmt = $db->prepare("SELECT t.*, 
                          (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count
                          FROM transactions t 
                          WHERE t.user_id = ? 
                          ORDER BY t.created_at DESC LIMIT 10");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($orders) {
        echo "<table><tr><th>ID</th><th>Order#</th><th>Status</th><th>Total</th><th>Items</th><th>Created</th></tr>";
        foreach ($orders as $o) {
            $status = $o['status'] ?? 'pending';
            echo "<tr>
                <td>{$o['id']}</td>
                <td>{$o['order_number']}</td>
                <td><span style='padding:2px 8px;border-radius:4px;background:#E5E7EB'>$status</span></td>
                <td>฿" . number_format($o['grand_total'] ?? 0, 2) . "</td>
                <td>{$o['item_count']}</td>
                <td>{$o['created_at']}</td>
            </tr>";
        }
        echo "</table>";
        
        // Show items of first order
        if (!empty($orders[0])) {
            $orderId = $orders[0]['id'];
            echo "<h4>Items in Order #{$orders[0]['order_number']}:</h4>";
            $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($items) {
                echo "<table><tr><th>Product</th><th>Price</th><th>Qty</th><th>Total</th></tr>";
                foreach ($items as $i) {
                    echo "<tr><td>{$i['product_name']}</td><td>฿" . number_format($i['price'], 2) . "</td><td>{$i['quantity']}</td><td>฿" . number_format($i['total'], 2) . "</td></tr>";
                }
                echo "</table>";
            }
        }
    } else {
        echo "<p class='info'>ℹ️ No transactions found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 6. CART ITEMS
// ==========================================
echo "<div class='box'><h2>6. 🛒 Cart Items</h2>";
try {
    $stmt = $db->prepare("SELECT c.*, bi.name as product_name, bi.price, bi.image_url 
                          FROM cart_items c 
                          LEFT JOIN business_items bi ON c.product_id = bi.id 
                          WHERE c.user_id = ?");
    $stmt->execute([$userId]);
    $cart = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($cart) {
        echo "<table><tr><th>ID</th><th>Product</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr>";
        $total = 0;
        foreach ($cart as $c) {
            $subtotal = ($c['price'] ?? 0) * $c['quantity'];
            $total += $subtotal;
            echo "<tr>
                <td>{$c['id']}</td>
                <td>{$c['product_name']}</td>
                <td>฿" . number_format($c['price'] ?? 0, 2) . "</td>
                <td>{$c['quantity']}</td>
                <td>฿" . number_format($subtotal, 2) . "</td>
            </tr>";
        }
        echo "<tr style='background:#F9FAFB;font-weight:bold'><td colspan='4'>Total</td><td>฿" . number_format($total, 2) . "</td></tr>";
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ Cart is empty</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 7. LINE ACCOUNT INFO
// ==========================================
echo "<div class='box'><h2>7. 📱 LINE Account Info</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ?");
    $stmt->execute([$botId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        echo "<table>";
        $safeFields = ['id', 'name', 'channel_id', 'bot_mode', 'welcome_message', 'is_active', 'created_at'];
        foreach ($account as $k => $v) {
            if (in_array($k, $safeFields)) {
                $v = htmlspecialchars(substr($v ?? '', 0, 100));
                echo "<tr><th>$k</th><td>$v</td></tr>";
            } elseif (strpos($k, 'secret') !== false || strpos($k, 'token') !== false) {
                echo "<tr><th>$k</th><td>***hidden***</td></tr>";
            }
        }
        echo "</table>";
    } else {
        echo "<p class='error'>❌ LINE Account not found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 8. RICH MENU STATUS
// ==========================================
echo "<div class='box'><h2>8. 🎨 Rich Menu Status</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM rich_menus WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY is_default DESC, id DESC LIMIT 5");
    $stmt->execute([$botId]);
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($menus) {
        echo "<table><tr><th>ID</th><th>Name</th><th>LINE ID</th><th>Size</th><th>Default</th><th>Created</th></tr>";
        foreach ($menus as $m) {
            $isDefault = $m['is_default'] ? '✅ Yes' : 'No';
            $lineId = $m['line_rich_menu_id'] ? substr($m['line_rich_menu_id'], 0, 20) . '...' : '-';
            echo "<tr>
                <td>{$m['id']}</td>
                <td>{$m['name']}</td>
                <td><code>$lineId</code></td>
                <td>{$m['size_width']}x{$m['size_height']}</td>
                <td>$isDefault</td>
                <td>{$m['created_at']}</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No rich menus found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 9. SHOP SETTINGS
// ==========================================
echo "<div class='box'><h2>9. ⚙️ Shop Settings</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? OR line_account_id IS NULL LIMIT 1");
    $stmt->execute([$botId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        echo "<table>";
        foreach ($settings as $k => $v) {
            if ($k === 'payment_methods' && $v) {
                $v = '<pre>' . htmlspecialchars(json_encode(json_decode($v), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            } else {
                $v = htmlspecialchars(substr($v ?? '', 0, 200));
            }
            echo "<tr><th>$k</th><td>$v</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No shop settings found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 10. PAYMENT SLIPS
// ==========================================
echo "<div class='box'><h2>10. 🧾 Payment Slips</h2>";
try {
    $stmt = $db->prepare("SELECT ps.*, t.order_number 
                          FROM payment_slips ps 
                          LEFT JOIN transactions t ON ps.transaction_id = t.id 
                          WHERE ps.user_id = ? 
                          ORDER BY ps.created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($slips) {
        echo "<table><tr><th>ID</th><th>Order#</th><th>Amount</th><th>Status</th><th>Created</th></tr>";
        foreach ($slips as $s) {
            $statusColor = $s['status'] === 'approved' ? '#059669' : ($s['status'] === 'rejected' ? '#DC2626' : '#D97706');
            echo "<tr>
                <td>{$s['id']}</td>
                <td>{$s['order_number']}</td>
                <td>฿" . number_format($s['amount'] ?? 0, 2) . "</td>
                <td><span style='color:$statusColor'>{$s['status']}</span></td>
                <td>{$s['created_at']}</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No payment slips found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 11. PRODUCTS
// ==========================================
echo "<div class='box'><h2>11. 📦 Products</h2>";
try {
    $stmt = $db->prepare("SELECT p.*, pc.name as category_name 
                          FROM products p 
                          LEFT JOIN product_categories pc ON p.category_id = pc.id 
                          WHERE p.line_account_id = ? OR p.line_account_id IS NULL 
                          ORDER BY p.sort_order, p.id LIMIT 10");
    $stmt->execute([$botId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($products) {
        echo "<table><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Active</th></tr>";
        foreach ($products as $p) {
            $active = $p['is_active'] ? '✅' : '❌';
            $stock = $p['stock'] == -1 ? '∞' : $p['stock'];
            echo "<tr>
                <td>{$p['id']}</td>
                <td>{$p['name']}</td>
                <td>{$p['category_name']}</td>
                <td>฿" . number_format($p['price'], 2) . "</td>
                <td>$stock</td>
                <td>$active</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>ℹ️ No products found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}
echo "</div>";

// ==========================================
// 12. SYSTEM SUMMARY
// ==========================================
echo "<div class='box'><h2>12. 📊 System Summary</h2>";
$stats = [];
try {
    $stats['users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['messages'] = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $stats['transactions'] = $db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
    $stats['products'] = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $stats['tags'] = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    
    echo "<div style='display:grid;grid-template-columns:repeat(5,1fr);gap:15px;text-align:center'>";
    foreach ($stats as $label => $count) {
        echo "<div style='background:#F3F4F6;padding:20px;border-radius:12px'>
            <div style='font-size:28px;font-weight:bold;color:#059669'>$count</div>
            <div style='color:#6B7280;text-transform:uppercase;font-size:12px'>$label</div>
        </div>";
    }
    echo "</div>";
} catch (Exception $e) {
    echo "<p class='error'>❌ " . $e->getMessage() . "</p>";
}

// Recommendations
echo "<h4 style='margin-top:20px'>🔍 Recommendations:</h4>";
echo "<ul>";
$recommendations = [];

try {
    // Check if user has points
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_points WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() == 0) {
        $recommendations[] = "User ID $userId ยังไม่มีข้อมูล points - ควรเพิ่มใน user_points";
    }
    
    // Check if shop has products
    if ($stats['products'] == 0) {
        $recommendations[] = "ยังไม่มีสินค้าในระบบ - ควรเพิ่มสินค้าใน business_items";
    }
    
    // Check rich menu
    $stmt = $db->query("SELECT COUNT(*) FROM rich_menus WHERE is_default = 1");
    if ($stmt->fetchColumn() == 0) {
        $recommendations[] = "ยังไม่มี default rich menu - ควรตั้งค่าใน rich-menu.php";
    }
    
    if (empty($recommendations)) {
        echo "<li class='success'>✅ ระบบพร้อมใช้งาน!</li>";
    } else {
        foreach ($recommendations as $r) {
            echo "<li class='info'>ℹ️ $r</li>";
        }
    }
} catch (Exception $e) {}
echo "</ul>";
echo "</div>";

// Quick Links
echo "<div class='box'><h2>🔗 Quick Links</h2>";
echo "<div style='display:flex;gap:10px;flex-wrap:wrap'>";
$links = [
    'run_all_fixes.php' => '🔧 Run Fixes',
    'user-detail.php?id=' . $userId => '👤 User Detail',
    'messages.php' => '💬 Messages',
    'rich-menu.php' => '📱 Rich Menu',
    'loyalty-points.php' => '💎 Loyalty Points',
    'shop/index.php' => '🛒 Shop',
    'debug_tags.php?user_id=' . $userId => '🏷️ Debug Tags',
    'debug_rich_menu.php' => '🎨 Debug Rich Menu'
];
foreach ($links as $url => $label) {
    echo "<a href='$url' style='padding:10px 20px;background:#10B981;color:white;text-decoration:none;border-radius:8px'>$label</a>";
}
echo "</div></div>";
