# 04. API & Telegram Bot — LexiFlow Pro

## Часть A — REST API для TWA

**Base URL:** `/api/twa`
**Auth:** JWT в `Authorization: Bearer <token>` (кроме `/auth`).
**Content-Type:** `application/json`.

### A.1 `POST /api/twa/auth`
Аутентификация через Telegram `initData`.

**Request:**
```json
{
  "init_data": "query_id=...&user=%7B...%7D&auth_date=...&hash=..."
}
```

**Validation:**
- HMAC-SHA256 по алгоритму Telegram (см. `07_SECURITY.md`).
- `auth_date` не старше 24 часов.

**Response 200:**
```json
{
  "token": "eyJhbGciOi...",
  "expires_in": 900,
  "student": {
    "id": 42,
    "first_name": "Alex",
    "username": "alex_dev",
    "telegram_group_id": 7
  }
}
```

**Errors:**
- 401 `invalid_init_data` — HMAC не сошёлся.
- 403 `group_inactive` — студент пришёл из неактивной группы.

### A.2 `GET /api/twa/me`
Информация о студенте.

**Response:**
```json
{
  "student": { "id": 42, "first_name": "Alex" },
  "group": { "id": 7, "title": "B1 English Evening" },
  "stats": {
    "words_learned": 234,
    "words_due_today": 18,
    "current_streak_days": 5
  }
}
```

### A.3 Training mode

#### `POST /api/twa/training/sessions/{session_id}/start`
Студент присоединяется к открытой тренировке. Валидируется, что `training_session.status = 'open'` и студент в той же группе.

**Response:**
```json
{
  "session_id": 123,
  "lesson": { "stage": 4, "lesson": 8, "title": "Resilience" },
  "total_words": 25
}
```

#### `GET /api/twa/training/sessions/{session_id}/next`
Следующая карточка. Возвращает либо `due` (для повторения), либо `new` (новое слово из урока).

**Response:**
```json
{
  "card": {
    "word_id": 1001,
    "word": "Resilient",
    "translation": "Устойчивый",
    "example": "She is a resilient girl.",
    "transcription": "rɪˈzɪl.i.ənt",
    "card_kind": "due"      // due | new
  },
  "progress": { "done": 7, "total": 25 }
}
```

При отсутствии карточек — `{ "card": null, "progress": { ... } }` и клиент показывает экран «готово».

#### `POST /api/twa/training/sessions/{session_id}/review`
Оценка карточки.

**Request:**
```json
{
  "word_id": 1001,
  "quality": 2,             // 2=hard, 3=medium, 5=easy
  "time_spent_ms": 4300
}
```

**Response:**
```json
{
  "next_review_at": "2026-04-22T14:00:00Z",
  "new_interval_days": 3,
  "easiness_factor": 2.45
}
```

### A.4 Exam mode

#### `POST /api/twa/exam/sessions/{session_id}/join`
**Response:**
```json
{
  "session_id": 55,
  "ends_at": "2026-04-19T19:10:00Z",
  "total_questions": 10,
  "seconds_per_question": 10
}
```

Ошибка 409 `already_joined` если студент уже подключался.

