<?php
/**
 * CustomerNoteService - จัดการ Customer Notes สำหรับ Inbox Chat
 * 
 * Requirements: 4.5 - Admin can add notes and display to other admins
 */

class CustomerNoteService {
    private $db;
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    /**
     * Add a new customer note
     * Requirements: 4.5 - When an admin adds a note, save it and display to other admins
     * 
     * @param int $userId Customer user ID
     * @param int $adminId Admin user ID who created the note
     * @param string $note Note content
     * @param bool $isPinned Whether the note is pinned
     * @return int Note ID
     * @throws InvalidArgumentException
     */
    public function addNote(int $userId, int $adminId, string $note, bool $isPinned = false): int {
        $note = trim($note);
        
        if (empty($note)) {
            throw new InvalidArgumentException('Note content is required');
        }
        
        if ($userId <= 0) {
            throw new InvalidArgumentException('Valid user ID is required');
        }
        
        if ($adminId <= 0) {
            throw new InvalidArgumentException('Valid admin ID is required');
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO customer_notes 
            (user_id, admin_id, note, is_pinned)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $adminId,
            $note,
            $isPinned ? 1 : 0
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get all notes for a customer
     * Requirements: 4.4 - Show customer notes from other admins
     * 
     * @param int $userId Customer user ID
     * @return array Notes with admin info, ordered by pinned first then by date
     */
    public function getNotes(int $userId): array {
        $sql = "SELECT cn.id, cn.user_id, cn.admin_id, cn.note, cn.is_pinned,
                       cn.created_at, cn.updated_at,
                       au.name as admin_name
                FROM customer_notes cn
                LEFT JOIN admin_users au ON cn.admin_id = au.id
                WHERE cn.user_id = ?
                ORDER BY cn.is_pinned DESC, cn.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    
    /**
     * Get a single note by ID
     * 
     * @param int $id Note ID
     * @return array|null
     */
    public function getById(int $id): ?array {
        $sql = "SELECT cn.id, cn.user_id, cn.admin_id, cn.note, cn.is_pinned,
                       cn.created_at, cn.updated_at,
                       au.name as admin_name
                FROM customer_notes cn
                LEFT JOIN admin_users au ON cn.admin_id = au.id
                WHERE cn.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Update an existing note
     * Requirements: 4.5 - Manage customer notes
     * 
     * @param int $id Note ID
     * @param array $data Updated data (note, is_pinned)
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updateNote(int $id, array $data): bool {
        $existingNote = $this->getById($id);
        if (!$existingNote) {
            return false;
        }
        
        $fields = [];
        $params = [];
        
        if (isset($data['note'])) {
            $note = trim($data['note']);
            if (empty($note)) {
                throw new InvalidArgumentException('Note content cannot be empty');
            }
            $fields[] = 'note = ?';
            $params[] = $note;
        }
        
        if (isset($data['is_pinned'])) {
            $fields[] = 'is_pinned = ?';
            $params[] = $data['is_pinned'] ? 1 : 0;
        }
        
        if (empty($fields)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        
        $sql = "UPDATE customer_notes SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete a note
     * Requirements: 4.5 - Manage customer notes
     * 
     * @param int $id Note ID
     * @return bool
     */
    public function deleteNote(int $id): bool {
        $existingNote = $this->getById($id);
        if (!$existingNote) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM customer_notes WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Get pinned notes for a customer
     * 
     * @param int $userId Customer user ID
     * @return array Pinned notes
     */
    public function getPinnedNotes(int $userId): array {
        $sql = "SELECT cn.id, cn.user_id, cn.admin_id, cn.note, cn.is_pinned,
                       cn.created_at, cn.updated_at,
                       au.name as admin_name
                FROM customer_notes cn
                LEFT JOIN admin_users au ON cn.admin_id = au.id
                WHERE cn.user_id = ? AND cn.is_pinned = 1
                ORDER BY cn.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get notes by admin
     * 
     * @param int $adminId Admin user ID
     * @return array Notes created by this admin
     */
    public function getNotesByAdmin(int $adminId): array {
        $sql = "SELECT cn.id, cn.user_id, cn.admin_id, cn.note, cn.is_pinned,
                       cn.created_at, cn.updated_at,
                       u.display_name as customer_name
                FROM customer_notes cn
                LEFT JOIN users u ON cn.user_id = u.id
                WHERE cn.admin_id = ?
                ORDER BY cn.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Toggle pin status of a note
     * 
     * @param int $id Note ID
     * @return bool
     */
    public function togglePin(int $id): bool {
        $existingNote = $this->getById($id);
        if (!$existingNote) {
            return false;
        }
        
        $newPinStatus = $existingNote['is_pinned'] ? 0 : 1;
        
        $stmt = $this->db->prepare("UPDATE customer_notes SET is_pinned = ? WHERE id = ?");
        return $stmt->execute([$newPinStatus, $id]);
    }
    
    /**
     * Get note count for a customer
     * 
     * @param int $userId Customer user ID
     * @return int
     */
    public function getCount(int $userId): int {
        $sql = "SELECT COUNT(*) FROM customer_notes WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Search notes for a customer
     * 
     * @param int $userId Customer user ID
     * @param string $search Search query
     * @return array Matching notes
     */
    public function searchNotes(int $userId, string $search): array {
        $sql = "SELECT cn.id, cn.user_id, cn.admin_id, cn.note, cn.is_pinned,
                       cn.created_at, cn.updated_at,
                       au.name as admin_name
                FROM customer_notes cn
                LEFT JOIN admin_users au ON cn.admin_id = au.id
                WHERE cn.user_id = ? AND cn.note LIKE ?
                ORDER BY cn.is_pinned DESC, cn.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, '%' . $search . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Delete all notes for a customer
     * 
     * @param int $userId Customer user ID
     * @return int Number of deleted notes
     */
    public function deleteAllForUser(int $userId): int {
        $stmt = $this->db->prepare("DELETE FROM customer_notes WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
}
