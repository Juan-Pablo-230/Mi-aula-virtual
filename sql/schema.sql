CREATE DATABASE IF NOT EXISTS miaulavirtual CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE miaulavirtual;

CREATE TABLE instituciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL UNIQUE,
    direccion VARCHAR(300) NULL,          -- texto armado: calle altura, localidad, país
    pais VARCHAR(100) NULL,
    localidad VARCHAR(150) NULL,
    calle VARCHAR(150) NULL,
    altura INT UNSIGNED NULL,
    telefono VARCHAR(50) NULL,
    email VARCHAR(200) NULL,
    activo BOOLEAN DEFAULT TRUE,
    creada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ciclos_lectivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institucion_id INT NOT NULL,
    año INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    activo BOOLEAN DEFAULT FALSE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE,
    UNIQUE KEY unique_ciclo (institucion_id, año),
    KEY idx_ciclo_activo (institucion_id, activo)
);

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NULL,            -- NULL para familiares cargados solo con DNI
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('madre','directivo','secretario','docente','alumno','familiar') NOT NULL,
    institucion_id INT NULL,
    foto_perfil VARCHAR(255) DEFAULT NULL,
    primer_ingreso BOOLEAN DEFAULT TRUE,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_dni (dni),
    UNIQUE KEY unique_email (email),
    KEY idx_usuarios_institucion (institucion_id),
    CONSTRAINT fk_usuarios_institucion
        FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE SET NULL
);

CREATE TABLE familiares_alumnos (
    familiar_id INT NOT NULL,
    alumno_id INT NOT NULL,
    PRIMARY KEY (familiar_id, alumno_id),
    FOREIGN KEY (familiar_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE niveles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE
);
INSERT INTO niveles (nombre) VALUES ('Primaria'),('Secundaria'),('Terciaria'),('Universitaria');

CREATE TABLE orientaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE asignaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    nivel_id INT NOT NULL,
    institucion_id INT NULL,
    asistencia_por_dia TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (nivel_id) REFERENCES niveles(id),
    CONSTRAINT fk_asignaciones_inst FOREIGN KEY (institucion_id)
        REFERENCES instituciones(id) ON DELETE CASCADE,
    KEY idx_asig_inst (institucion_id)
);

CREATE TABLE divisiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asignacion_id INT NOT NULL,
    nombre VARCHAR(10) NOT NULL,
    orientacion_id INT NULL,
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (orientacion_id) REFERENCES orientaciones(id) ON DELETE SET NULL,
    UNIQUE KEY unique_div (asignacion_id, nombre)
);

CREATE TABLE materias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    orientacion_id INT NULL,
    institucion_id INT NULL,
    FOREIGN KEY (orientacion_id) REFERENCES orientaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_materias_inst FOREIGN KEY (institucion_id)
        REFERENCES instituciones(id) ON DELETE CASCADE,
    KEY idx_mat_inst (institucion_id)
);

CREATE TABLE materias_asignaciones (
    materia_id INT NOT NULL,
    asignacion_id INT NOT NULL,
    PRIMARY KEY (materia_id, asignacion_id),
    FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE
);

CREATE TABLE asignaciones_docente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    docente_id INT NOT NULL,
    materia_id INT NOT NULL,
    division_id INT NOT NULL,
    UNIQUE KEY unique_asig (docente_id, materia_id, division_id),
    FOREIGN KEY (docente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisiones(id) ON DELETE CASCADE
);

CREATE TABLE alumnos_division (
    alumno_id INT NOT NULL,
    division_id INT NOT NULL,
    PRIMARY KEY (alumno_id, division_id),
    solo_materias TINYINT(1) NOT NULL DEFAULT 0,
    sumar_nuevas TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisiones(id) ON DELETE CASCADE
);

CREATE TABLE alumnos_division_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT NOT NULL,
    division_id INT NOT NULL,
    ciclo_lectivo_id INT NOT NULL,
    año_lectivo INT NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE NULL,
    estado ENUM('cursando','aprobado','repite','transferido','libre') DEFAULT 'cursando',
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisiones(id) ON DELETE CASCADE,
    FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id) ON DELETE CASCADE,
    INDEX idx_hist_alumno (alumno_id, año_lectivo),
    INDEX idx_hist_division (division_id, año_lectivo),
    INDEX idx_hist_activo (alumno_id, activo)
);

CREATE TABLE alumno_materia_exclusion (
    alumno_id INT NOT NULL,
    materia_id INT NOT NULL,
    PRIMARY KEY (alumno_id, materia_id),
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS solicitudes_transferencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT NOT NULL,
    institucion_origen_id INT NOT NULL,
    institucion_destino_id INT NOT NULL,
    solicitada_por INT NULL,
    resuelta_por INT NULL,
    estado ENUM('pendiente','aprobada','denegada','cancelada') NOT NULL DEFAULT 'pendiente',
    nota VARCHAR(300) NULL,
    creada_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelta_en DATETIME NULL,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (institucion_origen_id) REFERENCES instituciones(id) ON DELETE CASCADE,
    FOREIGN KEY (institucion_destino_id) REFERENCES instituciones(id) ON DELETE CASCADE,
    FOREIGN KEY (solicitada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (resuelta_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    KEY idx_sol_origen (institucion_origen_id, estado),
    KEY idx_sol_destino (institucion_destino_id, estado),
    KEY idx_sol_alumno (alumno_id, estado)
);

CREATE TABLE IF NOT EXISTS alumno_materia_inscripcion (
    alumno_id INT NOT NULL,
    materia_id INT NOT NULL,
    division_id INT NOT NULL,
    PRIMARY KEY (alumno_id, materia_id),
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE CASCADE,
    FOREIGN KEY (division_id) REFERENCES divisiones(id) ON DELETE CASCADE,
    KEY idx_ami_division (division_id)
);
