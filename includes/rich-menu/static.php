<?php
/**
 * Rich Menu - Static (Visual Editor)
 * สร้างและจัดการ Rich Menu ด้วย Visual Editor
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Variables from parent: $db, $currentBotId, $line, $lineManager

/**
 * Resize and compress image for Rich Menu
 * LINE API limit: 1MB max file size
 * Must be exactly 2500xHeight pixels
 */
function resizeRichMenuImage($sourcePath, $targetWidth, $targetHeight) {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) return false;
    
    $mime = $imageInfo['mime'];
    $srcWidth = $imageInfo[0];
    $srcHeight = $imageInfo[1];
    
    // Create source image
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $srcImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $srcImage = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $srcImage = imagecreatefromgif($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$srcImage) return false;
    
    // Create target image
    $dstImage = imagecreatetruecolor($targetWidth, $targetHeight);
    
    // White background (for JPEG output)
    $white = imagecolorallocate($dstImage, 255, 255, 255);
    imagefilledrectangle($dstImage, 0, 0, $targetWidth, $targetHeight, $white);
    
    // Calculate resize dimensions (cover mode)
    $srcRatio = $srcWidth / $srcHeight;
    $dstRatio = $targetWidth / $targetHeight;
    
    if ($srcRatio > $dstRatio) {
        // Source is wider - crop sides
        $newHeight = $srcHeight;
        $newWidth = (int)($srcHeight * $dstRatio);
        $srcX = (int)(($srcWidth - $newWidth) / 2);
        $srcY = 0;
    } else {
        // Source is taller - crop top/bottom
        $newWidth = $srcWidth;
        $newHeight = (int)($srcWidth / $dstRatio);
        $srcX = 0;
        $srcY = (int)(($srcHeight - $newHeight) / 2);
    }
    
    // Resize
    imagecopyresampled($dstImage, $srcImage, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, (int)$newWidth, (int)$newHeight);
    
    // Save as JPEG with compression (LINE API limit 1MB)
    $tempPath = sys_get_temp_dir() . '/richmenu_' . uniqid() . '.jpg';
    
    // Try different quality levels to get under 1MB
    $quality = 85;
    do {
        imagejpeg($dstImage, $tempPath, $quality);
        $fileSize = filesize($tempPath);
        $quality -= 10;
    } while ($fileSize > 1000000 && $quality > 30); // 1MB limit
    
    imagedestroy($srcImage);
    imagedestroy($dstImage);
    
    // Log file size for debugging
    error_log("Rich Menu image resized: " . round($fileSize / 1024) . "KB, quality: " . ($quality + 10));
    
    return $tempPath;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['tab'] ?? 'static') === 'static') {
    $action = $_POST['action'] ?? '';
    $errorMessage = '';
    $skipImage = !empty($_POST['skip_image']);
    
    if ($action === 'create') {
        // ถ้า skip_image ไม่ต้องตรวจสอบรูป
        if (!$skipImage && (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK)) {
            $errorMessage = "กรุณาอัพโหลดรูปภาพ Rich Menu";
        }
        
        if (empty($errorMessage)) {
            $areas = json_decode($_POST['areas'], true) ?: [];
            $targetHeight = (int)$_POST['size_height'];
            
            // แปลง areas ให้เป็นรูปแบบที่ LINE API รองรับ
            $lineAreas = array_map(function($area) {
                $lineArea = ['bounds' => $area['bounds']];
                
                // แปลง action type
                if ($area['action']['type'] === 'richmenuswitch') {
                    $lineArea['action'] = [
                        'type' => 'richmenuswitch',
                        'richMenuAliasId' => $area['action']['richMenuAliasId'],
                        'data' => 'richmenu-switch-' . $area['action']['richMenuAliasId']
                    ];
                } else {
                    $lineArea['action'] = $area['action'];
                }
                
                return $lineArea;
            }, $areas);
            
            $menuData = [
                'size' => ['width' => 2500, 'height' => $targetHeight],
                'selected' => false,
                'name' => $_POST['name'],
                'chatBarText' => $_POST['chat_bar_text'],
                'areas' => $lineAreas
            ];
            
            $result = $line->createRichMenu($menuData);
            
            if ($result['code'] === 200 && isset($result['body']['richMenuId'])) {
                $richMenuId = $result['body']['richMenuId'];
                
                // Upload image if provided (not skipped)
                if (!$skipImage && !empty($_FILES['image']['tmp_name'])) {
                    $imagePath = $_FILES['image']['tmp_name'];
                    $imageInfo = getimagesize($imagePath);
                    
                    if ($imageInfo) {
                        $srcWidth = $imageInfo[0];
                        $srcHeight = $imageInfo[1];
                        
                        if ($srcWidth !== 2500 || $srcHeight !== $targetHeight) {
                            $resizedPath = resizeRichMenuImage($imagePath, 2500, $targetHeight);
                            if ($resizedPath) {
                                $imagePath = $resizedPath;
                            }
                        }
                    }
                    
                    $uploadResult = $line->uploadRichMenuImage($richMenuId, $imagePath);
                    
                    if (isset($resizedPath) && file_exists($resizedPath)) {
                        unlink($resizedPath);
                    }
                    
                    if ($uploadResult['code'] !== 200) {
                        $errorMessage = "Upload failed (HTTP {$uploadResult['code']}): " . ($uploadResult['body']['message'] ?? json_encode($uploadResult['body'] ?? $uploadResult['error'] ?? 'Unknown error'));
                        error_log("Rich Menu Upload Error: " . json_encode($uploadResult));
                    }
                }
                
                // บันทึกลง DB
                if (!empty($richMenuId)) {
                    try {
                        $stmt = $db->query("SHOW COLUMNS FROM rich_menus LIKE 'line_account_id'");
                        if ($stmt->rowCount() > 0) {
                            $stmt = $db->prepare("INSERT INTO rich_menus (line_rich_menu_id, name, chat_bar_text, size_height, areas, line_account_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$richMenuId, $_POST['name'], $_POST['chat_bar_text'], $_POST['size_height'], $_POST['areas'], $currentBotId]);
                        } else {
                            $stmt = $db->prepare("INSERT INTO rich_menus (line_rich_menu_id, name, chat_bar_text, size_height, areas) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$richMenuId, $_POST['name'], $_POST['chat_bar_text'], $_POST['size_height'], $_POST['areas']]);
                        }
                    } catch (Exception $e) {
                        $stmt = $db->prepare("INSERT INTO rich_menus (line_rich_menu_id, name, chat_bar_text, size_height, areas) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$richMenuId, $_POST['name'], $_POST['chat_bar_text'], $_POST['size_height'], $_POST['areas']]);
                    }
                    
                    // Return richMenuId for JavaScript to upload image
                    if ($skipImage) {
                        echo "richMenuId: " . $richMenuId;
                        $_SESSION['rich_menu_success'] = 'สร้าง Rich Menu สำเร็จ (รอ upload รูป)';
                    } else {
                        $_SESSION['rich_menu_success'] = 'สร้าง Rich Menu สำเร็จ';
                    }
                }
            } else {
                $errorMessage = "สร้าง Rich Menu ไม่สำเร็จ: " . json_encode($result['body'] ?? []);
            }
        }
    } elseif ($action === 'set_default') {
        $stmt = $db->prepare("SELECT line_rich_menu_id, name FROM rich_menus WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $menu = $stmt->fetch();
        
        if ($menu) {
            // ตรวจสอบว่า Rich Menu มีรูปภาพหรือยัง
            $imageCheck = $line->getRichMenuImage($menu['line_rich_menu_id']);
            if (empty($imageCheck)) {
                $errorMessage = 'Rich Menu "' . htmlspecialchars($menu['name']) . '" ยังไม่มีรูปภาพ กรุณากดแก้ไขและ upload รูปก่อนตั้งเป็น Default';
            } else {
                $result = $line->setDefaultRichMenu($menu['line_rich_menu_id']);
                
                if ($result['code'] === 200) {
                    $db->query("UPDATE rich_menus SET is_default = 0");
                    $stmt = $db->prepare("UPDATE rich_menus SET is_default = 1 WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $_SESSION['rich_menu_success'] = 'ตั้ง Default Rich Menu สำเร็จ';
                } else {
                    // แปลง error message ให้เข้าใจง่าย
                    $apiError = $result['body']['message'] ?? json_encode($result['body'] ?? $result);
                    if (strpos($apiError, 'must upload richmenu image') !== false) {
                        $errorMessage = 'Rich Menu "' . htmlspecialchars($menu['name']) . '" ยังไม่มีรูปภาพ กรุณากดแก้ไขและ upload รูปก่อนตั้งเป็น Default';
                    } else {
                        $errorMessage = 'ไม่สามารถตั้ง Default Rich Menu ได้: ' . $apiError;
                    }
                    error_log("setDefaultRichMenu failed: " . json_encode($result));
                }
            }
        } else {
            $errorMessage = 'ไม่พบ Rich Menu ที่เลือก';
        }
    } elseif ($action === 'upload_image') {
        // Upload รูปให้ Rich Menu ที่มีอยู่แล้ว
        $menuId = (int)$_POST['id'];
        
        $stmt = $db->prepare("SELECT line_rich_menu_id, name, size_height FROM rich_menus WHERE id = ?");
        $stmt->execute([$menuId]);
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($menu) {
            if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = "กรุณาเลือกรูปภาพ";
            } else {
                $imagePath = $_FILES['image']['tmp_name'];
                $targetHeight = (int)($menu['size_height'] ?? 1686);
                $imageInfo = getimagesize($imagePath);
                
                if ($imageInfo) {
                    $srcWidth = $imageInfo[0];
                    $srcHeight = $imageInfo[1];
                    
                    // Resize ถ้าจำเป็น
                    if ($srcWidth !== 2500 || $srcHeight !== $targetHeight) {
                        $resizedPath = resizeRichMenuImage($imagePath, 2500, $targetHeight);
                        if ($resizedPath) {
                            $imagePath = $resizedPath;
                        }
                    }
                }
                
                $uploadResult = $line->uploadRichMenuImage($menu['line_rich_menu_id'], $imagePath);
                
                // Clean up temp file
                if (isset($resizedPath) && file_exists($resizedPath)) {
                    unlink($resizedPath);
                }
                
                if ($uploadResult['code'] === 200) {
                    $_SESSION['rich_menu_success'] = 'Upload รูป Rich Menu "' . htmlspecialchars($menu['name']) . '" สำเร็จ';
                } else {
                    $errorMessage = "Upload รูปไม่สำเร็จ: " . ($uploadResult['body']['message'] ?? json_encode($uploadResult));
                    error_log("Upload Rich Menu Image failed: " . json_encode($uploadResult));
                }
            }
        } else {
            $errorMessage = 'ไม่พบ Rich Menu ที่เลือก';
        }
    } elseif ($action === 'update') {
        // อัพเดท Rich Menu
        $menuId = (int)$_POST['menu_id'];
        $areas = $_POST['areas'];
        
        // ดึงข้อมูลเดิม
        $stmt = $db->prepare("SELECT line_rich_menu_id, name, chat_bar_text, size_height FROM rich_menus WHERE id = ?");
        $stmt->execute([$menuId]);
        $oldMenu = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($oldMenu) {
            // สร้าง Rich Menu ใหม่บน LINE (เพราะ LINE ไม่รองรับแก้ไข)
            $areasArray = json_decode($areas, true) ?: [];
            $targetHeight = (int)($_POST['size_height'] ?? $oldMenu['size_height']);
            
            // แปลง areas ให้เป็นรูปแบบที่ LINE API รองรับ
            $lineAreas = array_map(function($area) {
                $lineArea = ['bounds' => $area['bounds']];
                
                if ($area['action']['type'] === 'richmenuswitch') {
                    $lineArea['action'] = [
                        'type' => 'richmenuswitch',
                        'richMenuAliasId' => $area['action']['richMenuAliasId'],
                        'data' => 'richmenu-switch-' . $area['action']['richMenuAliasId']
                    ];
                } else {
                    $lineArea['action'] = $area['action'];
                }
                
                return $lineArea;
            }, $areasArray);
            
            $menuData = [
                'size' => ['width' => 2500, 'height' => $targetHeight],
                'selected' => false,
                'name' => $_POST['name'] ?? $oldMenu['name'],
                'chatBarText' => $_POST['chat_bar_text'] ?? $oldMenu['chat_bar_text'],
                'areas' => $lineAreas
            ];
            
            $result = $line->createRichMenu($menuData);
            
            if ($result['code'] === 200 && isset($result['body']['richMenuId'])) {
                $newRichMenuId = $result['body']['richMenuId'];
                $imageUploaded = false;
                
                // ตรวจสอบว่ามีรูปใหม่ upload มาหรือไม่
                if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    // ใช้รูปใหม่
                    $imagePath = $_FILES['image']['tmp_name'];
                    $imageInfo = getimagesize($imagePath);
                    
                    if ($imageInfo) {
                        $srcWidth = $imageInfo[0];
                        $srcHeight = $imageInfo[1];
                        
                        // Resize ถ้าจำเป็น
                        if ($srcWidth !== 2500 || $srcHeight !== $targetHeight) {
                            $resizedPath = resizeRichMenuImage($imagePath, 2500, $targetHeight);
                            if ($resizedPath) {
                                $imagePath = $resizedPath;
                            }
                        }
                    }
                    
                    $uploadResult = $line->uploadRichMenuImage($newRichMenuId, $imagePath);
                    
                    // Clean up temp file
                    if (isset($resizedPath) && file_exists($resizedPath)) {
                        unlink($resizedPath);
                    }
                    
                    if ($uploadResult['code'] === 200) {
                        $imageUploaded = true;
                    } else {
                        $errorMessage = "Upload รูปใหม่ไม่สำเร็จ: " . ($uploadResult['body']['message'] ?? json_encode($uploadResult));
                        error_log("Rich Menu Update - Upload new image failed: " . json_encode($uploadResult));
                    }
                } else {
                    // ไม่มีรูปใหม่ - พยายาม copy จาก menu เก่า
                    $oldImageData = $line->downloadRichMenuImage($oldMenu['line_rich_menu_id']);
                    if ($oldImageData) {
                        $tempFile = sys_get_temp_dir() . '/richmenu_copy_' . uniqid() . '.png';
                        file_put_contents($tempFile, $oldImageData);
                        $uploadResult = $line->uploadRichMenuImage($newRichMenuId, $tempFile);
                        unlink($tempFile);
                        
                        if ($uploadResult['code'] === 200) {
                            $imageUploaded = true;
                        }
                    }
                }
                
                if (empty($errorMessage)) {
                    // ลบ Rich Menu เก่าจาก LINE
                    $line->deleteRichMenu($oldMenu['line_rich_menu_id']);
                    
                    // อัพเดท DB
                    $stmt = $db->prepare("UPDATE rich_menus SET line_rich_menu_id = ?, name = ?, chat_bar_text = ?, size_height = ?, areas = ? WHERE id = ?");
                    $stmt->execute([
                        $newRichMenuId,
                        $_POST['name'] ?? $oldMenu['name'],
                        $_POST['chat_bar_text'] ?? $oldMenu['chat_bar_text'],
                        $targetHeight,
                        $areas,
                        $menuId
                    ]);
                    
                    if ($imageUploaded) {
                        $_SESSION['rich_menu_success'] = 'อัพเดท Rich Menu สำเร็จ';
                    } else {
                        $_SESSION['rich_menu_success'] = 'อัพเดท Rich Menu สำเร็จ (แต่ยังไม่มีรูป กรุณา upload รูปเพิ่ม)';
                    }
                }
            } else {
                $errorMessage = 'ไม่สามารถสร้าง Rich Menu ใหม่ได้: ' . json_encode($result['body'] ?? []);
            }
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("SELECT line_rich_menu_id FROM rich_menus WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $menu = $stmt->fetch();
        
        if ($menu) {
            $line->deleteRichMenu($menu['line_rich_menu_id']);
            $stmt = $db->prepare("DELETE FROM rich_menus WHERE id = ?");
            $stmt->execute([$_POST['id']]);
        }
    } elseif ($action === 'sync_from_line') {
        // Sync Rich Menus from LINE
        $lineMenus = $line->getRichMenuList();
        if ($lineMenus['code'] === 200) {
            $lineMenuIds = [];
            $synced = 0;
            
            // เก็บ list ของ menu ที่มีอยู่บน LINE
            if (!empty($lineMenus['body']['richmenus'])) {
                foreach ($lineMenus['body']['richmenus'] as $lineMenu) {
                    $richMenuId = $lineMenu['richMenuId'];
                    $lineMenuIds[] = $richMenuId;
                    
                    // Check if already exists in DB
                    $stmt = $db->prepare("SELECT id FROM rich_menus WHERE line_rich_menu_id = ?");
                    $stmt->execute([$richMenuId]);
                    if (!$stmt->fetch()) {
                        // Insert new
                        try {
                            $stmt = $db->query("SHOW COLUMNS FROM rich_menus LIKE 'line_account_id'");
                            if ($stmt->rowCount() > 0) {
                                $stmt = $db->prepare("INSERT INTO rich_menus (line_rich_menu_id, name, chat_bar_text, size_height, areas, line_account_id) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $richMenuId,
                                    $lineMenu['name'] ?? 'Synced Menu',
                                    $lineMenu['chatBarText'] ?? 'Menu',
                                    $lineMenu['size']['height'] ?? 1686,
                                    json_encode($lineMenu['areas'] ?? []),
                                    $currentBotId
                                ]);
                            } else {
                                $stmt = $db->prepare("INSERT INTO rich_menus (line_rich_menu_id, name, chat_bar_text, size_height, areas) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $richMenuId,
                                    $lineMenu['name'] ?? 'Synced Menu',
                                    $lineMenu['chatBarText'] ?? 'Menu',
                                    $lineMenu['size']['height'] ?? 1686,
                                    json_encode($lineMenu['areas'] ?? [])
                                ]);
                            }
                            $synced++;
                        } catch (Exception $e) {}
                    }
                }
            }
            
            // ลบ menu ที่ไม่มีบน LINE แล้ว
            $deleted = 0;
            $stmt = $db->query("SELECT id, line_rich_menu_id, name FROM rich_menus");
            $dbMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dbMenus as $dbMenu) {
                if (!empty($dbMenu['line_rich_menu_id']) && !in_array($dbMenu['line_rich_menu_id'], $lineMenuIds)) {
                    $stmt = $db->prepare("DELETE FROM rich_menus WHERE id = ?");
                    $stmt->execute([$dbMenu['id']]);
                    $deleted++;
                }
            }
            
            $totalOnLine = count($lineMenuIds);
            $_SESSION['rich_menu_success'] = "Sync สำเร็จ! พบ {$totalOnLine} เมนูบน LINE, เพิ่มใหม่ {$synced} เมนู, ลบที่ไม่มีแล้ว {$deleted} เมนู";
        } else {
            $errorMessage = "ไม่สามารถดึงข้อมูลจาก LINE ได้: " . json_encode($lineMenus['body'] ?? []);
        }
    }
    
    if (!empty($errorMessage)) {
        $_SESSION['rich_menu_error'] = $errorMessage;
    } else {
        $_SESSION['rich_menu_success'] = 'บันทึกสำเร็จ';
    }
    // Use JavaScript redirect since headers may already be sent
    echo '<script>window.location.href = "rich-menu.php?tab=static";</script>';
    exit;
}

// Get messages
$errorMessage = $_SESSION['rich_menu_error'] ?? '';
$successMessage = $_SESSION['rich_menu_success'] ?? '';
unset($_SESSION['rich_menu_error'], $_SESSION['rich_menu_success']);

// Get all rich menus for current bot
$menus = [];
try {
    // ตรวจสอบว่ามี column line_account_id หรือไม่
    $stmt = $db->query("SHOW COLUMNS FROM rich_menus LIKE 'line_account_id'");
    if ($stmt->rowCount() > 0) {
        $stmt = $db->prepare("SELECT * FROM rich_menus WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY created_at DESC");
        $stmt->execute([$currentBotId]);
    } else {
        $stmt = $db->query("SELECT * FROM rich_menus ORDER BY created_at DESC");
    }
    $menus = $stmt->fetchAll();
} catch (Exception $e) {
    // ตารางอาจไม่มี - สร้างใหม่
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS rich_menus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_rich_menu_id VARCHAR(100),
            name VARCHAR(255) NOT NULL,
            chat_bar_text VARCHAR(50),
            size_width INT DEFAULT 2500,
            size_height INT DEFAULT 1686,
            areas JSON,
            image_path VARCHAR(255),
            is_default TINYINT(1) DEFAULT 0,
            line_account_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e2) {}
    $menus = [];
}
?>

<?php if ($errorMessage): ?>
<div class="mb-4 p-4 bg-red-100 border border-red-300 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($errorMessage) ?>
</div>
<?php endif; ?>

<?php if ($successMessage): ?>
<div class="mb-4 p-4 bg-green-100 border border-green-300 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($successMessage) ?>
</div>
<?php endif; ?>

<div class="mb-4 flex justify-between items-center">
    <p class="text-gray-600">สร้างและจัดการ Rich Menu ด้วย Visual Editor</p>
    <div class="flex gap-2">
        <form method="POST" action="rich-menu.php?tab=static">
            <input type="hidden" name="action" value="sync_from_line">
            <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                <i class="fas fa-sync mr-2"></i>Sync จาก LINE
            </button>
        </form>
        <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            <i class="fas fa-plus mr-2"></i>สร้าง Rich Menu
        </button>
    </div>
</div>

<!-- Existing Menus -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($menus as $menu): ?>
    <?php 
    // ดึงรูปจาก LINE API
    $imageData = null;
    if (!empty($menu['line_rich_menu_id'])) {
        $imageData = $line->getRichMenuImage($menu['line_rich_menu_id']);
    }
    ?>
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="aspect-video bg-gray-100 flex items-center justify-center overflow-hidden">
            <?php if ($imageData): ?>
            <img src="<?= $imageData ?>" alt="<?= htmlspecialchars($menu['name']) ?>" class="w-full h-full object-cover">
            <?php else: ?>
            <i class="fas fa-image text-4xl text-gray-300"></i>
            <?php endif; ?>
        </div>
        <div class="p-4">
            <div class="flex justify-between items-start mb-2">
                <h3 class="font-semibold"><?= htmlspecialchars($menu['name']) ?></h3>
                <?php if ($menu['is_default']): ?>
                <span class="px-2 py-1 text-xs bg-green-100 text-green-600 rounded">Default</span>
                <?php elseif (!$imageData): ?>
                <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-600 rounded">ไม่มีรูป</span>
                <?php endif; ?>
            </div>
            <p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars($menu['chat_bar_text']) ?></p>
            
            <?php if (!$imageData): ?>
            <!-- Upload Image Button (uses JavaScript API) -->
            <div class="mb-3 p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                <label class="block text-sm font-medium text-yellow-700 mb-2">
                    <i class="fas fa-exclamation-triangle mr-1"></i>ต้อง upload รูปก่อนใช้งาน
                </label>
                <button type="button" 
                        data-menu-id="<?= $menu['id'] ?>"
                        onclick="uploadImageToMenu(<?= $menu['id'] ?>, '<?= htmlspecialchars($menu['line_rich_menu_id']) ?>')" 
                        class="w-full py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 text-sm">
                    <i class="fas fa-upload mr-1"></i>เลือกและ Upload รูป
                </button>
            </div>
            <?php endif; ?>
            
            <div class="flex space-x-2">
                <button type="button" onclick="editRichMenu(<?= $menu['id'] ?>, '<?= htmlspecialchars(addslashes($menu['name'])) ?>', '<?= htmlspecialchars(addslashes($menu['chat_bar_text'])) ?>', <?= $menu['size_height'] ?? 1686 ?>, '<?= htmlspecialchars(addslashes($menu['areas'] ?? '[]')) ?>')" 
                        class="px-4 py-2 border border-blue-500 text-blue-500 rounded-lg hover:bg-blue-50">
                    <i class="fas fa-edit"></i>
                </button>
                <?php if (!$menu['is_default'] && $imageData): ?>
                <form method="POST" action="rich-menu.php?tab=static" class="flex-1">
                    <input type="hidden" name="action" value="set_default">
                    <input type="hidden" name="id" value="<?= $menu['id'] ?>">
                    <button type="submit" class="w-full py-2 border border-green-500 text-green-500 rounded-lg hover:bg-green-50 text-sm">ตั้งเป็น Default</button>
                </form>
                <?php endif; ?>
                <form method="POST" action="rich-menu.php?tab=static" onsubmit="return confirm('ลบ Rich Menu นี้?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $menu['id'] ?>">
                    <button type="submit" class="px-4 py-2 border border-red-300 text-red-500 rounded-lg hover:bg-red-50"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($menus)): ?>
    <div class="col-span-full bg-white rounded-xl shadow p-8 text-center text-gray-500">
        <i class="fas fa-th-large text-6xl mb-4"></i>
        <p>ยังไม่มี Rich Menu</p>
    </div>
    <?php endif; ?>