#### `GET /api/twa/exam/sessions/{session_id}/question?index=0`
**Response:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "word": "Resilient",
  "options": [
    "Устойчивый",
    "Хрупкий",
    "Смелый",
    "Тихий"
  ],
  "seconds_left": 9.8
}
```

#### `POST /api/twa/exam/sessions/{session_id}/answer`
**Request:**
```json
{
  "question_index": 0,
  "word_id": 1001,
  "selected_option_index": 0,
  "time_spent_ms": 3200
}
```

**Response:**
```json
{
  "is_correct": true,
  "score_earned": 15,
  "total_score": 45,
  "has_next": true
}
```

#### `GET /api/twa/exam/sessions/{session_id}/result`
Доступно только после закрытия сессии.
```json
{
  "student_score": 95,
  "rank": 3,
  "total_participants": 12,
  "leaderboard": [
    { "rank": 1, "name": "Alex", "score": 120 },
    { "rank": 2, "name": "Maria", "score": 110 }
  ]
}
```

### A.5 TTS fallback
`GET /api/twa/tts?word=resilient&voice=en-US-AriaNeural`
Возвращает `audio/mpeg`. Cache-friendly: `Cache-Control: public, max-age=2592000`.

### A.6 Общие правила
- Все даты в ISO 8601 UTC.
- Rate limit: 60 запросов/минуту на JWT (кроме `/tts` — 30/мин).
- При любой 4xx/5xx ответ:
```json
{ "error": { "code": "string", "message": "string", "details": {} } }
```

## Часть B — Telegram Bot

### B.1 Webhook
**URL:** `POST /telegram/webhook/{secret}`
- `{secret}` генерируется при развёртывании и совпадает с `TELEGRAM_WEBHOOK_SECRET`.
- Дополнительно проверяем заголовок `X-Telegram-Bot-Api-Secret-Token` (Telegram сам его шлёт, если указать при `setWebhook`).
- Контроллер кладёт `Update` в очередь `high` и мгновенно отвечает 200.

```php
// app/Http/Controllers/TelegramWebhookController.php (скелет)
public function __invoke(Request $request, string $secret)
{
    abort_unless(hash_equals(config('telegram.webhook_secret'), $secret), 404);
    abort_unless(hash_equals(
        config('telegram.webhook_secret'),
        $request->header('X-Telegram-Bot-Api-Secret-Token', '')
    ), 403);

    HandleTelegramUpdate::dispatch($request->all())->onQueue('high');
    return response()->json(['ok' => true]);
}
```

### B.2 Middleware

1. **GroupLock** — проверяет `chat_id` в белом списке.
2. **TeacherOnly** — применяется к командам `/start_training`, `/start_exam`, `/close_exam`.
3. **IgnorePrivateChats** — для команд, которые имеют смысл только в группе.

### B.3 Команды и хендлеры

| Команда | Контекст | Роль | Что делает |
|---------|----------|------|-----------|
| `/start` | ЛС | любая | Если `user_id` есть в `users.telegram_user_id` → «Привет, учитель X». Иначе — инструкция, как присоединиться к группе. |
| `/start_training [stage] [lesson]` | группа | teacher | Создаёт `training_session`, постит сообщение с WebApp-кнопкой. |
| `/start_exam [stage] [lesson] [minutes]` | группа | teacher | Создаёт `exam_session`, планирует закрытие. |
| `/close_exam` | группа | teacher | Досрочно закрывает открытый экзамен. |
| `/stats` | ЛС | teacher | Отчёт по его группам за 7 дней. |
| `/help` | любой | любая | Список доступных команд для роли. |

### B.4 Формат сообщения с WebApp-кнопкой

```php
$telegram->sendWebAppButton(
    chatId: $group->chat_id,
    text: "📚 Тренировка: Урок {$lesson->number}\nНажмите кнопку, чтобы начать.",
    buttonText: '🎯 Открыть тренировку',
    url: "{$baseUrl}/twa/training/{$session->id}",
);
```

URL TWA: `https://lexiflow.example.com/twa/?mode=training&session_id=123` (SPA сама поймёт режим по query-параметрам).

### B.5 События бота (не команды)

- **`my_chat_member`** — бот добавлен/удалён из группы.
  - Added → создаём запись `telegram_groups` со статусом `pending`, уведомляем админов.
  - Removed → `status = 'disabled'`, лочим операции.
- **`message` в группе от `new_chat_members`** — в `new_chat_members` есть `id` бота или новых студентов.
  - Для новых студентов: upsert в `students`.
- **Callback query** — пока не используется (весь UX через WebApp), но хендлер на будущее.

### B.6 Событие `update` от WebApp (опционально)

Если TWA вызывает `Telegram.WebApp.sendData(...)`, то в чат приходит апдейт типа `web_app_data` (только в приватных чатах). В нашем дизайне студент общается с API напрямую, а не через `sendData` — это упрощает масштабирование. Но оставляем возможность добавить лёгкие «отправки» (например, «поделиться результатом экзамена»).

