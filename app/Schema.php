<?php
declare(strict_types=1);
namespace School;
final class Schema {
    public static function migrate(Database $db): void {
        $id = $db->sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT PRIMARY KEY AUTO_INCREMENT';
        $int = $db->sqlite ? 'INTEGER' : 'BIGINT';
        $binary = $db->sqlite ? '' : ' COLLATE utf8mb4_bin';
        $tail = $db->sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $tables = [
            'schools'=>"id $id, name VARCHAR(180) NOT NULL, active SMALLINT NOT NULL DEFAULT 1, secret_encrypted TEXT NOT NULL, created_at DATETIME NOT NULL",
            'users'=>"id $id, school_id $int NOT NULL, external_id VARCHAR(128)$binary NOT NULL, role VARCHAR(20) NOT NULL, name VARCHAR(180) NOT NULL, UNIQUE(school_id, external_id), FOREIGN KEY(school_id) REFERENCES schools(id)",
            'admins'=>"id $id, email VARCHAR(180) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL",
            'disciplines'=>"id $id, name VARCHAR(140) NOT NULL UNIQUE",
            'subjects'=>"id $id, discipline_id $int NOT NULL, name VARCHAR(180) NOT NULL, UNIQUE(discipline_id, name), FOREIGN KEY(discipline_id) REFERENCES disciplines(id)",
            'associated_texts'=>"id $id, author_id $int NOT NULL, title VARCHAR(180) NOT NULL, content TEXT NOT NULL, source TEXT NOT NULL, work_author VARCHAR(180) NOT NULL, locked_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(author_id) REFERENCES users(id)",
            'questions'=>"id $id, author_id $int NOT NULL, school_id $int NOT NULL, discipline_id $int NULL, subject_id $int NULL, text_id $int NULL, statement TEXT NOT NULL, correct_option VARCHAR(1) NULL, status VARCHAR(20) NOT NULL, locked_at DATETIME NULL, annulment_reason TEXT NULL, annulled_at DATETIME NULL, annulled_by $int NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(author_id) REFERENCES users(id), FOREIGN KEY(school_id) REFERENCES schools(id), FOREIGN KEY(discipline_id) REFERENCES disciplines(id), FOREIGN KEY(subject_id) REFERENCES subjects(id), FOREIGN KEY(text_id) REFERENCES associated_texts(id), FOREIGN KEY(annulled_by) REFERENCES users(id)",
            'alternatives'=>"question_id $int NOT NULL, label VARCHAR(1) NOT NULL, content TEXT NOT NULL, PRIMARY KEY(question_id,label), FOREIGN KEY(question_id) REFERENCES questions(id)",
            'resolutions'=>"id $id, school_id $int NOT NULL, student_id $int NOT NULL, question_id $int NOT NULL, attempt_id VARCHAR(128)$binary NOT NULL, activity_id VARCHAR(128) NULL, selected_option VARCHAR(1) NOT NULL, correct SMALLINT NOT NULL, content_hash VARCHAR(64) NOT NULL, answered_at DATETIME NOT NULL, UNIQUE(school_id, student_id, attempt_id), FOREIGN KEY(school_id) REFERENCES schools(id), FOREIGN KEY(student_id) REFERENCES users(id), FOREIGN KEY(question_id) REFERENCES questions(id)",
            'audit_log'=>"id $id, actor_id $int NULL, action VARCHAR(80) NOT NULL, entity VARCHAR(40) NOT NULL, entity_id $int NOT NULL, details TEXT NOT NULL, created_at DATETIME NOT NULL",
            'login_limits'=>"bucket VARCHAR(64) PRIMARY KEY, failures INTEGER NOT NULL, started_at INTEGER NOT NULL"
        ];
        foreach ($tables as $name=>$columns) $db->pdo->exec("CREATE TABLE IF NOT EXISTS $name ($columns)$tail");
        foreach (['questions_status_discipline'=>'questions(status,discipline_id,subject_id)', 'questions_author'=>'questions(author_id)', 'resolutions_student'=>'resolutions(student_id,answered_at)', 'questions_text'=>'questions(text_id)'] as $name=>$target) {
            $exists = $db->sqlite ? $db->one('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index',$name]) : $db->one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ?', [$name]);
            if (!$exists) $db->pdo->exec("CREATE INDEX $name ON $target");
        }
    }
}