</div>


<!-- Visual Editor Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="bg-white rounded-xl w-full max-w-5xl mx-4 my-8">
        <form method="POST" action="rich-menu.php?tab=static" enctype="multipart/form-data" id="richMenuForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="areas" id="areasJson">
            
            <div class="p-6 border-b flex justify-between items-center">
                <h3 id="modalTitle" class="text-lg font-semibold">🎨 สร้าง Rich Menu</h3>
                <button type="button" onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Left: Settings -->
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อ Rich Menu</label>
                        <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="เช่น Main Menu">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Chat Bar Text <span class="text-gray-400">(สูงสุด 14 ตัว)</span></label>
                        <input type="text" name="chat_bar_text" required maxlength="14" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="เช่น เมนู">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ขนาด</label>
                        <select name="size_height" id="sizeHeight" onchange="updateCanvasSize()" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="1686">Large (2500 x 1686) - 6 ปุ่ม</option>
                            <option value="843">Compact (2500 x 843) - 3 ปุ่ม</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">รูปภาพ Rich Menu</label>
                        <input type="file" name="image" id="imageInput" accept="image/png,image/jpeg" onchange="handleImageSelect(this);" class="w-full px-4 py-2 border rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">PNG/JPEG ขนาด 2500 x 1686 หรือ 2500 x 843 px (รูปจะถูก resize อัตโนมัติ)</p>
                        <div id="imageInfo" class="text-xs mt-1"></div>
                    </div>
                    
                    <!-- Template Buttons -->
                    <div>
                        <label class="block text-sm font-medium mb-2">เทมเพลตด่วน</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" onclick="applyTemplate(2, 3)" class="p-2 border rounded-lg hover:bg-gray-50 text-sm">
                                <i class="fas fa-th-large block text-xl mb-1"></i>2x3 (6 ปุ่ม)
                            </button>
                            <button type="button" onclick="applyTemplate(1, 3)" class="p-2 border rounded-lg hover:bg-gray-50 text-sm">
                                <i class="fas fa-columns block text-xl mb-1"></i>1x3 (3 ปุ่ม)
                            </button>
                            <button type="button" onclick="applyTemplate(2, 2)" class="p-2 border rounded-lg hover:bg-gray-50 text-sm">
                                <i class="fas fa-th block text-xl mb-1"></i>2x2 (4 ปุ่ม)
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Right: Visual Editor -->
                <div>
                    <label class="block text-sm font-medium mb-2">พื้นที่คลิก <span class="text-gray-400">(คลิกที่ช่องเพื่อตั้งค่า)</span></label>
                    <div class="relative border-2 border-dashed border-gray-300 rounded-lg overflow-hidden bg-gray-100" id="canvasContainer">
                        <img id="previewImage" src="" class="w-full hidden">
                        <div id="areasOverlay" class="absolute inset-0"></div>
                    </div>
                </div>
            </div>
            
            <!-- Areas List -->
            <div class="px-6 pb-6">
                <label class="block text-sm font-medium mb-2">รายการ Actions</label>
                <div id="areasList" class="space-y-2 max-h-48 overflow-y-auto"></div>
            </div>
            
            <div class="p-6 border-t flex justify-end space-x-2 bg-gray-50">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                <button type="submit" id="submitBtn" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-save mr-2"></i>สร้าง Rich Menu
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Area Edit Modal -->
<div id="areaModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[60]">
    <div class="bg-white rounded-xl w-full max-w-md mx-4">
        <div class="p-4 border-b">
            <h4 class="font-semibold">ตั้งค่า Action</h4>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">ประเภท Action</label>
                <select id="actionType" onchange="toggleActionFields()" class="w-full px-4 py-2 border rounded-lg">
                    <option value="message">📝 ส่งข้อความ</option>
                    <option value="uri">🔗 เปิดลิงก์</option>
                    <option value="postback">📤 Postback</option>
                    <option value="richmenuswitch">🔄 สลับ Rich Menu</option>
                </select>
            </div>
            <div id="messageField">
                <label class="block text-sm font-medium mb-1">ข้อความที่จะส่ง</label>
                <input type="text" id="actionText" class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น shop, menu, ติดต่อเรา">
            </div>
            <div id="uriField" class="hidden">
                <label class="block text-sm font-medium mb-1">URL</label>
                <input type="url" id="actionUri" class="w-full px-4 py-2 border rounded-lg" placeholder="https://example.com">
            </div>
            <div id="postbackField" class="hidden">
                <label class="block text-sm font-medium mb-1">Postback Data</label>
                <input type="text" id="actionData" class="w-full px-4 py-2 border rounded-lg" placeholder="action=buy&item=1">
            </div>
            <div id="switchField" class="hidden">
                <label class="block text-sm font-medium mb-1">Rich Menu Alias ID</label>
                <input type="text" id="actionAlias" class="w-full px-4 py-2 border rounded-lg" placeholder="richmenu-alias-1-page2">
                <p class="text-xs text-gray-500 mt-1">ดู Alias ID ได้จากแท็บ "สลับหน้า"</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ป้ายกำกับ (แสดงใน Editor)</label>
                <input type="text" id="actionLabel" class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น ดูสินค้า">
            </div>
        </div>
        <div class="p-4 border-t flex justify-end space-x-2">
            <button type="button" onclick="closeAreaModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
            <button type="button" onclick="saveAreaAction()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
        </div>
    </div>
