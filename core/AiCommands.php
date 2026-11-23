<?php
// =====================================================
// core/AiCommands.php — ทะเบียนคำสั่งที่ AI สั่งแอปได้ (หนึ่งไฟล์ต่อหนึ่งคำสั่ง)
// โหลดทุกไฟล์ใน core/ai-commands/ ครั้งเดียวแล้วจำไว้ และมีตัวตรวจ args ที่ใช้ร่วมกัน
// =====================================================

final class AiCommands
{
    /** @var array<string, array<string, mixed>>|null ผลที่โหลดแล้ว */
    private static ?array $cache = null;

    /**
     * คำสั่งทุกตัว เรียงตามชื่อไฟล์
     *
     * @return array<string, array<string, mixed>> ชื่อคำสั่ง => คำนิยาม
     * @throws LogicException ไฟล์คำสั่งไม่ครบคีย์ หรือ name ไม่ตรงชื่อไฟล์
     */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        $out = [];
        foreach (glob(__DIR__ . '/ai-commands/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $cmd  = require $file;
            if (!is_array($cmd)) {
                throw new LogicException('ไฟล์คำสั่งต้อง return array: ' . $name);
            }
            foreach (['name', 'description', 'args', 'confirm', 'run'] as $key) {
                if (!array_key_exists($key, $cmd)) {
                    throw new LogicException("คำสั่ง {$name} ขาดคีย์ {$key}");
                }
            }
            if ($cmd['name'] !== $name) {
                throw new LogicException("คำสั่ง {$name} ประกาศชื่อ {$cmd['name']} ไม่ตรงกับไฟล์");
            }
            if (!is_string($cmd['description']) || !is_array($cmd['args'])
                || !is_bool($cmd['confirm']) || !is_callable($cmd['run'])) {
                throw new LogicException("คำสั่ง {$name} มีคีย์ผิดชนิด");
            }
            if ($cmd['confirm'] === true && !is_callable($cmd['preview'] ?? null)) {
                throw new LogicException("คำสั่ง {$name} ต้องมี preview เพราะต้องให้ผู้ใช้ยืนยัน");
            }
            $out[$name] = $cmd;
        }
        ksort($out);
        return self::$cache = $out;
    }

    /**
     * คำนิยามของคำสั่ง $name หรือ null เมื่อไม่มี
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * ข้อความที่ต้องมี ห้ามว่างและยาวเกิน $max
     *
     * @param array<string, mixed> $args
     * @throws AiCommandFailed
     */
    public static function requireText(array $args, string $key, int $max): string
    {
        $value = $args[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new AiCommandFailed('ต้องระบุ ' . $key);
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new AiCommandFailed($key . ' ยาวเกิน ' . $max . ' ตัวอักษร');
        }
        return $value;
    }

    /**
     * วันที่ Y-m-d แบบเข้มงวด ค่าว่างใช้ $default (null = ไม่มีวันที่)
     *
     * @param array<string, mixed> $args
     * @throws AiCommandFailed
     */
    public static function optionalDate(array $args, string $key, ?string $default = null): ?string
    {
        $value = $args[$key] ?? '';
        if (!is_string($value)) throw new AiCommandFailed('รูปแบบวันที่ไม่ถูกต้อง');
        $value = trim($value);
        if ($value === '') return $default;
        $parsed = DateTime::createFromFormat('Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new AiCommandFailed('รูปแบบวันที่ไม่ถูกต้อง');
        }
        return $value;
    }

    /**
     * เดือน YYYY-MM แบบเข้มงวด ค่าว่างใช้ $default
     *
     * @param array<string, mixed> $args
     * @throws AiCommandFailed
     */
    public static function optionalMonth(array $args, string $key, string $default): string
    {
        $value = $args[$key] ?? '';
        if (!is_string($value)) throw new AiCommandFailed('รูปแบบเดือนไม่ถูกต้อง');
        $value = trim($value);
        if ($value === '') return $default;
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw new AiCommandFailed('รูปแบบเดือนไม่ถูกต้อง');
        }
        return $value;
    }

    /**
     * ข้อความที่เว้นว่างได้ (ตัดช่องว่างหัวท้าย) ห้ามยาวเกิน $max
     *
     * @param array<string, mixed> $args
     * @throws AiCommandFailed
     */
    public static function optionalText(array $args, string $key, int $max): string
    {
        $value = $args[$key] ?? '';
        if (!is_string($value)) throw new AiCommandFailed($key . ' ต้องเป็นข้อความ');
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new AiCommandFailed($key . ' ยาวเกิน ' . $max . ' ตัวอักษร');
        }
        return $value;
    }

    /**
     * รหัสแถวที่เป็นจำนวนเต็มบวก
     *
     * @param array<string, mixed> $args
     * @throws AiCommandFailed
     */
    public static function requireId(array $args, string $key): int
    {
        $value = $args[$key] ?? null;
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && ctype_digit(trim($value)) && (int)$value > 0) {
            return (int)trim($value);
        }
        throw new AiCommandFailed('ต้องระบุ ' . $key . ' ของรายการ');
    }
}

/** คำสั่งที่รันไม่ได้ด้วยข้อมูลที่ AI ส่งมา ข้อความคือสิ่งที่ส่งกลับให้ AI/ผู้ใช้เห็น */
class AiCommandFailed extends RuntimeException
{
}