### B.7 Rate limiting отправки сообщений
- Не более 20 сообщений в минуту в одну группу.
- Реализация через Redis-based sliding window в `TelegramGateway::sendMessage()`.
- Если лимит достигнут — кладём в очередь `default` с задержкой.

### B.8 Пример хендлера обработки обновлений

```php
// app/Telegram/Handlers/StartTrainingHandler.php
public function __invoke(Nutgram $bot, ?int $stage = null, ?int $lesson = null): void
{
    $group = $this->groupRepo->findActiveByChatId($bot->chatId());
    if (!$group) return; // GroupLock уже отсёк, но на всякий

    $teacher = $this->userRepo->findByTelegramId($bot->userId());
    if (!$group->isTaughtBy($teacher)) {
        $bot->sendMessage('Только учитель этой группы может запускать тренировку.');
        return;
    }

    // если параметры не переданы — показать выбор
    if ($stage === null || $lesson === null) {
        $this->showLessonPicker($bot, $group);
        return;
    }

    $session = $this->trainingService->open($group, $stage, $lesson, $teacher);

    $bot->sendMessage(
        text: "📚 Тренировка по Уроку {$lesson} запущена!",
        reply_markup: $this->buildWebAppButton($session),
    );
}
```

### B.9 Закрытие экзамена (scheduler)

```php
// app/Console/Commands/CloseExpiredExams.php
public function handle(ExamSessionService $service): int
{
    $expired = ExamSession::query()
        ->where('status', 'open')
        ->where('ends_at', '<=', now())
        ->lockForUpdate()
        ->get();

    foreach ($expired as $session) {
        $service->close($session);
        PostLeaderboardJob::dispatch($session->id)->onQueue('default');
    }

    return self::SUCCESS;
}
```

Регистрируем в `app/Console/Kernel.php`:
```php
$schedule->command('exams:close-expired')->everyMinute()->withoutOverlapping();
```

### B.10 Отправка лидерборда в группу

```php
// app/Jobs/PostLeaderboardJob.php — скелет
public function handle(TelegramGateway $tg): void
{
    $session = ExamSession::with('results.student', 'group')->findOrFail($this->sessionId);
    $text = $this->formatLeaderboard($session);
    $tg->sendMessage($session->group->chat_id, $text, parseMode: 'HTML');
}

private function formatLeaderboard(ExamSession $session): string
{
    $rows = $session->results->sortBy('rank')->map(function (ExamResult $r) {
        $medal = match ($r->rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "{$r->rank}." };
        return "{$medal} {$r->student->first_name} — <b>{$r->total_score}</b>";
    })->implode("\n");

    return "🏁 <b>Итоги экзамена</b>\nУрок {$session->lesson->number}\n\n{$rows}";
}
```

## Часть C — Примеры полного цикла

### C.1 Happy path тренировки (E2E)
1. Teacher в группе: `/start_training 4 8`.
2. Bot → `HandleTelegramUpdate` (queue) → `StartTrainingHandler` → создаёт `training_sessions` id=123.
3. Bot отправляет сообщение с WebApp-кнопкой на URL `.../twa/?mode=training&session_id=123`.
4. Student нажимает кнопку → открывается TWA.
5. TWA: `POST /api/twa/auth` с `initData`.
6. TWA: `POST /api/twa/training/sessions/123/start`.
7. Цикл: `GET .../next` → показать карточку → `POST .../review` с `quality`.
8. Teacher в группе: `/close_training` (или тренировка просто продолжается — MVP не требует формального закрытия).

### C.2 Happy path экзамена
1. Teacher: `/start_exam 4 8 10`.
2. Создана `exam_sessions` со `ends_at = now() + 10 min`.
3. Сообщение с WebApp-кнопкой.
4. Student через TWA: `join`, `question`, `answer` × N.
5. Scheduler через минуту после `ends_at` закрывает сессию, считает `exam_results`, диспатчит `PostLeaderboardJob`.
6. Бот постит лидерборд в группу.