</div>

<script>
let areas = [];
let currentAreaIndex = -1;
let editingMenuId = null;
const CANVAS_WIDTH = 2500;
let canvasHeight = 1686;
let pendingImageBase64 = null;

// Compress image and return base64
async function compressImageToBase64(file, maxWidth, maxHeight, maxSizeKB = 800) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas');
                canvas.width = maxWidth;
                canvas.height = maxHeight;
                const ctx = canvas.getContext('2d');
                
                // White background
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, maxWidth, maxHeight);
                
                // Calculate crop/resize (cover mode)
                const srcRatio = img.width / img.height;
                const dstRatio = maxWidth / maxHeight;
                let sx, sy, sw, sh;
                
                if (srcRatio > dstRatio) {
                    sh = img.height;
                    sw = img.height * dstRatio;
                    sx = (img.width - sw) / 2;
                    sy = 0;
                } else {
                    sw = img.width;
                    sh = img.width / dstRatio;
                    sx = 0;
                    sy = (img.height - sh) / 2;
                }
                
                ctx.drawImage(img, sx, sy, sw, sh, 0, 0, maxWidth, maxHeight);
                
                // Try different quality levels
                let quality = 0.8;
                
                const tryCompress = () => {
                    const dataUrl = canvas.toDataURL('image/jpeg', quality);
                    const sizeKB = (dataUrl.length * 0.75) / 1024; // base64 is ~33% larger
                    console.log(`Compressed: ${sizeKB.toFixed(0)}KB at quality ${quality}`);
                    
                    if (sizeKB > maxSizeKB && quality > 0.2) {
                        quality -= 0.1;
                        tryCompress();
                    } else {
                        resolve(dataUrl);
                    }
                };
                
                tryCompress();
            };
            img.onerror = reject;
            img.src = e.target.result;
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// Upload image via API (bypass nginx limit)
async function uploadImageViaAPI(richMenuId, base64Data) {
    const response = await fetch('api/rich-menu-upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            richMenuId: richMenuId,
            imageData: base64Data
        })
    });
    
    const result = await response.json();
    if (!result.success) {
        throw new Error(result.error || 'Upload failed');
    }
    return result;
}

