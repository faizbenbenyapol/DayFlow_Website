<?php
// =====================================================
// controllers/ExerciseController.php
// =====================================================

require_once ROOT . '/models/Workout.php';

class ExerciseController
{
    public function index(): void
    {
        $pageTitle  = 'ออกกำลังกาย';
        $pageStyle  = 'exercise';
        $pageScript = 'exercise';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/exercise/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        $userId = Auth::userId();
        $limit  = (int)Request::query('limit', 50);
        $month  = Request::query('month', '');
        $data   = Workout::listForUser($userId, $limit, $month);
        Response::json(['workouts' => $data]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id      = Workout::create($userId, $data);
        $workout = Workout::getById($id, $userId);
        Response::json(['ok' => true, 'workout' => $workout], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId    = Auth::userId();
        $workoutId = (int)$id;
        $w         = Workout::getById($workoutId, $userId);
        if (!$w) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        Workout::update($workoutId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Workout::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    public function apiStats(): void
    {
        $userId = Auth::userId();
        Response::json(Workout::getStats($userId));
    }

    public function apiCategoriesList(): void
    {
        $userId = Auth::userId();
        Response::json(['categories' => ExerciseCategory::listForUser($userId)]);
    }

    public function apiCategoryCreate(): void
    {
        $userId = Auth::userId();
        $name = trim(Request::input('name', ''));
        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อหมวดหมู่'], 422);

        $id = ExerciseCategory::create($userId, $name);
        Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function apiCategoryUpdate(string $id): void
    {
        $userId = Auth::userId();
        $name = trim(Request::input('name', ''));
        if (!$name) Response::json(['error' => 'กรุณากรอกชื่อหมวดหมู่'], 422);

        $ok = ExerciseCategory::update((int)$id, $userId, $name);
        Response::json(['ok' => $ok]);
    }

    public function apiCategoryDelete(string $id): void
    {
        $userId = Auth::userId();
        $ok = ExerciseCategory::delete((int)$id, $userId);
        Response::json(['ok' => $ok]);
    }

    private function validateData(): array
    {
        $type = Request::input('type', '');
        $date = Request::input('workout_date', date('Y-m-d'));

        if (!$type) return ['error' => 'กรุณากรอกประเภทการออกกำลังกาย'];
        if (!$date) return ['error' => 'กรุณากรอกวันที่'];

        return [
            'workout_date' => $date,
            'type'         => $type,
            'duration_min' => Request::input('duration_min', ''),
            'sets'         => Request::input('sets', ''),
            'reps'         => Request::input('reps', ''),
            'weight_kg'    => Request::input('weight_kg', ''),
            'notes'        => Request::input('notes', ''),
        ];
    }
}
