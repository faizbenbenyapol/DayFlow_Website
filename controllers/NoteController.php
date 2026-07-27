<?php
// =====================================================
// controllers/NoteController.php
// =====================================================

require_once ROOT . '/models/Note.php';
require_once ROOT . '/models/NoteBlock.php';
require_once ROOT . '/core/TelegramService.php';

class NoteController
{
    private const BLOCK_TYPES = ['text', 'link', 'checklist'];

    private function validateTitle($title): string
    {
        $title = trim((string)$title);
        if ($title === '') $title = 'ไม่มีชื่อ';
        if (mb_strlen($title) > 255) Response::json(['error' => 'ชื่อโน้ตยาวเกิน 255 ตัวอักษร'], 422);
        return $title;
    }

    private function validateBlock(string $type, $content): array
    {
        if (!in_array($type, self::BLOCK_TYPES, true)) Response::json(['error' => 'ประเภทบล็อกไม่ถูกต้อง'], 422);
        if (is_array($content) || is_object($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $content = (string)$content;
        if (mb_strlen($content) > 1000000) Response::json(['error' => 'เนื้อหาโน้ตใหญ่เกินไป'], 422);
        return [$type, $content];
    }

    public function index(): void
    {
        $pageTitle  = 'โน้ต';
        $pageStyle  = 'notes';
        $pageScript = 'notes';

        $userId = Auth::userId();
        $tags   = Note::getUserTags($userId);

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/notes/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function editor(string $id): void
    {
        $pageTitle  = 'แก้ไขโน้ต';
        $pageStyle  = 'notes';
        $pageScript = 'notes';

        $userId = Auth::userId();
        $note   = Note::getById((int)$id, $userId);

        if (!$note) Response::abort(404, 'ไม่พบโน้ต');

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/notes/editor.php';
        require ROOT . '/views/layout/footer.php';
    }

    // --- API ---

    public function apiList(): void
    {
        $userId = Auth::userId();
        $search = Request::query('search', '');
        $tagId  = (int)Request::query('tag', 0);
        $notes  = Note::listForUser($userId, $search, $tagId);
        Response::json(['notes' => $notes]);
    }

    public function apiCreate(): void
    {
        $userId    = Auth::userId();
        $title     = Request::input('title', 'ไม่มีชื่อ');
        $title     = $this->validateTitle($title);
        $encrypted = (bool)Request::input('is_encrypted', false);
        $password  = Request::rawInput('password', '');

        if ($encrypted && empty($password)) {
            Response::json(['error' => 'กรุณากำหนดรหัสผ่านสำหรับโน้ตที่เข้ารหัส'], 422);
        }

        $id = Note::create($userId, $title, $encrypted, $password);
        $note = Note::getById($id, $userId);
        $msg = TelegramService::formatMessage(
            "📝 โน้ตใหม่ถูกสร้างขึ้น",
            [
                'หัวข้อ' => htmlspecialchars($title),
                'ประเภท' => $encrypted ? 'เข้ารหัส' : 'ปกติ'
            ]
        );
        TelegramService::sendNotification($userId, 'note', $msg);

        Response::json(['ok' => true, 'note' => $note], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $noteId = (int)$id;
        $note   = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        $data = [];
        $body = Request::json();
        if (array_key_exists('title', $body))  $data['title']  = $this->validateTitle($body['title']);
        if (array_key_exists('pinned', $body)) $data['pinned'] = !empty($body['pinned']) ? 1 : 0;

        Note::update($noteId, $userId, $data);

        // Sync tags
        if (array_key_exists('tags', $body)) {
            Note::syncTags($noteId, (array)$body['tags'], $userId);
        }

        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Note::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบโน้ต'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiVerify(string $id): void
    {
        $userId   = Auth::userId();
        $noteId   = (int)$id;
        $note     = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        $password = Request::rawInput('password', '');
        if (!Note::verifyPassword($note, $password)) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        // Return decrypted blocks
        $blocks = NoteBlock::getForNote($noteId);
        foreach ($blocks as &$block) {
            $block['content'] = NoteBlock::decrypt($block['content'], $password, $note['encrypt_salt']) ?? '';
        }
        Response::json(['ok' => true, 'blocks' => $blocks]);
    }

    public function apiTags(): void
    {
        $userId = Auth::userId();
        Response::json(['tags' => Note::getUserTags($userId)]);
    }

    public function apiTagCreate(): void
    {
        $userId = Auth::userId();
        $name   = Request::input('name', '');
        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อแท็ก'], 422);

        $id = NoteTag::create($userId, $name);
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function apiTagUpdate(string $id): void
    {
        $userId = Auth::userId();
        $name   = Request::input('name', '');
        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อแท็ก'], 422);

        if (!NoteTag::update((int)$id, $userId, $name)) {
            Response::json(['error' => 'ชื่อแท็กนี้มีอยู่แล้ว'], 422);
        }
        Response::json(['ok' => true]);
    }

    public function apiTagDelete(string $id): void
    {
        $userId = Auth::userId();
        NoteTag::delete((int)$id, $userId);
        Response::json(['ok' => true]);
    }

    // --- Block endpoints ---

    public function apiBlocksList(string $id): void
    {
        $userId = Auth::userId();
        $noteId = (int)$id;
        $note   = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        if ($note['is_encrypted']) {
            Response::json(['error' => 'โน้ตนี้เข้ารหัสอยู่ กรุณาใส่รหัสผ่านก่อน'], 403);
        }

        $blocks = NoteBlock::getForNote($noteId);
        Response::json(['blocks' => $blocks]);
    }

    public function apiBlockCreate(string $id): void
    {
        $userId  = Auth::userId();
        $noteId  = (int)$id;
        $note    = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        [$type, $content] = $this->validateBlock(
            (string)Request::input('type', 'text'),
            Request::rawInput('content', '')
        );

        if ($note['is_encrypted']) {
            $password = Request::rawInput('password', '');
            if ($password === '') Response::json(['error' => 'กรุณาใส่รหัสผ่านของโน้ต'], 422);
            $content  = NoteBlock::encrypt($content, $password, $note['encrypt_salt']);
        }

        $bid = NoteBlock::create($noteId, $type, $content);
        Response::json(['ok' => true, 'id' => $bid], 201);
    }

    public function apiBlockUpdate(string $id, string $bid): void
    {
        $userId  = Auth::userId();
        $noteId  = (int)$id;
        $blockId = (int)$bid;
        $note    = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        [$type, $content] = $this->validateBlock(
            (string)Request::input('type', 'text'),
            Request::rawInput('content', '')
        );

        if ($note['is_encrypted']) {
            $password = Request::rawInput('password', '');
            if ($password === '') Response::json(['error' => 'กรุณาใส่รหัสผ่านของโน้ต'], 422);
            $content  = NoteBlock::encrypt($content, $password, $note['encrypt_salt']);
        }

        NoteBlock::update($blockId, $noteId, $type, $content);
        Response::json(['ok' => true]);
    }

    public function apiBlockDelete(string $id, string $bid): void
    {
        $userId  = Auth::userId();
        $noteId  = (int)$id;
        $blockId = (int)$bid;
        $note    = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        NoteBlock::delete($blockId, $noteId);
        Response::json(['ok' => true]);
    }

    public function apiBlockReorder(string $id): void
    {
        $userId = Auth::userId();
        $noteId = (int)$id;
        $note   = Note::getById($noteId, $userId);
        if (!$note) Response::json(['error' => 'ไม่พบโน้ต'], 404);

        $items = Request::json()['items'] ?? [];
        NoteBlock::reorder($noteId, $items);
        Response::json(['ok' => true]);
    }
}