// Handle image selection - compress immediately
async function handleImageSelect(input) {
    if (input.files && input.files[0]) {
        const height = parseInt(document.getElementById('sizeHeight').value) || 1686;
        const infoDiv = document.getElementById('imageInfo');
        
        infoDiv.innerHTML = '<span class="text-blue-500"><i class="fas fa-spinner fa-spin"></i> กำลังบีบอัดรูป...</span>';
        
        try {
            pendingImageBase64 = await compressImageToBase64(input.files[0], 2500, height, 800);
            const sizeKB = (pendingImageBase64.length * 0.75) / 1024;
            infoDiv.innerHTML = `<span class="text-green-500">✓ บีบอัดแล้ว: ${sizeKB.toFixed(0)}KB</span>`;
            
            // Preview
            document.getElementById('previewImage').src = pendingImageBase64;
            document.getElementById('previewImage').classList.remove('hidden');
        } catch (err) {
            infoDiv.innerHTML = `<span class="text-red-500">✗ Error: ${err.message}</span>`;
            pendingImageBase64 = null;
        }
    }
}

// Handle form submit - create menu then upload image via API
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('richMenuForm');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            try {
                // Step 1: Create Rich Menu (without image)
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังสร้าง Rich Menu...';
                submitBtn.disabled = true;
                
                const formData = new FormData(form);
                formData.delete('image'); // Remove image from form
                formData.set('skip_image', '1'); // Flag to skip image upload
                
                const createResponse = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });
                
                const responseText = await createResponse.text();
                
                // Extract richMenuId from response if available
                const richMenuIdMatch = responseText.match(/richMenuId['":\s]+([a-zA-Z0-9-]+)/);
                
                // Step 2: Upload image via API if we have pending image
                if (pendingImageBase64 && richMenuIdMatch) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังอัพโหลดรูป...';
                    await uploadImageViaAPI(richMenuIdMatch[1], pendingImageBase64);
                }
                
                // Redirect
                window.location.href = 'rich-menu.php?tab=static';
                
            } catch (err) {
                console.error('Error:', err);
                alert('เกิดข้อผิดพลาด: ' + err.message);
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    }
});

