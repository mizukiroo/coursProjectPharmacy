import express from "express";
import mysql from "mysql2/promise";
import path from "path";
import { fileURLToPath } from "url";
import fs from "fs";

const app = express();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * ----------------------------
 * middleware
 * ----------------------------
 */
app.use(express.json());

// логирование запросов
app.use((req, res, next) => {
    const t0 = Date.now();
    res.on("finish", () => {
        console.log(
            `[HTTP] ${req.method} ${req.originalUrl} -> ${res.statusCode} (${Date.now() - t0}ms)`
        );
    });
    next();
});

/**
 * ----------------------------
 * DB (как у тебя)
 * ----------------------------
 */
const DB_HOST = process.env.DB_HOST ?? "127.0.0.1";
const DB_USER = process.env.DB_USER ?? "root";
const DB_PASS = process.env.DB_PASS ?? "";
const DB_NAME = process.env.DB_NAME ?? "course_pharmacy";

const pool = mysql.createPool({
    host: DB_HOST,
    user: DB_USER,
    password: DB_PASS,
    database: DB_NAME,
    waitForConnections: true,
    connectionLimit: 10,
});

// проверка БД
(async () => {
    try {
        const [r] = await pool.query("SELECT 1 AS ok");
        console.log("[DB] connected:", r[0]);
    } catch (e) {
        console.error("[DB] connection FAILED:", e.message);
        process.exit(1);
    }
})();

/**
 * ----------------------------
 * STATIC (фикс: без хардкода C:/...)
 *
 * Твой server.js лежит в src/public/backend,
 * а index.html и фронт обычно в src/public
 * => по умолчанию поднимаемся на 1 уровень вверх.
 *
 * Но можно переопределить переменной окружения:
 *   Windows (PowerShell):
 *     $env:PUBLIC_DIR="C:\xampp\htdocs\coursProject\src\public"
 *   Windows (cmd):
 *     set PUBLIC_DIR=C:\xampp\htdocs\coursProject\src\public
 *   macOS/Linux:
 *     export PUBLIC_DIR=/path/to/src/public
 * ----------------------------
 */
const DEFAULT_PUBLIC_DIR = path.resolve(__dirname, "..");
const PUBLIC_DIR = process.env.PUBLIC_DIR
    ? path.resolve(process.env.PUBLIC_DIR)
    : DEFAULT_PUBLIC_DIR;

// диагностика (как у тебя было)
console.log("[STATIC] __dirname =", __dirname);
console.log("[STATIC] PUBLIC_DIR =", PUBLIC_DIR);
try {
    console.log("[STATIC] files =", fs.readdirSync(PUBLIC_DIR).slice(0, 30));
} catch (e) {
    console.error("[STATIC] cannot read PUBLIC_DIR:", e.message);
}

app.use(express.static(PUBLIC_DIR));

/**
 * ----------------------------
 * API (всё твоё — сохранено)
 * ----------------------------
 */

// чтобы не было 404 на favicon
app.get("/favicon.ico", (req, res) => res.sendStatus(204));

// ✅ точки для карты
app.get("/api/map/points", async (req, res) => {
    try {
        const type = String(req.query.type ?? "polyclinic");
        if (type !== "polyclinic") return res.status(400).json({ error: "Unknown type" });

        const [rows] = await pool.query(`\
      SELECT
        o.global_id AS id,
        'polyclinic' AS type,
        COALESCE(NULLIF(o.short_name,''), NULLIF(o.full_name,''), CONCAT('Поликлиника #', o.global_id)) AS name,
        gp.lon AS lon,
        gp.lat AS lat,
        wh.hours AS hours
      FROM organization o
      LEFT JOIN (
        SELECT global_id, MIN(lat) AS lat, MIN(lon) AS lon
        FROM org_geo_point
        GROUP BY global_id
      ) gp ON gp.global_id = o.global_id
      LEFT JOIN (
        SELECT
          global_id,
          GROUP_CONCAT(
            CONCAT(
              CASE day_of_week
                WHEN 1 THEN 'Пн'
                WHEN 2 THEN 'Вт'
                WHEN 3 THEN 'Ср'
                WHEN 4 THEN 'Чт'
                WHEN 5 THEN 'Пт'
                WHEN 6 THEN 'Сб'
                WHEN 7 THEN 'Вс'
                ELSE CONCAT('D', day_of_week)
              END,
              ' ',
              TIME_FORMAT(time_from, '%H:%i'),
              '-',
              TIME_FORMAT(time_to, '%H:%i')
            )
            ORDER BY day_of_week, time_from
            SEPARATOR '; '
          ) AS hours
        FROM org_working_hours
        GROUP BY global_id
      ) wh ON wh.global_id = o.global_id
      WHERE gp.lat IS NOT NULL AND gp.lon IS NOT NULL
    `);

        res.json(rows);
    } catch (e) {
        console.error("[API] /api/map/points error:", e);
        res.status(500).json({ error: "Server error" });
    }
});

// ✅ детали по клику
app.get("/api/map/points/:type/:id", async (req, res) => {
    try {
        if (req.params.type !== "polyclinic") return res.status(400).json({ error: "Unknown type" });

        const id = Number(req.params.id);
        if (!Number.isFinite(id)) return res.status(400).json({ error: "Bad id" });

        const [rows] = await pool.query(
            `\
      SELECT
        o.global_id    AS id,
        'polyclinic'   AS type,
        o.short_name   AS title,
        o.full_name    AS full_name,
        a.address_text AS address
      FROM organization o
      LEFT JOIN address a ON a.address_id = o.address_id
      WHERE o.global_id = ?
      LIMIT 1
      `,
            [id]
        );

        if (!rows.length) return res.status(404).json({ error: "Not found" });
        res.json(rows[0]);
    } catch (e) {
        console.error("[API] /api/map/points/:type/:id error:", e);
        res.status(500).json({ error: "Server error" });
    }
});

/**
 * ----------------------------
 * SPA fallback
 * ВАЖНО: ставим ПОСЛЕ /api, чтобы не перехватывал API запросы.
 * ----------------------------
 */
app.get("*", (req, res) => {
    res.sendFile(path.join(PUBLIC_DIR, "index.html"));
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Open  : http://localhost:${PORT}/`);
    console.log(`Points: http://localhost:${PORT}/api/map/points?type=polyclinic`);
});
