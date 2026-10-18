# Узбекские строки интерфейса — на проверку носителю

> Сгенерировано из `lang/{ru,uz}/*.php` (бот, напоминания, 2FA) и `resources/twa/src/i18n/{ru,uz}.ts` (TWA) — Фаза 0, задача 0.5.
> Узбекский — латиница, апостроф ASCII `'` (o'qish, so'z). Обращение на «Siz».
> Плейсхолдеры: `:x` — Laravel, `{x}` — TWA; их не переводить и не удалять.
> Формы чисел разделены `|` (ru: 1 / 2–4 / 5+; uz: одна форма «N ta so'z»).
>
> Что проверить в первую очередь: термины «o'quvchi» (студент), «mashg'ulot» (тренировка), «imtihon» (экзамен),
> «tiklash kodlari» (recovery-коды), порядок слов в `result.rank`.
> Правки вносите прямо в колонку uz (или в файлы `lang/uz/*.php`, `resources/twa/src/i18n/uz.ts`).

## Бот, напоминания, 2FA (`lang/uz/*.php`)

| Ключ | uz | ru |
|---|---|---|
| `bot.start.not_linked` | Bu Telegram akkaunt LexiFlow'dagi o'qituvchiga biriktirilmagan. Administratordan Telegram ID'ngizni kiritishini so'rang: :id. | Этот Telegram-аккаунт не привязан к учителю в LexiFlow. Попросите админа прописать ваш Telegram ID: :id. |
| `bot.start.greeting` | Salom, :name! Siz LexiFlow o'qituvchisi sifatida tizimga kirdingiz. Guruhdagi buyruqlar: /help | Привет, :name! Вы авторизованы как учитель LexiFlow. Команды в группе: /help |
| `bot.help.private` | LexiFlow — ingliz tilini o'rganish yordamchisi.<br><br>O'qituvchilar uchun: botni guruhga qo'shing, administrator uni faollashtiradi.<br>Guruhdagi mavjud buyruqlar: /start_training, /start_exam, /close_exam, /help<br>Shaxsiy hisobot: /stats | LexiFlow — помощник для изучения английского.<br><br>Для учителей: добавьте бота в группу, админ активирует её.<br>Доступные команды в группе: /start_training, /start_exam, /close_exam, /help<br>Личный отчёт: /stats |
| `bot.help.group` | LexiFlow Bot guruhda.<br><br>O'qituvchi buyruqlari:<br>/start_training — mashg'ulotni boshlash<br>/start_exam — imtihonni boshlash<br>/close_exam — imtihonni yopish<br>/stats — bot bilan shaxsiy chatda hisobot<br>/help — ushbu yordam | LexiFlow Bot в группе.<br><br>Команды учителя:<br>/start_training — запустить тренировку<br>/start_exam — запустить экзамен<br>/close_exam — закрыть экзамен<br>/stats — личный отчёт в чате с ботом<br>/help — эта справка |
| `bot.stats.not_linked` | Bu Telegram akkaunt LexiFlow o'qituvchisiga biriktirilmagan.<br>Sizning Telegram ID'ngiz: :id | Этот Telegram-аккаунт не привязан к учителю LexiFlow.<br>Ваш Telegram ID: :id |
| `errors.teacher_not_linked` | Sizning Telegram akkauntingiz o'qituvchiga biriktirilmagan. | Ваш Telegram-аккаунт не привязан к учителю. |
| `errors.teacher_not_linked_ask_admin` | Sizning Telegram akkauntingiz o'qituvchiga biriktirilmagan. Administratordan sizni qo'shishini so'rang. | Ваш Telegram-аккаунт не привязан к учителю. Попросите админа добавить вас. |
| `errors.group_inactive` | Guruh faollashtirilmagan. Administratordan uni /admin orqali faollashtirishini so'rang. | Группа не активирована. Попросите админа активировать её в /admin. |
| `errors.stage_not_found` | Ko'rsatilgan stage topilmadi. | Указанный stage не найден. |
| `errors.lesson_not_found` | Dars topilmadi. | Урок не найден. |
| `errors.training.not_teacher` | Mashg'ulotni faqat shu guruhning o'qituvchisi boshlay oladi. | Только учитель этой группы может запускать тренировку. |
| `errors.training.failed` | Mashg'ulotni boshlab bo'lmadi. | Не удалось запустить тренировку. |
| `errors.exam.not_teacher` | Imtihonni faqat shu guruhning o'qituvchisi boshlay oladi. | Только учитель этой группы может запускать экзамен. |
| `errors.exam.not_enough_words` | Darsda imtihon uchun so'zlar yetarli emas. | В уроке недостаточно слов для экзамена. |
| `errors.exam.not_enough_distractors` | Javob variantlari uchun so'zlar yetarli emas: darsga yoki shu stage'ning boshqa darslariga ko'proq so'z qo'shing. | Не хватает слов для вариантов ответа: добавьте больше слов в урок или в другие уроки этого stage. |
| `errors.exam.already_open` | Guruhda imtihon allaqachon davom etmoqda. Uni /close_exam orqali yakunlang. | В группе уже идёт экзамен. Завершите его через /close_exam. |
| `errors.exam.invalid_duration` | Imtihon davomiyligi 1 daqiqadan 30 daqiqagacha bo'lishi kerak. | Длительность экзамена должна быть от 1 до 30 минут. |
| `errors.exam.failed` | Imtihonni boshlab bo'lmadi. | Не удалось запустить экзамен. |
| `errors.exam.close_not_teacher` | Imtihonni faqat shu guruhning o'qituvchisi yopishi mumkin. | Только учитель этой группы может закрыть экзамен. |
| `errors.exam.no_open_exam` | Hozir guruhda ochiq imtihon yo'q. | Сейчас в группе нет открытого экзамена. |
| `errors.exam.close_failed` | Imtihonni yopib bo'lmadi. | Не удалось закрыть экзамен. |
| `exam.started` | 🏁 <b>Imtihon boshlandi!</b><br>Stage :stage · Lesson :lesson · <b>:minutes daq</b><br>Qo'shilish uchun tugmani bosing. | 🏁 <b>Экзамен запущен!</b><br>Stage :stage · Lesson :lesson · <b>:minutes мин</b><br>Нажмите кнопку, чтобы присоединиться. |
| `exam.open_button` | 🏁 Imtihonni ochish | 🏁 Открыть экзамен |
| `exam.leaderboard.title` | 🏁 <b>Imtihon natijalari</b><br>Dars :lesson | 🏁 <b>Итоги экзамена</b><br>Урок :lesson |
| `exam.leaderboard.nobody` | Hech kim javob bermadi 😅 | Никто не ответил 😅 |
| `reminders.review` | LexiFlow'da :count ta so'zni takrorlash vaqti keldi. | Пора повторить :count слово в LexiFlow.\|Пора повторить :count слова в LexiFlow.\|Пора повторить :count слов в LexiFlow. |
| `reminders.open_button` | LexiFlow'ni ochish | Открыть LexiFlow |
| `stats.no_groups` | Sizda hozircha biriktirilgan guruhlar yo'q.<br>Administratordan guruhni /admin orqali biriktirishini so'rang. | У вас пока нет привязанных групп.<br>Попросите администратора привязать группу в /admin. |
| `stats.title` | 📊 LexiFlow statistikasi (so'nggi 7 kun) | 📊 Статистика LexiFlow за 7 дней |
| `stats.groups` | Guruhlar: :count | Групп: :count |
| `stats.learners` | Faol o'quvchilar: :count | Активных студентов: :count |
| `stats.reviews` | Kartochkalarni takrorlashlar: :count | Повторений карточек: :count |
| `stats.exams` | Boshlangan imtihonlar: :count | Экзаменов запущено: :count |
| `stats.accuracy` | Imtihonlardagi aniqlik: :percent% | Точность экзаменов: :percent% |
| `training.picker.prompt` | Mashg'ulot uchun darsni tanlang: | Выберите урок для тренировки: |
| `training.picker.empty` | Tizimda hozircha darslar yo'q. Administratordan kontent yuklashini so'rang. | В системе пока нет уроков. Попросите администратора загрузить контент. |
| `training.picker.button` | Stage :stage / Lesson :lesson | Stage :stage / Lesson :lesson |
| `training.started` | 📚 Stage :stage / Lesson :lesson mashg'uloti boshlandi! Boshlash uchun tugmani bosing. | 📚 Тренировка Stage :stage / Lesson :lesson запущена! Нажмите кнопку, чтобы начать. |
| `training.open_button` | 🎯 Mashg'ulotni ochish | 🎯 Открыть тренировку |
| `twofactor.confirm_button` | Tasdiqlash | Подтвердить |
| `twofactor.setup.title` | 2FA sozlash | Настройка 2FA |
| `twofactor.setup.heading` | Ikki bosqichli autentifikatsiyani yoqing | Включите двухфакторную аутентификацию |
| `twofactor.setup.intro` | QR-kodni Google Authenticator, 1Password yoki Authy ilovasida skanerlang, so'ng tasdiqlash uchun 6 xonali kodni kiriting. | Отсканируйте QR-код в Google Authenticator, 1Password или Authy, затем введите 6-значный код для подтверждения. |
| `twofactor.setup.manual_key` | Yoki kalitni qo'lda kiriting: | Или введите ключ вручную: |
| `twofactor.setup.code_label` | Ilovadagi kod | Код из приложения |
| `twofactor.challenge.title` | Ikki bosqichli tekshiruv | Двухфакторная проверка |
| `twofactor.challenge.heading` | Kodni kiriting | Введите код |
| `twofactor.challenge.intro` | Autentifikator ilovasini oching va 6 xonali kodni kiriting. Yoki tiklash kodidan foydalaning. | Откройте приложение-аутентификатор и введите 6-значный код. Либо используйте recovery-код. |
| `twofactor.challenge.code_label` | Kod | Код |
| `twofactor.recovery.title` | Tiklash kodlari | Recovery-коды |
| `twofactor.recovery.heading` | Tiklash kodlarini saqlab qo'ying | Сохраните recovery-коды |
| `twofactor.recovery.intro` | Har bir kod <strong>faqat bir marta</strong> amal qiladi. Telefoningizni yo'qotsangiz, ulardan foydalaning. Kodlarni parollar menejerida saqlang. | Каждый код действителен <strong>один раз</strong>. Используйте их, если потеряете телефон. Храните в менеджере паролей. |
| `twofactor.recovery.to_admin` | → Admin panelga o'tish | → Перейти в админку |
| `twofactor.errors.invalid_code` | Kod noto'g'ri. Qayta urinib ko'ring. | Неверный код. Попробуйте ещё раз. |
| `twofactor.errors.invalid_code_or_recovery` | Kod yoki tiklash kodi noto'g'ri. | Неверный код или recovery-код. |

## TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `common.close` | Yopish | Закрыть |
| `common.loading` | Yuklanmoqda… | Загрузка… |
| `common.retry` | Qayta urinish | Повторить |
| `common.error_title` | Xatolik | Ошибка |
| `landing.in_telegram` | Mashg'ulotni Telegram guruhidagi tugma orqali oching. | Откройте тренировку через кнопку в группе Telegram. |
| `landing.outside_telegram` | Bu sahifa faqat Telegram Web App ichida ishlaydi. O'qituvchidan mashg'ulot havolasini guruhga yuborishini so'rang. | Эта страница работает только внутри Telegram Web App. Попросите учителя прислать ссылку-тренировку в группу. |
| `card.kind.new` | Yangi so'z | Новое слово |
| `card.kind.learning` | Mustahkamlash | Закрепление |
| `card.kind.due` | Takrorlash | Повторение |
| `card.speak` | Talaffuz qilish | Произнести |
| `card.reveal` | Tarjimani ko'rsatish | Показать перевод |
| `card.grade_group` | Baho | Оценка |
| `card.grade.hard` | Qiyin | Трудно |
| `card.grade.medium` | O'rtacha | Средне |
| `card.grade.easy` | Oson | Легко |
| `training.lesson_header` | Stage {stage} · Lesson {lesson} | Stage {stage} · Lesson {lesson} |
| `training.closed_title` | 🔒 Mashg'ulot o'qituvchi tomonidan yakunlandi | 🔒 Тренировка завершена учителем |
| `exam.closed_title` | 🔒 Imtihon o'qituvchi tomonidan yakunlandi | 🔒 Экзамен завершён учителем |
| `result.title` | Imtihon natijalari | Итоги экзамена |
| `result.your_score` | Sizning natijangiz | Твой результат |
| `result.rank` | {total} ishtirokchi ichida {rank}-o'rin | Место {rank} из {total} |
| `result.pending` | Natijalar hali hisoblanmoqda… | Результаты ещё считаются… |
| `result.top` | Eng yaxshi ishtirokchilar | Топ участников |
| `finished.title` | Ajoyib! | Отлично! |
| `finished.reviewed.one` | Siz {count} ta so'zni takrorladingiz. | Вы повторили {count} слово. |
| `finished.reviewed.few` | _(в uz этой формы нет — используется `other`)_ | Вы повторили {count} слова. |
| `finished.reviewed.many` | _(в uz этой формы нет — используется `other`)_ | Вы повторили {count} слов. |
| `finished.reviewed.other` | Siz {count} ta so'zni takrorladingiz. | Вы повторили {count} слова. |
| `finished.next_label` | Keyingi takrorlash | Следующее повторение |
| `finished.next_today` | Bugun | Сегодня |
| `finished.next_in_days.one` | {count} kundan keyin | Через {count} день |
| `finished.next_in_days.few` | _(в uz этой формы нет — используется `other`)_ | Через {count} дня |
| `finished.next_in_days.many` | _(в uz этой формы нет — используется `other`)_ | Через {count} дней |
| `finished.next_in_days.other` | {count} kundan keyin | Через {count} дня |
| `error.closed.training` | O'qituvchi mashg'ulotni yakunladi. | Учитель завершил тренировку. |
| `error.closed.exam` | Imtihon o'qituvchi tomonidan yakunlandi. | Экзамен был завершён учителем. |
| `error.forbidden.training` | Sizda bu mashg'ulotga kirish huquqi yo'q. | У вас нет доступа к этой тренировке. |
| `error.forbidden.exam` | Sizda bu imtihonga kirish huquqi yo'q. | У вас нет доступа к этому экзамену. |
| `error.network` | Server bilan aloqa yo'q. Qayta urinib ko'ring. | Нет связи с сервером. Попробуйте ещё раз. |
| `error.rate_limited` | Juda ko'p so'rov yuborildi. Biroz kutib, qayta urinib ko'ring. | Слишком много запросов. Подождите немного и повторите. |
| `error.server` | Server vaqtincha mavjud emas. Keyinroq urinib ko'ring. | Сервер временно недоступен. Попробуйте позже. |
| `error.generic` | Nimadir xato ketdi. | Что-то пошло не так. |
| `error.unexpected` | Kutilmagan xatolik. | Непредвиденная ошибка. |
| `error.outside_telegram` | Ilovani Telegram orqali oching. | Откройте приложение через Telegram. |
| `error.code.session_closed` | Sessiya allaqachon yopilgan. | Сессия уже закрыта. |
| `error.code.session_not_found` | Sessiya topilmadi. O'qituvchidan uni qaytadan boshlashini so'rang. | Сессия не найдена. Попросите учителя запустить её заново. |
| `error.code.student_not_found` | Profilingiz topilmadi. Guruhga qo'shiling va qayta urinib ko'ring. | Ваш профиль не найден. Вступите в группу и попробуйте снова. |
| `error.code.group_inactive` | Guruh faollashtirilmagan. O'qituvchiga murojaat qiling. | Группа не активирована. Обратитесь к учителю. |
| `error.code.group_mismatch` | Bu sessiya boshqa guruhga tegishli. | Эта сессия относится к другой группе. |
| `error.code.session_context_required` | Mashg'ulot yoki imtihonni guruhdagi tugma orqali oching. | Откройте тренировку или экзамен по кнопке в группе. |
| `error.code.invalid_init_data` | Telegram sessiyasini tasdiqlab bo'lmadi. Ilovani qaytadan oching. | Не удалось подтвердить Telegram-сессию. Откройте приложение заново. |
| `error.code.invalid_token` | Sessiya eskirgan. Ilovani qaytadan oching. | Сессия устарела. Откройте приложение заново. |
| `error.code.lesson_not_found` | Dars topilmadi. | Урок не найден. |
| `error.code.question_not_found` | Savol topilmadi. | Вопрос не найден. |
| `error.code.question_not_served` | Savol hali ko'rsatilmagan. Ekranni yangilang. | Вопрос ещё не показан. Обновите экран. |
| `error.code.already_answered` | Siz bu savolga allaqachon javob berdingiz. | Вы уже ответили на этот вопрос. |
| `error.code.word_mismatch` | Javob savolga mos kelmaydi. Ekranni yangilang. | Ответ не соответствует вопросу. Обновите экран. |
| `error.code.wrong_lesson` | Bu so'z darsga tegishli emas. | Это слово не относится к уроку. |
| `error.code.card_not_started` | Bu kartochka hali ko'rsatilmagan. Ekranni yangilang. | Эта карточка ещё не показывалась. Обновите экран. |
| `error.code.card_not_due` | Bu kartochka allaqachon takrorlangan, vaqti hali kelmadi. | Эта карточка уже повторена, её время ещё не пришло. |
| `error.code.exam_still_open` | Imtihon hali davom etmoqda, natijalar tugagandan keyin chiqadi. | Экзамен ещё идёт, результаты появятся после его завершения. |
| `error.code.tts_unavailable` | Ovozli talaffuz hozircha mavjud emas. | Озвучка сейчас недоступна. |