// Standalone upload for existing menus
async function uploadImageToMenu(menuId, lineRichMenuId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/png,image/jpeg';
    
    input.onchange = async function() {
        if (this.files && this.files[0]) {
            const btn = document.querySelector(`[data-menu-id="${menuId}"]`);
            const originalText = btn ? btn.innerHTML : '';
            
            try {
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    btn.disabled = true;
                }
                
                // Compress
                const base64 = await compressImageToBase64(this.files[0], 2500, 1686, 800);
                
                // Upload via API
                await uploadImageViaAPI(lineRichMenuId, base64);
                
                alert('อัพโหลดรูปสำเร็จ!');
                window.location.reload();
                
            } catch (err) {
                alert('เกิดข้อผิดพลาด: ' + err.message);
                if (btn) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }
        }
    };
    
    input.click();
}

function openModal(isEdit = false) {
    pendingImageBase64 = null;
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    
    if (!isEdit) {
        // Reset form for new menu
        editingMenuId = null;
        document.getElementById('richMenuForm').querySelector('[name="action"]').value = 'create';
        document.getElementById('richMenuForm').querySelector('[name="name"]').value = '';
        document.getElementById('richMenuForm').querySelector('[name="chat_bar_text"]').value = '';
        document.getElementById('sizeHeight').value = '1686';
        document.getElementById('previewImage').src = '';
        document.getElementById('previewImage').classList.add('hidden');
        document.getElementById('modalTitle').textContent = '🎨 สร้าง Rich Menu';
        document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>สร้าง Rich Menu';
        
        // Show/hide menu_id field
        const menuIdField = document.getElementById('menuIdField');
        if (menuIdField) menuIdField.classList.add('hidden');
        
        areas = [];
        updateAreasDisplay();
    }
    
    updateCanvasSize();
}

