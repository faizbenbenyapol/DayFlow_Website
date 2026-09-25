-- Exercise categories. Until now models/Workout.php created this table with a
-- CREATE TABLE IF NOT EXISTS on every categories request.
CREATE TABLE IF NOT EXISTS `exercise_categories` (
  `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name`    VARCHAR(80) NOT NULL,
  UNIQUE KEY `uq_user_ex_cat` (`user_id`, `name`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
