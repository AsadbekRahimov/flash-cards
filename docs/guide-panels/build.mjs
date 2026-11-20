// Сборка PDF-руководства по панелям LexiFlow Pro: node docs/guide-panels/build.mjs
//
// Только Playwright (Chromium) и стандартная библиотека Node. Что делает:
//  1. берёт parts/*.html по порядку имён (файлы, начинающиеся с цифры), проверяет, что в каждом есть
//     <section class="chapter"> с <h1> и текстом, и что все <img src> существуют — иначе ошибка и выход с кодом 1;
//  2. собирает обложку, оглавление и главы в одну страницу; рисунки нумеруются сквозь всё руководство
//     («Рис. N.» в начале подписи ставит сборка, номер в исходнике можно не писать);
//  3. у каждой главы — свой колонтитул с её названием (именованные страницы CSS + поля @page);
//  4. номера страниц в оглавлении: первый проход печатает PDF с невидимыми метками у заголовков, pdftotext
//     (poppler-utils) находит, на какой странице каждая метка, второй проход печатает итог с номерами.
//     Без pdftotext оглавление остаётся со ссылками, но без номеров страниц (сборка об этом пишет);
//  5. закладки PDF (outline) строит Chromium по заголовкам h1–h3.
// Результат: LexiFlow_Panels_Guide_RU.pdf рядом с этим файлом.

import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

// require(), а не import: так Playwright находится и в node_modules проекта, и в глобальной установке (NODE_PATH).
const { chromium } = createRequire(import.meta.url)('playwright');

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const PARTS = path.join(ROOT, 'parts');
const OUT = path.join(ROOT, 'LexiFlow_Panels_Guide_RU.pdf');

/** Разделы оглавления по номерам файлов глав. */
const SECTIONS = [
  { from: 0, to: 2, title: 'Начало работы' },
  { from: 3, to: 49, title: 'Панель администратора /admin' },
  { from: 50, to: 89, title: 'Кабинет учебного центра /org' },
  { from: 90, to: 99, title: 'Справочник' },
];

function fail(messages) {
  for (const m of messages) console.error('ОШИБКА: ' + m);
  process.exit(1);
}

// --- 1. Главы и проверки ------------------------------------------------------------------------
const files = fs.readdirSync(PARTS).filter((f) => /^\d.*\.html$/.test(f)).sort();
if (files.length === 0) fail(['в parts/ нет ни одной главы']);

const errors = [];
const chapters = files.map((file) => {
  const html = fs.readFileSync(path.join(PARTS, file), 'utf8');
  const text = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (!/<section[^>]*class="[^"]*\bchapter\b/.test(html)) errors.push(`${file}: нет <section class="chapter">`);
  if (!/<h1[\s>]/.test(html)) errors.push(`${file}: нет заголовка <h1>`);
  if (text.length < 300) errors.push(`${file}: глава пустая или почти пустая (${text.length} знаков текста)`);
  for (const m of html.matchAll(/<img\b[^>]*\bsrc="([^"]+)"/g)) {
    const src = m[1];
    if (/^(https?:|data:)/.test(src)) { errors.push(`${file}: внешний или встроенный рисунок не допускается: ${src.slice(0, 60)}`); continue; }
    if (!fs.existsSync(path.resolve(PARTS, decodeURI(src)))) errors.push(`${file}: нет файла рисунка ${src}`);
  }
  const num = parseInt(file, 10);
  return { file, num, html };
});
if (errors.length) fail(errors);

// --- 2. Сборка страницы -------------------------------------------------------------------------
const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const now = new Date();
const dateRu = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
const hasOrg = chapters.some((c) => c.num >= 50 && c.num <= 89);

const cover = `
<div class="cover">
  <div class="brand">LexiFlow Pro</div>
  <div class="title">Руководство по&nbsp;панелям управления</div>
  <div class="subtitle">Панель администратора <span class="mono">/admin</span>${hasOrg ? ' и кабинет учебного центра <span class="mono">/org</span>' : ''}: вход, ежедневная работа, контент, ученики, оплата и отчёты — с пояснениями и снимками экранов.</div>
  <ul class="roles">
    <li><b>Администратор платформы</b> — панель /admin целиком</li>
    <li><b>Учитель</b> — свои группы, ученики и экзамены в /admin</li>
    ${hasOrg ? '<li><b>Владелец и учитель учебного центра</b> — кабинет /org</li>' : ''}
  </ul>
  <div class="meta">Редакция от ${dateRu} · снимки сделаны на демонстрационном стенде, все имена вымышленные</div>
</div>`;

const body = chapters
  .map((c, i) => `<div class="ch-wrap" data-ch="${i}" data-num="${c.num}">${c.html}</div>`)
  .join('\n');

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lexiflow-guide-'));
const htmlPath = path.join(tmpDir, 'guide.html');
fs.writeFileSync(htmlPath, `<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<base href="${pathToFileURL(PARTS).href}/">
<title>LexiFlow Pro — руководство по панелям</title>
<link rel="stylesheet" href="../guide.css">
<style id="chapter-pages"></style>
</head><body>
${cover}
<nav class="toc"><h1 id="toc">Содержание</h1><ol id="toc-list"></ol></nav>
${body}
</body></html>`);

// --- 3. Обработка в браузере --------------------------------------------------------------------
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);