function editRichMenu(id, name, chatBarText, sizeHeight, areasJson) {
    editingMenuId = id;
    
    // Set form values
    document.getElementById('richMenuForm').querySelector('[name="action"]').value = 'update';
    document.getElementById('richMenuForm').querySelector('[name="name"]').value = name;
    document.getElementById('richMenuForm').querySelector('[name="chat_bar_text"]').value = chatBarText;
    document.getElementById('sizeHeight').value = sizeHeight || 1686;
    
    // Set menu_id
    let menuIdInput = document.getElementById('menuIdInput');
    if (!menuIdInput) {
        menuIdInput = document.createElement('input');
        menuIdInput.type = 'hidden';
        menuIdInput.name = 'menu_id';
        menuIdInput.id = 'menuIdInput';
        document.getElementById('richMenuForm').appendChild(menuIdInput);
    }
    menuIdInput.value = id;
    
    // Update modal title
    document.getElementById('modalTitle').textContent = '✏️ แก้ไข Rich Menu';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save mr-2"></i>บันทึกการแก้ไข';
    
    // Parse areas
    try {
        areas = JSON.parse(areasJson) || [];
        // Add labels if missing
        areas = areas.map((area, i) => ({
            ...area,
            label: area.label || 'ปุ่ม ' + (i + 1)
        }));
    } catch (e) {
        areas = [];
    }
    
    openModal(true);
    updateAreasDisplay();
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
    editingMenuId = null;
}

