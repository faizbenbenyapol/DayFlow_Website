<?php

require_once ROOT . '/models/Habit.php';

class HabitController
{
    public function index(): void
    {
        $pageTitle = 'นิสัยประจำวัน';
        $pageStyle = 'habits';
        $pageScript = 'habits';
        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/habits/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiList(): void
    {
        Response::json(['habits' => Habit::listForUser(Auth::userId())]);
    }

    public function apiCreate(): void
    {
        $name = trim((string)Request::input('name', ''));
        $color = trim((string)Request::input('color', '#6366f1'));
        $target = max(1, min(7, (int)Request::input('target_days', 7)));
        if ($name === '' || mb_strlen($name) > 160) Response::json(['error' => 'กรุณากรอกชื่อนิสัยไม่เกิน 160 ตัวอักษร'], 422);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
        Response::json(['ok' => true, 'id' => Habit::create(Auth::userId(), $name, $color, $target)], 201);
    }

    public function apiUpdate(string $id): void
    {
        $name = trim((string)Request::input('name', ''));
        $color = trim((string)Request::input('color', '#6366f1'));
        $target = max(1, min(7, (int)Request::input('target_days', 7)));
        if ($name === '' || mb_strlen($name) > 160) Response::json(['error' => 'ข้อมูลนิสัยไม่ถูกต้อง'], 422);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
        if (!Habit::update((int)$id, Auth::userId(), $name, $color, $target)) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }

    public function apiToggle(string $id): void
    {
        if (!Habit::get((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true, 'completed_today' => Habit::toggleToday((int)$id, Auth::userId())]);
    }

    public function apiDelete(string $id): void
    {
        if (!Habit::archive((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }
}