const info = await page.evaluate((SECTIONS) => {
  const esc = (s) => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  const css = [];
  const toc = document.getElementById('toc-list');
  let figNo = 0;
  let headNo = 0;
  let lastSection = null;
  const heads = [];
  for (const wrap of document.querySelectorAll('.ch-wrap')) {
    const i = wrap.dataset.ch;
    const num = Number(wrap.dataset.num);
    const sec = wrap.querySelector('section.chapter');
    const h1 = sec.querySelector('h1');
    const title = h1.textContent.replace(/\s+/g, ' ').trim();
    // свой колонтитул у каждой главы
    sec.style.page = `ch${i}`;
    css.push(`@page ch${i} { @top-left { content: "${esc(title)}"; font: 8.5pt 'Source Serif 4', serif; color: #5F6677; } @top-right { content: "${num >= 50 && num <= 89 ? 'Кабинет /org' : num >= 3 && num <= 49 ? 'Панель /admin' : ''}"; font: 8.5pt 'IBM Plex Mono', monospace; color: #9AA1B1; } }`);
    // раздел оглавления
    const s = SECTIONS.find((x) => num >= x.from && num <= x.to);
    if (s && s !== lastSection) {
      const li = document.createElement('li');
      li.className = 'part';
      li.textContent = s.title;
      toc.appendChild(li);
      lastSection = s;
    }
    // заголовки: якоря и метки страниц
    const entry = (h, level, parent) => {
      if (!h.id) h.id = `h-${i}-${headNo}`;
      const n = headNo++;
      const marker = document.createElement('span');
      marker.className = 'pg-marker';
      marker.textContent = `@@PG${n}@@`;
      h.appendChild(marker);
      heads.push({ n, id: h.id });
      const li = document.createElement('li');
      li.className = `lvl${level}`;
      const a = document.createElement('a');
      a.href = `#${h.id}`;
      a.innerHTML = `<span class="t"></span><span class="dots"></span><span class="p" data-pg="${n}">000</span>`;
      a.querySelector('.t').textContent = h.textContent.replace(/@@PG\d+@@/, '').replace(/\s+/g, ' ').trim();
      li.appendChild(a);
      parent.appendChild(li);
      return li;
    };
    const top = entry(h1, 1, toc);
    const sub = document.createElement('ol');
    for (const h2 of sec.querySelectorAll('h2')) entry(h2, 2, sub);
    if (sub.children.length) top.appendChild(sub);
    // сквозная нумерация рисунков
    for (const cap of sec.querySelectorAll('figure > figcaption')) {
      figNo++;
      const rest = cap.innerHTML.replace(/^\s*(<b>)?\s*Рис\.\s*[\dN.]*\s*(<\/b>)?\s*/i, '');
      cap.innerHTML = `<span class="fig-no">Рис. ${figNo}.</span> ${rest}`;
    }
  }
  document.getElementById('chapter-pages').textContent = css.join('\n');
  return { heads, figures: figNo, chapters: document.querySelectorAll('.ch-wrap').length };
}, SECTIONS);

// подождать все рисунки
await page.evaluate(async () => {
  await Promise.all([...document.images].map((img) => (img.complete ? null : new Promise((r) => { img.onload = img.onerror = r; }))));
});
const broken = await page.evaluate(() => [...document.images].filter((i) => !i.naturalWidth).map((i) => i.getAttribute('src')));
if (broken.length) fail(broken.map((s) => `рисунок не загрузился: ${s}`));

const pdfOptions = { format: 'A4', printBackground: true, preferCSSPageSize: true, outline: true, tagged: true };

// --- 4. Номера страниц оглавления ---------------------------------------------------------------
let tocMode = 'номера страниц';
const pass1 = path.join(tmpDir, 'pass1.pdf');
await page.pdf({ ...pdfOptions, path: pass1 });
let pages = null;
try {
  const txt = execFileSync('pdftotext', ['-enc', 'UTF-8', pass1, '-'], { maxBuffer: 256 * 1024 * 1024 }).toString('utf8');
  pages = txt.split('\f');
} catch {
  tocMode = 'только ссылки (pdftotext не найден)';
}
if (pages) {
  const where = {};
  pages.forEach((t, idx) => { for (const m of t.matchAll(/@@PG(\d+)@@/g)) where[m[1]] ??= idx + 1; });
  const missing = info.heads.filter((h) => !where[h.n]).length;
  await page.evaluate((where) => {
    for (const s of document.querySelectorAll('.toc .p')) s.textContent = where[s.dataset.pg] ? String(where[s.dataset.pg]) : '';
  }, where);
  if (missing) console.warn(`Предупреждение: у ${missing} заголовков не найдена страница — в оглавлении без номера.`);
}
await page.evaluate(() => document.querySelectorAll('.pg-marker').forEach((m) => m.remove()));
if (!pages) await page.evaluate(() => document.querySelectorAll('.toc .p, .toc .dots').forEach((m) => m.remove()));

// --- 5. Итоговый PDF ----------------------------------------------------------------------------
await page.pdf({ ...pdfOptions, path: OUT });
await browser.close();
fs.rmSync(tmpDir, { recursive: true, force: true });

const size = fs.statSync(OUT).size;
let pageCount = '';
try { pageCount = (execFileSync('pdfinfo', [OUT]).toString().match(/Pages:\s+(\d+)/) || [])[1] || ''; } catch { /* нет pdfinfo */ }
console.log(`Готово: ${path.relative(process.cwd(), OUT)}`);
console.log(`Глав: ${info.chapters}, рисунков: ${info.figures}${pageCount ? `, страниц: ${pageCount}` : ''}, размер: ${(size / 1024 / 1024).toFixed(1)} МБ`);
console.log(`Оглавление: ${tocMode}.`);