function updateCanvasSize() {
    canvasHeight = parseInt(document.getElementById('sizeHeight').value);
    const container = document.getElementById('canvasContainer');
    const aspectRatio = canvasHeight / CANVAS_WIDTH;
    container.style.paddingBottom = (aspectRatio * 100) + '%';
    updateAreasDisplay();
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.getElementById('previewImage');
            img.src = e.target.result;
            img.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function applyTemplate(rows, cols) {
    canvasHeight = parseInt(document.getElementById('sizeHeight').value);
    areas = [];
    
    const cellWidth = Math.floor(CANVAS_WIDTH / cols);
    const cellHeight = Math.floor(canvasHeight / rows);
    
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            areas.push({
                bounds: {
                    x: c * cellWidth,
                    y: r * cellHeight,
                    width: cellWidth,
                    height: cellHeight
                },
                action: { type: 'message', text: '' },
                label: `ปุ่ม ${r * cols + c + 1}`
            });
        }
    }
    updateAreasDisplay();
}

function updateAreasDisplay() {
    const overlay = document.getElementById('areasOverlay');
    const list = document.getElementById('areasList');
    
    // Update overlay
    overlay.innerHTML = areas.map((area, i) => {
        const left = (area.bounds.x / CANVAS_WIDTH * 100) + '%';
        const top = (area.bounds.y / canvasHeight * 100) + '%';
        const width = (area.bounds.width / CANVAS_WIDTH * 100) + '%';
        const height = (area.bounds.height / canvasHeight * 100) + '%';
        const hasAction = area.action.text || area.action.uri || area.action.data || area.action.richMenuAliasId;
        const bgColor = hasAction ? 'rgba(34, 197, 94, 0.3)' : 'rgba(59, 130, 246, 0.2)';
        const borderColor = hasAction ? '#22c55e' : '#3b82f6';
        
        return `<div onclick="editArea(${i})" class="absolute cursor-pointer flex items-center justify-center text-white text-xs font-bold hover:opacity-80 transition" 
            style="left:${left};top:${top};width:${width};height:${height};background:${bgColor};border:2px solid ${borderColor}">
            <span class="bg-black bg-opacity-50 px-2 py-1 rounded">${area.label || 'คลิกตั้งค่า'}</span>
        </div>`;
    }).join('');
    
    // Update list
    list.innerHTML = areas.map((area, i) => {
        const actionText = area.action.text || area.action.uri || area.action.data || area.action.richMenuAliasId || '(ยังไม่ตั้งค่า)';
        const typeIcon = area.action.type === 'uri' ? '🔗' : area.action.type === 'postback' ? '📤' : area.action.type === 'richmenuswitch' ? '🔄' : '📝';
        return `<div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
            <div class="flex items-center">
                <span class="w-6 h-6 bg-green-500 text-white rounded text-xs flex items-center justify-center mr-2">${i+1}</span>
                <span class="text-sm">${typeIcon} ${area.label || 'ปุ่ม ' + (i+1)}: <span class="text-gray-500">${actionText}</span></span>
            </div>
            <div class="flex space-x-1">
                <button type="button" onclick="editArea(${i})" class="p-1 text-blue-500 hover:bg-blue-50 rounded"><i class="fas fa-edit"></i></button>
                <button type="button" onclick="deleteArea(${i})" class="p-1 text-red-500 hover:bg-red-50 rounded"><i class="fas fa-trash"></i></button>
            </div>
        </div>`;
    }).join('');
    
    // Update hidden input
    const areasForApi = areas.map(a => ({
        bounds: a.bounds,
        action: a.action
    }));
    document.getElementById('areasJson').value = JSON.stringify(areasForApi);
}

