-- ProctorDesk schema (MySQL 8)
-- Run: mysql -u root -p proctordesk < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS cheat_logs;
DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS exam_attempts;
DROP TABLE IF EXISTS exam_students;
DROP TABLE IF EXISTS exam_files;
DROP TABLE IF EXISTS exam_questions;
DROP TABLE IF EXISTS exams;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(120)  NOT NULL,
  email           VARCHAR(190)  NOT NULL,
  password_hash   VARCHAR(255)  NOT NULL,
  role            ENUM('admin','instructor','student') NOT NULL,
  is_active       TINYINT(1)    NOT NULL DEFAULT 1,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login      DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exams (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title             VARCHAR(190) NOT NULL,
  subject           ENUM('math','english','coding','physics','chemistry','other') NOT NULL DEFAULT 'other',
  instructor_id     INT UNSIGNED NOT NULL,
  duration_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
  shuffle_options   TINYINT(1) NOT NULL DEFAULT 0,
  allow_back_nav    TINYINT(1) NOT NULL DEFAULT 1,
  max_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  pass_score        TINYINT UNSIGNED NOT NULL DEFAULT 50,
  instructions      TEXT NULL,
  starts_at         DATETIME NULL,
  ends_at           DATETIME NULL,
  status            ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  results_released  TINYINT(1) NOT NULL DEFAULT 0,
  cheat_threshold   TINYINT UNSIGNED NOT NULL DEFAULT 5,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exams_instructor (instructor_id),
  KEY idx_exams_status (status),
  KEY idx_exams_window (starts_at, ends_at),
  CONSTRAINT fk_exams_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_questions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id        INT UNSIGNED NOT NULL,
  type           ENUM('mcq','true_false','short','code','fill') NOT NULL,
  body           TEXT NOT NULL,
  option_a       TEXT NULL,
  option_b       TEXT NULL,
  option_c       TEXT NULL,
  option_d       TEXT NULL,
  correct_answer TEXT NULL,
  marks          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
  image_path     VARCHAR(255) NULL,
  language       VARCHAR(20) NULL,
  starter_code   TEXT NULL,
  word_limit     SMALLINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_questions_exam (exam_id, sort_order),
  CONSTRAINT fk_questions_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_students (
  exam_id     INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (exam_id, student_id),
  KEY idx_es_student (student_id),
  CONSTRAINT fk_es_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_es_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_attempts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id          INT UNSIGNED NOT NULL,
  student_id       INT UNSIGNED NOT NULL,
  started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at     DATETIME NULL,
  score            DECIMAL(5,2) NULL,
  passed           TINYINT(1) NULL,
  is_terminated    TINYINT(1) NOT NULL DEFAULT 0,
  cheat_flag_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_address       VARCHAR(45) NULL,
  user_agent       VARCHAR(255) NULL,
  question_order   TEXT NULL,
  last_heartbeat   DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_exam (exam_id),
  KEY idx_attempts_student (student_id),
  KEY idx_attempts_open (exam_id, student_id, submitted_at),
  CONSTRAINT fk_att_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attempt_answers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id      INT UNSIGNED NOT NULL,
  question_id     INT UNSIGNED NOT NULL,
  student_answer  TEXT NULL,
  is_correct      TINYINT(1) NULL,
  marks_awarded   DECIMAL(4,2) NULL,
  answered_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_answer (attempt_id, question_id),
  KEY idx_aa_question (question_id),
  CONSTRAINT fk_aa_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_aa_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cheat_logs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id  INT UNSIGNED NOT NULL,
  event_type  ENUM('tab_switch','focus_lost','copy_attempt','paste_attempt',
                   'right_click','devtools_open','window_blur','fullscreen_exit',
                   'multi_face','keyboard_shortcut') NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  extra_data  JSON NULL,
  PRIMARY KEY (id),
  KEY idx_cheat_attempt (attempt_id, occurred_at),
  KEY idx_cheat_type (event_type),
  CONSTRAINT fk_cheat_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id       INT UNSIGNED NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  stored_name   VARCHAR(190) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_files_exam (exam_id),
  CONSTRAINT fk_files_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address  VARCHAR(45) NOT NULL,
  email       VARCHAR(190) NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_ip_time (ip_address, attempted_at),
  KEY idx_login_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed admin: email admin@proctordesk.local / password Admin@1234
-- Hash generated with PHP password_hash('Admin@1234', PASSWORD_BCRYPT)
INSERT INTO users (name, email, password_hash, role, is_active)
VALUES ('Site Admin', 'admin@proctordesk.local',
        '$2y$12$nl3PTt9SPweWs..8SX4fIunUwG2Q.gnzQ1t6b29txFGihGilUdSuW',
        'admin', 1);