function editArea(index) {
    currentAreaIndex = index;
    const area = areas[index];
    
    document.getElementById('actionType').value = area.action.type || 'message';
    document.getElementById('actionText').value = area.action.text || '';
    document.getElementById('actionUri').value = area.action.uri || '';
    document.getElementById('actionData').value = area.action.data || '';
    document.getElementById('actionAlias').value = area.action.richMenuAliasId || '';
    document.getElementById('actionLabel').value = area.label || '';
    
    toggleActionFields();
    
    document.getElementById('areaModal').classList.remove('hidden');
    document.getElementById('areaModal').classList.add('flex');
}

function closeAreaModal() {
    document.getElementById('areaModal').classList.add('hidden');
    document.getElementById('areaModal').classList.remove('flex');
    currentAreaIndex = -1;
}

function toggleActionFields() {
    const type = document.getElementById('actionType').value;
    document.getElementById('messageField').classList.toggle('hidden', type !== 'message');
    document.getElementById('uriField').classList.toggle('hidden', type !== 'uri');
    document.getElementById('postbackField').classList.toggle('hidden', type !== 'postback');
    document.getElementById('switchField').classList.toggle('hidden', type !== 'richmenuswitch');
}

function saveAreaAction() {
    if (currentAreaIndex < 0) return;
    
    const type = document.getElementById('actionType').value;
    const label = document.getElementById('actionLabel').value;
    
    let action = { type };
    if (type === 'message') {
        action.text = document.getElementById('actionText').value;
    } else if (type === 'uri') {
        action.uri = document.getElementById('actionUri').value;
    } else if (type === 'postback') {
        action.data = document.getElementById('actionData').value;
    } else if (type === 'richmenuswitch') {
        action.richMenuAliasId = document.getElementById('actionAlias').value;
    }
    
    areas[currentAreaIndex].action = action;
    areas[currentAreaIndex].label = label || areas[currentAreaIndex].label;
    
    updateAreasDisplay();
    closeAreaModal();
}

function deleteArea(index) {
    if (confirm('ลบพื้นที่นี้?')) {
        areas.splice(index, 1);
        updateAreasDisplay();
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateCanvasSize();
});

// Validate image size
function validateImage(input) {
    const file = input.files[0];
    if (!file) return;
    
    const targetHeight = parseInt(document.getElementById('sizeHeight').value);
    const img = new Image();
    
    img.onload = function() {
        const infoDiv = document.getElementById('imageInfo');
        const isCorrectSize = (img.width === 2500 && img.height === targetHeight);
        
        if (isCorrectSize) {
            infoDiv.innerHTML = `<span class="text-green-600">✅ ขนาดถูกต้อง: ${img.width} x ${img.height} px</span>`;
        } else {
            infoDiv.innerHTML = `<span class="text-yellow-600">⚠️ ขนาด ${img.width} x ${img.height} px จะถูก resize เป็น 2500 x ${targetHeight} px อัตโนมัติ</span>`;
        }
    };
    
    img.src = URL.createObjectURL(file);
}

// Form validation
document.getElementById('richMenuForm').addEventListener('submit', function(e) {
    if (areas.length === 0) {
        e.preventDefault();
        alert('กรุณาเลือกเทมเพลตหรือสร้างพื้นที่คลิกอย่างน้อย 1 พื้นที่');
        return false;
    }
    
    const hasEmptyAction = areas.some(a => !a.action.text && !a.action.uri && !a.action.data && !a.action.richMenuAliasId);
    if (hasEmptyAction) {
        e.preventDefault();
        alert('กรุณาตั้งค่า Action ให้ครบทุกพื้นที่');
        return false;
    }
});
</script>

<style>
#canvasContainer {
    position: relative;
    width: 100%;
    padding-bottom: 67.44%; /* 1686/2500 */
    background: linear-gradient(45deg, #f0f0f0 25%, transparent 25%), 
                linear-gradient(-45deg, #f0f0f0 25%, transparent 25%), 
                linear-gradient(45deg, transparent 75%, #f0f0f0 75%), 
                linear-gradient(-45deg, transparent 75%, #f0f0f0 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
}
#previewImage {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
