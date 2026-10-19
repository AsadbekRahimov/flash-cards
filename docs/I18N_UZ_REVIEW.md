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
| `bot.start.greeting` | Salom, :name! Siz LexiFlow o'qituvchisi sifatida tizimga kirdingiz. Guruhdagi buyruqlar: /help | Привет, :name! Вы авторизованы как учитель LexiFlow. Команды в группе: /help |
| `bot.help.private` | LexiFlow — ingliz tilini o'rganish yordamchisi.<br><br>O'qituvchilar uchun: botni guruhga qo'shing, administrator uni faollashtiradi.<br>Guruhdagi mavjud buyruqlar: /start_training, /start_exam, /close_exam, /help<br>Shaxsiy hisobot: /stats | LexiFlow — помощник для изучения английского.<br><br>Для учителей: добавьте бота в группу, админ активирует её.<br>Доступные команды в группе: /start_training, /start_exam, /close_exam, /help<br>Личный отчёт: /stats |
| `bot.help.group` | LexiFlow Bot guruhda.<br><br>O'qituvchi buyruqlari:<br>/start_training — mashg'ulotni boshlash<br>/start_exam — imtihonni boshlash<br>/close_exam — imtihonni yopish<br>/stats — bot bilan shaxsiy chatda hisobot<br>/help — ushbu yordam | LexiFlow Bot в группе.<br><br>Команды учителя:<br>/start_training — запустить тренировку<br>/start_exam — запустить экзамен<br>/close_exam — закрыть экзамен<br>/stats — личный отчёт в чате с ботом<br>/help — эта справка |
| `bot.group.bot_not_admin` | ⚠️ Botni guruh administratori qiling. Aks holda bot xabarlarni va a'zolarning chiqib ketishini ko'rmaydi, LexiFlow to'liq ishlamaydi. Guruh → Boshqarish → Administratorlar → Administrator qo'shish. | ⚠️ Сделайте бота администратором группы. Без этого он не видит сообщения и выход участников, и LexiFlow работает не полностью. Группа → Управление → Администраторы → Добавить администратора. |
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

## Публичный сайт: лендинг, оферта, политика (`lang/uz/landing.php`, `lang/uz/legal.php`)

> Задача 1.7. Тексты оферты и политики — заглушки (структура разделов); юридический текст предоставит владелец и затем потребует отдельной проверки носителем.

| Ключ | uz | ru |
|---|---|---|
| `landing.meta.title` | LexiFlow - Telegram orqali inglizcha so'zlarni o'rganing | LexiFlow — английский слово за словом в Telegram |
| `landing.meta.description` | LexiFlow - Telegramdagi bot va mini-ilova: kartochkalar, ovozli talaffuz va aqlli takrorlash orqali inglizcha so'zlarni o'rganing. Bepul: kuniga 20 ta yangi so'z. | LexiFlow — бот и мини-приложение в Telegram для изучения английских слов: карточки, озвучка и умные повторения. Бесплатно: 20 новых слов в день. |
| `landing.nav.how` | Qanday ishlaydi | Как это работает |
| `landing.nav.pricing` | Tariflar | Тарифы |
| `landing.nav.teachers` | O'quv markazlari uchun | Для центров |
| `landing.nav.label` | Asosiy navigatsiya | Основная навигация |
| `landing.hero.title` | Esda qoladigan inglizcha so'zlar | Английские слова, которые остаются в памяти |
| `landing.hero.subtitle` | Ingliz tilini bevosita Telegramda o'rganing: ovozli va misolli kartochkalar, bot esa so'zlarni unuta boshlagan paytingizda takrorlashni eslatadi. | Учите английский прямо в Telegram: карточки с озвучкой и примерами, а бот напоминает повторить слова ровно тогда, когда вы начинаете их забывать. |
| `landing.hero.cta_bot` | Botni ochish | Открыть бота |
| `landing.hero.cta_unavailable` | Bot tez orada ochiladi | Бот скоро откроется |
| `landing.hero.note` | Bepul: kuniga 20 ta yangi so'z, takrorlash esa cheklovsiz. | Бесплатно: 20 новых слов в день, повторения — без ограничений. |
| `landing.how.title` | Qanday ishlaydi | Как это работает |
| `landing.how.steps.0.title` | Botni oching | Откройте бота |
| `landing.how.steps.0.text` | Telegramda «Start» tugmasini bosing va interfeys tilini tanlang. Hech narsa o'rnatish shart emas. | Нажмите «Старт» в Telegram и выберите язык интерфейса. Ничего устанавливать не нужно. |
| `landing.how.steps.1.title` | Kartochkalar bilan o'rganing | Учите по карточкам |
| `landing.how.steps.1.text` | Mini-ilova Telegramning o'zida ochiladi: so'z, tarjima, misol va ovozli talaffuz. | Мини-приложение открывается прямо в Telegram: слово, перевод, пример и озвучка. |
| `landing.how.steps.2.title` | O'z vaqtida takrorlang | Повторяйте вовремя |
| `landing.how.steps.2.text` | Intervalli takrorlash so'zni yangilash vaqtini tanlaydi, bot esa eslatma yuboradi. | Интервальные повторения подбирают момент, когда слово пора освежить, а бот присылает напоминание. |
| `landing.features.title` | Ichida nimalar bor | Что внутри |
| `landing.features.items.0.title` | Bot va mini-ilova | Бот и мини-приложение |
| `landing.features.items.0.text` | Bot - boshlash va eslatmalar uchun, mini-ilova - mashg'ulotlar uchun. Hammasi bitta messenjerda. | Бот — для старта и напоминаний, мини-приложение — для самих занятий. Всё в одном мессенджере. |
| `landing.features.items.1.title` | Aqlli takrorlash | Умные повторения |
| `landing.features.items.1.text` | Har bir so'z tobora uzayib boruvchi oraliqlarda qaytadi - shu tarzda u uzoq muddatli xotiraga o'tadi. | Каждое слово возвращается через растущие промежутки времени — так оно переходит в долговременную память. |
| `landing.features.items.2.title` | Ovoz va misollar | Озвучка и примеры |
| `landing.features.items.2.text` | Talaffuzni tinglang va so'z gapda qanday ishlatilishini ko'ring. | Слушайте произношение и смотрите, как слово употребляется в предложении. |
| `landing.features.items.3.title` | O'zbek va rus tillari | Узбекский и русский |
| `landing.features.items.3.text` | Bot va mini-ilova interfeysi o'zbek va rus tillarida mavjud. | Интерфейс бота и мини-приложения доступен на русском и узбекском языках. |
| `landing.pricing.title` | Tariflar | Тарифы |
| `landing.pricing.subtitle` | Bepul boshlang. Pullik imkoniyatlar keyinroq paydo bo'ladi - bunda yutuqlaringiz saqlanib qoladi. | Начните бесплатно. Платные возможности появятся позже — прогресс при этом сохранится. |
| `landing.pricing.soon` | Tez orada | Скоро |
| `landing.pricing.free.name` | Free | Free |
| `landing.pricing.free.tagline` | Kundalik mashq uchun | Для ежедневной практики |
| `landing.pricing.free.features.0` | Ommaviy kurslar | Публичные курсы |
| `landing.pricing.free.features.1` | Kuniga 20 ta yangi so'z | 20 новых слов в день |
| `landing.pricing.free.features.2` | Cheklovsiz takrorlash | Повторения без ограничений |
| `landing.pricing.free.features.3` | Bot va mini-ilova | Бот и мини-приложение |
| `landing.pricing.pro.name` | Pro | Pro |
| `landing.pricing.pro.tagline` | Ko'proq istaganlar uchun | Для тех, кто хочет больше |
| `landing.pricing.pro.features.0` | Yangi so'zlar uchun kunlik limitsiz | Без дневного лимита новых слов |
| `landing.pricing.pro.features.1` | Mashqlarning ko'proq turlari | Больше типов упражнений |
| `landing.pricing.pro.features.2` | Mashq testlari | Тренировочные тесты |
| `landing.pricing.pro.features.3` | Batafsil statistika | Подробная статистика |
| `landing.pricing.pro.note` | Pro tarkibi va narxlari hozircha aniqlanmoqda. | Состав и цены Pro пока уточняются. |
| `landing.pricing.centers.name` | O'quv markazlari uchun | Для учебных центров |
| `landing.pricing.centers.tagline` | Oyiga bir marta qat'iy to'lov | Фиксированная оплата раз в месяц |
| `landing.pricing.centers.features.0` | Markaz oyiga bir marta qat'iy summa to'laydi | Центр платит фиксированную сумму раз в месяц |
| `landing.pricing.centers.features.1` | Markaz o'quvchilari markazda ekan, to'liq Pro oladi | Все студенты центра получают полный Pro, пока состоят в центре |
| `landing.pricing.centers.features.2` | Telegramdagi guruhlar, o'qituvchilar uchun mashg'ulot va imtihonlar | Группы в Telegram, тренировки и экзамены для учителей |
| `landing.pricing.centers.contact` | Markazni ulash: | Подключить центр: |
| `landing.footer.tagline` | LexiFlow - Telegramda ingliz tili. | LexiFlow — английский в Telegram. |
| `landing.footer.contact` | Qo'llab-quvvatlash: | Поддержка: |
| `landing.footer.language` | Til | Язык |
| `landing.footer.legal_label` | Hujjatlar | Документы |
| `legal.draft_notice` | Qoralama. Ushbu hujjat matni xizmat egasi tomonidan taqdim etiladi; sahifa hozircha amaldagi hujjat hisoblanmaydi. | Черновик. Текст этого документа будет предоставлен владельцем сервиса; страница пока не является действующим документом. |
| `legal.placeholder` | Bo'lim matni xizmat egasi tomonidan taqdim etiladi. | Текст раздела будет предоставлен владельцем. |
| `legal.back` | Bosh sahifaga | На главную |
| `legal.offer.title` | Ommaviy oferta | Публичная оферта |
| `legal.offer.sections.0` | Umumiy qoidalar | Общие положения |
| `legal.offer.sections.1` | Shartnoma predmeti | Предмет договора |
| `legal.offer.sections.2` | Ro'yxatdan o'tish va hisob | Регистрация и учётная запись |
| `legal.offer.sections.3` | Tariflar, to'lov va qaytarish tartibi | Тарифы, порядок оплаты и возврата |
| `legal.offer.sections.4` | Tomonlarning huquq va majburiyatlari | Права и обязанности сторон |
| `legal.offer.sections.5` | Tomonlarning javobgarligi | Ответственность сторон |
| `legal.offer.sections.6` | Shaxsiy ma'lumotlar | Персональные данные |
| `legal.offer.sections.7` | Nizolarni hal qilish | Разрешение споров |
| `legal.offer.sections.8` | Amal qilish muddati va shartlarni o'zgartirish | Срок действия и изменение условий |
| `legal.offer.sections.9` | Ijrochining rekvizitlari | Реквизиты исполнителя |
| `legal.privacy.title` | Maxfiylik siyosati | Политика конфиденциальности |
| `legal.privacy.sections.0` | Umumiy qoidalar | Общие положения |
| `legal.privacy.sections.1` | Qanday ma'lumotlarni qayta ishlaymiz | Какие данные мы обрабатываем |
| `legal.privacy.sections.2` | Qayta ishlash maqsadlari va asoslari | Цели и основания обработки |
| `legal.privacy.sections.3` | Ma'lumotlarni uchinchi shaxslarga berish | Передача данных третьим лицам |
| `legal.privacy.sections.4` | Ma'lumotlarni saqlash va himoya qilish | Хранение и защита данных |
| `legal.privacy.sections.5` | Foydalanuvchi huquqlari | Права пользователя |
| `legal.privacy.sections.6` | Cookie fayllar va analitika | Файлы cookie и аналитика |
| `legal.privacy.sections.7` | Siyosatni o'zgartirish | Изменение политики |
| `legal.privacy.sections.8` | Ma'lumotlar operatorining aloqa ma'lumotlari | Контакты оператора данных |

## TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `common.close` | Yopish | Закрыть |
| `common.loading` | Yuklanmoqda… | Загрузка… |
| `common.retry` | Qayta urinish | Повторить |
| `common.error_title` | Xatolik | Ошибка |
| `landing.outside_telegram` | Bu sahifa faqat Telegram Web App ichida ishlaydi. O'qituvchidan mashg'ulot havolasini guruhga yuborishini so'rang. | Эта страница работает только внутри Telegram Web App. Попросите учителя прислать ссылку-тренировку в группу. |
| `card.kind.new` | Yangi so'z | Новое слово |
| `card.kind.learning` | Mustahkamlash | Закрепление |
| `card.kind.due` | Takrorlash | Повторение |
| `card.speak` | Talaffuz qilish | Произнести |
| `card.reveal` | Tarjimani ko'rsatish | Показать перевод |
| `card.translation_fallback` | Mashg'ulot tilida tarjima hali yo'q | Перевода на язык занятия пока нет |
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
| `catalog.title` | Kurslar | Курсы |
| `catalog.open` | Kurslar katalogi | Каталог курсов |
| `catalog.empty` | Hozircha kurslar yo'q. Keyinroq qaytib ko'ring. | Курсов пока нет. Загляните позже. |
| `catalog.choose` | Tanlash | Выбрать |
| `catalog.active` | Faol | Активный |
| `catalog.words.one` | {count} ta so'z | {count} слово |
| `catalog.words.few` | _(в uz этой формы нет — используется `other`)_ | {count} слова |
| `catalog.words.many` | _(в uz этой формы нет — используется `other`)_ | {count} слов |
| `catalog.words.other` | {count} ta so'z | {count} слова |
| `catalog.progress` | {total} tadan {done} tasi o'rganildi | Изучено {done} из {total} |
| `catalog.goal.general` | Umumiy ingliz tili | Общий английский |
| `catalog.goal.school` | Maktab | Школа |
| `catalog.goal.ielts` | IELTS | IELTS |
| `catalog.goal.sat` | SAT | SAT |
| `course.back` | Kurslarga | К курсам |
| `course.module` | {number}-modul | Модуль {number} |
| `course.lesson` | {number}-dars | Урок {number} |
| `course.no_lessons` | Kursda hali darslar yo'q. | В курсе пока нет уроков. |
| `error.closed.catalog` | Kurs endi mavjud emas. | Курс больше недоступен. |
| `error.forbidden.catalog` | Sizda kurslar katalogiga kirish huquqi yo'q. | У вас нет доступа к каталогу курсов. |
| `error.code.course_not_found` | Kurs topilmadi yoki mavjud emas. | Курс не найден или недоступен. |
| `error.code.timezone_change_too_soon` | Vaqt mintaqasini kuniga bir martadan ko'p o'zgartirib bo'lmaydi. | Часовой пояс можно менять не чаще раза в сутки. |
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

## Фаза 1 · задача 1.4a (промокоды) — на проверку носителю

### Бот (`lang/uz/bot.php`)

| Ключ | uz | ru |
|---|---|---|
| `bot.help.private` (добавлена строка) | Pro uchun promokod: /promo KOD | Промокод на Pro: /promo КОД |
| `bot.help.private` (добавлены строки, 1.5a) | Bugungi takrorlashlar: /today<br>Sozlamalar (til, maqsad, daraja, vaqt mintaqasi, eslatmalar): /settings | Повторения на сегодня: /today<br>Настройки (язык, цель, уровень, часовой пояс, напоминания): /settings |
| `bot.promo.usage` | Promokodni quyidagicha yuboring: /promo KOD | Отправьте промокод так: /promo КОД |
| `bot.promo.use_private` | Promokodni guruhda ko'rsatmaslik yaxshi. Bot bilan shaxsiy chatni oching va /promo KOD yuboring. | Промокод лучше не показывать в группе. Откройте личный чат с ботом и отправьте /promo КОД. |
| `bot.promo.blocked` | Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling. | Доступ к аккаунту ограничен. Обратитесь в поддержку. |
| `bot.promo.rate_limited` | Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring. | Слишком много попыток. Подождите минуту и повторите. |
| `bot.promo.success_until` | Tayyor! LexiFlow Pro :date gacha faol. | Готово! LexiFlow Pro активен до :date. |
| `bot.promo.success_forever` | Tayyor! LexiFlow Pro muddatsiz faol. | Готово! LexiFlow Pro активен без ограничения по сроку. |
| `bot.promo.errors.promo_not_found` | Promokod topilmadi. Xato yozilmaganini tekshiring. | Промокод не найден. Проверьте, нет ли опечатки. |
| `bot.promo.errors.promo_expired` | Promokodning amal qilish muddati tugagan. | Срок действия промокода истёк. |
| `bot.promo.errors.promo_exhausted` | Bu promokoddan foydalanish limiti tugagan. | Этим промокодом уже воспользовалось максимальное число людей. |
| `bot.promo.errors.promo_already_redeemed` | Siz bu promokodni allaqachon faollashtirgansiz. | Вы уже активировали этот промокод. |
| `bot.promo.errors.promo_not_applicable` | Sizda allaqachon muddatsiz Pro bor — promokod kerak emas. | У вас уже есть Pro без ограничения по сроку — промокод не нужен. |
| `bot.promo.errors.plan_unknown` | Bu promokod hozircha mavjud emas. Qo'llab-quvvatlash xizmatiga yozing. | Этот промокод сейчас недоступен. Напишите в поддержку. |

### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `promo.toggle` | Promokodingiz bormi? | Есть промокод? |
| `promo.placeholder` | Promokod | Промокод |
| `promo.submit` | Faollashtirish | Активировать |
| `promo.success_until` | Pro {date} gacha faol. | Pro активен до {date}. |
| `promo.success_forever` | Pro muddatsiz faol. | Pro активен без ограничения по сроку. |
| `paywall.title` | Pro tez orada | Pro скоро |
| `paywall.lead_quota` | Bugungi yangi so'zlar limiti tugadi — takrorlash cheklovsiz. | Лимит новых слов на сегодня исчерпан — повторения доступны без ограничений. |
| `paywall.soon` | Pro tez orada ochiladi. | Pro скоро откроется. |
| `paywall.free` | Hozir sizda Free tarif: kuniga {count} ta yangi so'z, takrorlash cheklovsiz. | Сейчас у вас тариф Free: {count} новых слов в день, повторения — без лимита. |
| `paywall.free_generic` | Hozir sizda Free tarif: yangi so'zlar kunlik limit bilan cheklangan, takrorlash cheklovsiz. | Сейчас у вас тариф Free: новые слова ограничены дневным лимитом, повторения — без лимита. |
| `paywall.contact` | Markazda o'qiysizmi? Markaz administratoridan LexiFlow Pro'ni ulashni so'rang: | Учитесь в центре? Попросите администратора центра подключить LexiFlow Pro: |
| `paywall.dismiss` | Tushunarli | Понятно |
| `paywall.open` | Pro haqida bilib oling | Узнать о Pro |
| `error.code.promo_not_found` | Promokod topilmadi. Xato yozilmaganini tekshiring. | Промокод не найден. Проверьте, нет ли опечатки. |
| `error.code.promo_expired` | Promokodning amal qilish muddati tugagan. | Срок действия промокода истёк. |
| `error.code.promo_exhausted` | Bu promokoddan foydalanish limiti tugagan. | Этим промокодом уже воспользовалось максимальное число людей. |
| `error.code.promo_already_redeemed` | Siz bu promokodni allaqachon faollashtirgansiz. | Вы уже активировали этот промокод. |
| `error.code.promo_not_applicable` | Sizda allaqachon muddatsiz Pro bor — promokod kerak emas. | У вас уже есть Pro без ограничения по сроку — промокод не нужен. |
| `error.code.plan_unknown` | Bu promokod hozircha mavjud emas. Qo'llab-quvvatlash xizmatiga yozing. | Этот промокод сейчас недоступен. Напишите в поддержку. |

Проверить: термин «promokod», «faollashtirish» (активировать), «limiti tugagan» (исчерпан лимит).

## Фаза 1 · задача 1.5a (онбординг в боте) — на проверку носителю

> `lang/uz/onboarding.php`: пять шагов `/start` (язык, цель, уровень, часовой пояс, время напоминаний), экран «Сегодня» (`/today`), краткое меню, `/settings`.
> Из `bot.start.not_linked` убрана строка «аккаунт не привязан к учителю»: неизвестный человек в ЛС теперь проходит онбординг (в списке выше она больше не показана).
> Названия языков в выборе языка (`config/localization.php` → `names`) не переводятся: «🇷🇺 Русский» и «🇺🇿 O'zbekcha» одинаковы в обоих языках.
> В подписях часовых поясов `:offset` — смещение вида `UTC+5`; в `options.reminder.time` `:time` — `08:00`, `13:00`, `19:00`, `21:00`.
> Проверить в первую очередь: «Maqsad» (цель), «Daraja» (уровень), «Vaqt mintaqasi» (часовой пояс), «Eslatmalar» (напоминания), уровни `A1 — boshlayapman` / `B2 — o'rtadan yuqori`, названия городов (Almati, Ashxobod, Moskva).

### Бот (`lang/uz/onboarding.php`)

| Ключ | uz | ru |
|---|---|---|
| `onboarding.blocked` | Akkauntingizga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling. | Доступ к аккаунту ограничен. Обратитесь в поддержку. |
| `onboarding.steps.locale` | Interfeys tilini tanlang | Выберите язык интерфейса |
| `onboarding.steps.goal` | Ingliz tilini nima uchun o'rganmoqchisiz? | Для чего вам английский? |
| `onboarding.steps.level` | Darajangiz qanday? O'zingizni baholang — keyinroq o'zgartirish mumkin. | Какой у вас уровень? Оцените себя как получится — позже его можно изменить. |
| `onboarding.steps.timezone` | Qaysi vaqt mintaqasida yashaysiz? Eslatmalar o'z vaqtida kelishi uchun. | В каком часовом поясе вы живёте? Так напоминания придут вовремя. |
| `onboarding.steps.reminder` | So'zlarni takrorlashni qachon eslatib turay? | Когда напоминать о повторении слов? |
| `onboarding.options.goal.general` | Umumiy ingliz tili | Общий английский |
| `onboarding.options.goal.ielts` | IELTS | IELTS |
| `onboarding.options.goal.sat` | SAT | SAT |
| `onboarding.options.goal.school` | Maktab | Школа |
| `onboarding.options.level.A1` | A1 — boshlayapman | A1 — начинаю |
| `onboarding.options.level.A2` | A2 — elementar | A2 — базовый |
| `onboarding.options.level.B1` | B1 — o'rta | B1 — средний |
| `onboarding.options.level.B2` | B2 — o'rtadan yuqori | B2 — выше среднего |
| `onboarding.options.level.unknown` | Bilmayman | Не знаю |
| `onboarding.options.timezone.tashkent` | Toshkent (:offset) | Ташкент (:offset) |
| `onboarding.options.timezone.almaty` | Almati (:offset) | Алматы (:offset) |
| `onboarding.options.timezone.bishkek` | Bishkek (:offset) | Бишкек (:offset) |
| `onboarding.options.timezone.dushanbe` | Dushanbe (:offset) | Душанбе (:offset) |
| `onboarding.options.timezone.ashgabat` | Ashxobod (:offset) | Ашхабад (:offset) |
| `onboarding.options.timezone.moscow` | Moskva (:offset) | Москва (:offset) |
| `onboarding.options.timezone.istanbul` | Istanbul (:offset) | Стамбул (:offset) |
| `onboarding.options.reminder.time` | :time | :time |
| `onboarding.options.reminder.off` | Eslatmasiz | Без напоминаний |
| `onboarding.done.text` | Tayyor! Kursingiz: :course. Tugmani bosing — birinchi mashg'ulot bir necha daqiqa oladi. | Готово! Ваш курс: :course. Нажмите кнопку — первая тренировка займёт пару минут. |
| `onboarding.done.text_plain` | Tayyor! Tugmani bosing — birinchi mashg'ulot bir necha daqiqa oladi. | Готово! Нажмите кнопку — первая тренировка займёт пару минут. |
| `onboarding.done.button` | 🎯 Birinchi mashg'ulotni boshlash | 🎯 Начать первую тренировку |
| `onboarding.course_link.text` | «:course» kursi LexiFlow'da mavjud. Uni quyidagi tugma bilan oching — o'zingiz tanlamaguningizcha joriy kurs o'zgarmaydi. | Курс «:course» доступен в LexiFlow. Откройте его кнопкой ниже — текущий курс не изменится, пока вы сами его не выберете. |
| `onboarding.course_link.button` | Kursni ochish | Открыть курс |
| `onboarding.today.due` | Bugun takrorlash uchun: :count ta so'z. | Сегодня к повторению: :count слово.\|Сегодня к повторению: :count слова.\|Сегодня к повторению: :count слов. |
| `onboarding.today.empty` | Bugun takrorlanadigan so'z yo'q. Yangi so'zlarni o'rganish uchun LexiFlow'ni oching. | На сегодня повторений нет. Откройте LexiFlow, чтобы выучить новые слова. |
| `onboarding.today.button` | LexiFlow'ni ochish | Открыть LexiFlow |
| `onboarding.menu.text` | Keyingi qadam?<br>/today — bugungi takrorlashlar<br>/settings — sozlamalar<br>/promo KOD — Pro uchun promokod<br>/help — yordam | Что дальше?<br>/today — повторения на сегодня<br>/settings — настройки<br>/promo КОД — промокод на Pro<br>/help — справка |
| `onboarding.settings.menu` | Nimani o'zgartirmoqchisiz? | Что хотите изменить? |
| `onboarding.settings.saved` | Saqlandi. | Сохранено. |
| `onboarding.settings.timezone_too_soon` | Vaqt mintaqasini kuniga bir martadan ko'p o'zgartirib bo'lmaydi. Keyingi o'zgartirish — :when dan keyin (joriy vaqtingiz bo'yicha). | Часовой пояс можно менять не чаще одного раза в сутки. Следующая смена — после :when (по вашему текущему времени). |
| `onboarding.settings.buttons.locale` | Til | Язык |
| `onboarding.settings.buttons.goal` | Maqsad | Цель |
| `onboarding.settings.buttons.level` | Daraja | Уровень |
| `onboarding.settings.buttons.timezone` | Vaqt mintaqasi | Часовой пояс |
| `onboarding.settings.buttons.reminder` | Eslatmalar | Напоминания |

## Фаза 1 · задача 1.3a C (личные сессии) — на проверку носителю

### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `course.learn` (кнопка у урока) | O'rganish | Учить |
| `training.daily_header` | Takrorlash | Повторение |
| `finished.reason.lesson_complete` | Darsdagi barcha so'zlar o'tildi. | Все слова урока пройдены. |
| `finished.reason.nothing_due` | Bugun takrorlaydigan narsa yo'q. | На сегодня повторять нечего. |
| `finished.reason.new_quota_exhausted` | Bugungi yangi so'zlar limiti tugadi. Yangi so'zlar ertaga ochiladi. | Лимит новых слов на сегодня исчерпан. Новые слова откроются завтра. |
| `finished.reason.session_cap` | Bu uzun mashg'ulot bo'ldi — biroz dam oling. | Это была длинная сессия — сделайте перерыв. |
| `error.code.out_of_scope` (было `wrong_lesson`) | Bu so'z ushbu mashg'ulotga tegishli emas. | Это слово не относится к этой тренировке. |
| `error.code.lesson_mismatch` | Bu rejim tanlangan darsga mos kelmaydi. | Этот режим не подходит к выбранному уроку. |
| `error.code.invalid_mode` | Noma'lum mashg'ulot rejimi. | Неизвестный режим тренировки. |

Ключ `error.code.wrong_lesson` удалён (код ошибки переименован в `out_of_scope`).

Проверить: «O'rganish» (учить) на кнопке рядом с «Takrorlash» (повторять); «mashg'ulot» (тренировка/сессия) — как в остальных текстах.

## Фаза 1 · задача 1.3b C (экран «Сегодня») — на проверку носителю

### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `today.title` | Bugun | Сегодня |
| `today.plan.free` | Free | Free |
| `today.plan.pro` | Pro | Pro |
| `today.goal.title` | Kunlik maqsad | Цель дня |
| `today.goal.progress` | {target} tadan {done} tasi | {done} из {target} |
| `today.goal.met` | Maqsad bajarildi | Цель выполнена |
| `today.goal.left.one` | {count} ta kartochka qoldi | Осталась {count} карточка |
| `today.goal.left.few` | _(в uz этой формы нет — используется `other`)_ | Осталось {count} карточки |
| `today.goal.left.many` | _(в uz этой формы нет — используется `other`)_ | Осталось {count} карточек |
| `today.goal.left.other` | {count} ta kartochka qoldi | Осталось {count} карточки |
| `today.streak.title` | Seriya | Серия |
| `today.streak.days.one` | {count} kun | {count} день |
| `today.streak.days.few` | _(в uz этой формы нет — используется `other`)_ | {count} дня |
| `today.streak.days.many` | _(в uz этой формы нет — используется `other`)_ | {count} дней |
| `today.streak.days.other` | {count} kun | {count} дня |
| `today.streak.none` | Seriyani boshlash uchun kunlik maqsadni bajaring | Выполните цель дня, чтобы начать серию |
| `today.streak.at_risk` | Seriya xavf ostida: maqsadni yarim tungacha bajaring | Серия под угрозой: выполните цель до полуночи |
| `today.streak.safe` | Bugungi kun uchun seriya uzaytirildi | На сегодня серия продлена |
| `today.streak.best` | Rekord: {best} | Рекорд: {best} |
| `today.review.title` | Takrorlash | Повторение |
| `today.review.due.one` | Takrorlash uchun {count} ta so'z | {count} слово к повторению |
| `today.review.due.few` | _(в uz этой формы нет — используется `other`)_ | {count} слова к повторению |
| `today.review.due.many` | _(в uz этой формы нет — используется `other`)_ | {count} слов к повторению |
| `today.review.due.other` | Takrorlash uchun {count} ta so'z | {count} слова к повторению |
| `today.review.none` | Hozircha takrorlaydigan narsa yo'q | Повторять пока нечего |
| `today.review.start` | Takrorlash | Повторить |
| `today.review.continue` | Davom etish | Продолжить |
| `today.new.remaining` | Bugun yana {count} ta yangi so'z o'rganish mumkin | Новых слов на сегодня осталось: {count} |
| `today.new.exhausted` | Bugungi yangi so'zlar limiti tugadi | Лимит новых слов на сегодня исчерпан |
| `today.course.title` | Kurs | Курс |
| `today.course.next` | Keyingi dars | Следующий урок |
| `today.course.completed` | Kursdagi barcha so'zlar ko'rsatildi | Все слова курса уже показаны |
| `today.course.none` | Yangi so'zlarni o'rganish uchun kursni tanlang | Выберите курс, чтобы учить новые слова |
| `today.onboarding_hint` | Sozlashni yakunlang: botga /start yuboring | Завершите настройку: отправьте боту /start |
| `finished.home` | Bosh sahifaga | На главную |
| `finished.goal` | Kunlik maqsad: {target} tadan {done} tasi | Цель дня: {done} из {target} |
| `finished.goal_met` | Kunlik maqsad bajarildi! | Цель дня выполнена! |
| `error.closed.today` | Bugun ekrani endi mavjud emas. | Экран «Сегодня» больше недоступен. |
| `error.forbidden.today` | Sizda Bugun ekraniga kirish huquqi yo'q. | У вас нет доступа к экрану «Сегодня». |

Удалены ключи `landing.in_telegram` и `landing.review_all`: стартовый экран в Telegram теперь сразу ведёт на «Сегодня».

Проверить: «Bugun» как название экрана (вместе с «Bugun» = «Сегодня» в `finished.next_today`); «Seriya» (серия дней подряд с выполненной целью) и «Rekord»; «Kunlik maqsad» (цель дня) и форму «{target} tadan {done} tasi» ("X из Y"); «xavf ostida» (под угрозой) в `today.streak.at_risk`; `today.goal.left` — «N ta kartochka qoldi» (осталось N карточек); `today.course.completed` («barcha so'zlar ko'rsatildi» — все слова показаны, не «выучены»).

## Фаза 1 · задача 1.5b (напоминания по локальному времени) — на проверку носителю

### Бот (`lang/uz/reminders.php`)

| Ключ | uz | ru |
|---|---|---|
| `reminders.streak_at_risk` | Seriyangiz — :count kun. Uni uzmaslik uchun bugungi maqsadni bajaring. | Ваша серия — :count день. Выполните цель дня, чтобы не прервать её.\|Ваша серия — :count дня. …\|Ваша серия — :count дней. … |

`:count` — длина серии в днях; в ru три формы числа, в uz одна. Ключи `reminders.review` и `reminders.open_button` без изменений. Проверить: «Seriyangiz» (ваша серия — согласовано с «Seriya» на экране «Сегодня»), «uzmaslik» (не прервать), «bugungi maqsad» (цель дня — тот же термин, что `today.goal.title` «Kunlik maqsad»; если носителю ближе «kunlik maqsadni», заменить).

## Фаза 1 · задача 1.6 (настройки) — на проверку носителю

### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `settings.title` | Sozlamalar | Настройки |
| `settings.language` | Interfeys tili | Язык интерфейса |
| `settings.timezone` | Vaqt mintaqasi | Часовой пояс |
| `settings.timezone.locked` | Vaqt mintaqasini kuniga bir martadan ko'p o'zgartirib bo'lmaydi. Keyingi o'zgartirish — {when}. | Часовой пояс можно менять не чаще раза в сутки. Следующая смена — {when}. |
| `settings.reminder` | Eslatmalar | Напоминания |
| `settings.reminder.off` | Eslatmalarsiz | Без напоминаний |
| `settings.reminder.hint` | Vaqt sizning vaqt mintaqangiz bo'yicha. | Время — по вашему часовому поясу. |
| `settings.daily_goal` | Kunlik maqsad | Цель на день |
| `settings.daily_goal.option.one/other` | {count} ta kartochka | {count} карточка / карточки / карточек |
| `settings.course` | Faol kurs | Активный курс |
| `settings.course.none` | Kurs tanlanmagan | Курс не выбран |
| `settings.saved` | Saqlandi | Сохранено |
| `error.closed.settings` | Sozlamalar endi mavjud emas. | Настройки больше недоступны. |
| `error.forbidden.settings` | Sizda sozlamalarga kirish huquqi yo'q. | У вас нет доступа к настройкам. |

Проверить: «Sozlamalar» (настройки), «Interfeys tili» (язык интерфейса), «Vaqt mintaqasi» (часовой пояс), «Eslatmalar»/«Eslatmalarsiz» (напоминания / без напоминаний), «Faol kurs» (активный курс). Названия городов в списке часовых поясов приходят с сервера из `lang/uz/onboarding.php` (проверены в 1.5a).



## Финальные правки Фазы 1 — на проверку носителю

### Пропущенные ранее ключи

#### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `paywall.review` | So'zlarni takrorlash | Повторить слова |
| `training.restart` | Qaytadan boshlash | Начать заново |
| `error.code.learner_not_found` | Profilingiz topilmadi. Ilovani Telegram orqali qaytadan oching. | Ваш профиль не найден. Откройте приложение заново из Telegram. |
| `error.code.learner_blocked` | Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling. | Доступ к аккаунту ограничен. Обратитесь в поддержку. |
| `error.code.not_a_member` | Siz bu guruh a'zosi emassiz. Guruhga qo'shiling va havolani qaytadan oching. | Вы не состоите в этой группе. Вступите в группу и откройте ссылку снова. |
| `error.code.missing_token` | Siz tizimga kirmagansiz. Ilovani Telegram orqali qaytadan oching. | Вы не авторизованы. Откройте приложение заново из Telegram. |

#### 2FA (`lang/uz/twofactor.php`)

| Ключ | uz | ru |
|---|---|---|
| `twofactor.recovery.intro_emphasis` | faqat bir marta | один раз |

В `recovery.intro` слово подставляется вместо `:emphasis` и выделяется жирным: «Har bir kod **faqat bir marta** amal qiladi». Проверить, что фраза читается целиком.

### validation — на проверку носителю (`lang/uz/validation.php`)

Стандартные сообщения валидации Laravel 12 (ответы 422 API). `:attribute` — название поля (подставляется из `validation.attributes.*` ниже либо имя поля как есть); остальные `:x` — значения. Узбекский — латиница, апостроф ASCII, «maydoni» = «поле». Ключи с плюралами (`min.string` и т.д.) — разные типы значения, не формы числа.

| Ключ | uz | ru |
|---|---|---|
| `validation.accepted` | :attribute maydoni qabul qilinishi kerak. | Поле :attribute должно быть принято. |
| `validation.accepted_if` | :other :value bo'lganda :attribute maydoni qabul qilinishi kerak. | Поле :attribute должно быть принято, когда :other равно :value. |
| `validation.active_url` | :attribute maydoni to'g'ri URL manzil bo'lishi kerak. | Поле :attribute должно быть корректным URL-адресом. |
| `validation.after` | :attribute maydoni :date dan keyingi sana bo'lishi kerak. | Поле :attribute должно быть датой после :date. |
| `validation.after_or_equal` | :attribute maydoni :date yoki undan keyingi sana bo'lishi kerak. | Поле :attribute должно быть датой не раньше :date. |
| `validation.alpha` | :attribute maydoni faqat harflardan iborat bo'lishi kerak. | Поле :attribute может содержать только буквы. |
| `validation.alpha_dash` | :attribute maydoni faqat harflar, raqamlar, defis va pastki chiziqdan iborat bo'lishi kerak. | Поле :attribute может содержать только буквы, цифры, дефисы и подчёркивания. |
| `validation.alpha_num` | :attribute maydoni faqat harflar va raqamlardan iborat bo'lishi kerak. | Поле :attribute может содержать только буквы и цифры. |
| `validation.any_of` | :attribute maydoni noto'g'ri to'ldirilgan. | Поле :attribute заполнено неверно. |
| `validation.array` | :attribute maydoni massiv bo'lishi kerak. | Поле :attribute должно быть массивом. |
| `validation.ascii` | :attribute maydoni faqat bir baytli harflar, raqamlar va belgilardan iborat bo'lishi kerak. | Поле :attribute может содержать только однобайтовые буквы, цифры и символы. |
| `validation.before` | :attribute maydoni :date dan oldingi sana bo'lishi kerak. | Поле :attribute должно быть датой до :date. |
| `validation.before_or_equal` | :attribute maydoni :date yoki undan oldingi sana bo'lishi kerak. | Поле :attribute должно быть датой не позже :date. |
| `validation.between.array` | :attribute maydonida :min dan :max gacha element bo'lishi kerak. | Поле :attribute должно содержать от :min до :max элементов. |
| `validation.between.file` | :attribute maydonidagi fayl hajmi :min dan :max kilobaytgacha bo'lishi kerak. | Размер файла в поле :attribute должен быть от :min до :max килобайт. |
| `validation.between.numeric` | :attribute maydoni :min va :max oralig'ida bo'lishi kerak. | Поле :attribute должно быть между :min и :max. |
| `validation.between.string` | :attribute maydoni uzunligi :min dan :max belgigacha bo'lishi kerak. | Длина поля :attribute должна быть от :min до :max символов. |
| `validation.boolean` | :attribute maydoni true yoki false bo'lishi kerak. | Поле :attribute должно быть true или false. |
| `validation.can` | :attribute maydonida ruxsat etilmagan qiymat bor. | Поле :attribute содержит недопустимое значение. |
| `validation.confirmed` | :attribute maydoni tasdig'i mos kelmadi. | Подтверждение поля :attribute не совпадает. |
| `validation.contains` | :attribute maydonida majburiy qiymat yo'q. | В поле :attribute отсутствует обязательное значение. |
| `validation.current_password` | Parol noto'g'ri. | Неверный пароль. |
| `validation.date` | :attribute maydoni to'g'ri sana bo'lishi kerak. | Поле :attribute должно быть корректной датой. |
| `validation.date_equals` | :attribute maydoni :date ga teng sana bo'lishi kerak. | Поле :attribute должно быть датой, равной :date. |
| `validation.date_format` | :attribute maydoni :format formatiga mos bo'lishi kerak. | Поле :attribute должно соответствовать формату :format. |
| `validation.decimal` | :attribute maydonida verguldan keyin :decimal ta raqam bo'lishi kerak. | Поле :attribute должно содержать :decimal знаков после запятой. |
| `validation.declined` | :attribute maydoni rad etilishi kerak. | Поле :attribute должно быть отклонено. |
| `validation.declined_if` | :other :value bo'lganda :attribute maydoni rad etilishi kerak. | Поле :attribute должно быть отклонено, когда :other равно :value. |
| `validation.different` | :attribute va :other maydonlari bir-biridan farq qilishi kerak. | Поля :attribute и :other должны различаться. |
| `validation.digits` | :attribute maydoni :digits ta raqamdan iborat bo'lishi kerak. | Поле :attribute должно состоять из :digits цифр. |
| `validation.digits_between` | :attribute maydoni :min dan :max tagacha raqamdan iborat bo'lishi kerak. | Поле :attribute должно состоять из :min–:max цифр. |
| `validation.dimensions` | :attribute maydonidagi rasm o'lchamlari noto'g'ri. | Изображение в поле :attribute имеет недопустимые размеры. |
| `validation.distinct` | :attribute maydonida takrorlanuvchi qiymat bor. | Поле :attribute содержит повторяющееся значение. |
| `validation.doesnt_contain` | :attribute maydoni quyidagilardan birortasini o'z ichiga olmasligi kerak: :values. | Поле :attribute не должно содержать ничего из: :values. |
| `validation.doesnt_end_with` | :attribute maydoni quyidagilardan biri bilan tugamasligi kerak: :values. | Поле :attribute не должно заканчиваться одним из: :values. |
| `validation.doesnt_start_with` | :attribute maydoni quyidagilardan biri bilan boshlanmasligi kerak: :values. | Поле :attribute не должно начинаться с одного из: :values. |
| `validation.email` | :attribute maydoni to'g'ri elektron pochta manzili bo'lishi kerak. | Поле :attribute должно быть корректным адресом электронной почты. |
| `validation.encoding` | :attribute maydoni :encoding kodlashida bo'lishi kerak. | Поле :attribute должно быть в кодировке :encoding. |
| `validation.ends_with` | :attribute maydoni quyidagilardan biri bilan tugashi kerak: :values. | Поле :attribute должно заканчиваться одним из: :values. |
| `validation.enum` | Tanlangan :attribute qiymati yaroqsiz. | Выбрано недопустимое значение :attribute. |
| `validation.exists` | Tanlangan :attribute qiymati yaroqsiz. | Выбрано недопустимое значение :attribute. |
| `validation.extensions` | :attribute maydoni quyidagi kengaytmalardan biriga ega bo'lishi kerak: :values. | Поле :attribute должно иметь одно из расширений: :values. |
| `validation.file` | :attribute maydoni fayl bo'lishi kerak. | Поле :attribute должно быть файлом. |
| `validation.filled` | :attribute maydoni to'ldirilgan bo'lishi kerak. | Поле :attribute должно быть заполнено. |
| `validation.gt.array` | :attribute maydonida :value tadan ko'p element bo'lishi kerak. | Поле :attribute должно содержать больше :value элементов. |
| `validation.gt.file` | :attribute maydonidagi fayl hajmi :value kilobaytdan katta bo'lishi kerak. | Размер файла в поле :attribute должен быть больше :value килобайт. |
| `validation.gt.numeric` | :attribute maydoni :value dan katta bo'lishi kerak. | Поле :attribute должно быть больше :value. |
| `validation.gt.string` | :attribute maydoni uzunligi :value belgidan ko'p bo'lishi kerak. | Длина поля :attribute должна быть больше :value символов. |
| `validation.gte.array` | :attribute maydonida kamida :value ta element bo'lishi kerak. | Поле :attribute должно содержать не меньше :value элементов. |
| `validation.gte.file` | :attribute maydonidagi fayl hajmi kamida :value kilobayt bo'lishi kerak. | Размер файла в поле :attribute должен быть не меньше :value килобайт. |
| `validation.gte.numeric` | :attribute maydoni kamida :value bo'lishi kerak. | Поле :attribute должно быть не меньше :value. |
| `validation.gte.string` | :attribute maydoni uzunligi kamida :value belgi bo'lishi kerak. | Длина поля :attribute должна быть не меньше :value символов. |
| `validation.hex_color` | :attribute maydoni to'g'ri o'n oltilik rang kodi bo'lishi kerak. | Поле :attribute должно быть корректным цветом в шестнадцатеричном формате. |
| `validation.image` | :attribute maydoni rasm bo'lishi kerak. | Поле :attribute должно быть изображением. |
| `validation.in` | Tanlangan :attribute qiymati yaroqsiz. | Выбрано недопустимое значение :attribute. |
| `validation.in_array` | :attribute maydoni :other ichida mavjud bo'lishi kerak. | Значение поля :attribute должно присутствовать в :other. |
| `validation.in_array_keys` | :attribute maydonida quyidagi kalitlardan kamida bittasi bo'lishi kerak: :values. | Поле :attribute должно содержать хотя бы один из ключей: :values. |
| `validation.integer` | :attribute maydoni butun son bo'lishi kerak. | Поле :attribute должно быть целым числом. |
| `validation.ip` | :attribute maydoni to'g'ri IP manzil bo'lishi kerak. | Поле :attribute должно быть корректным IP-адресом. |
| `validation.ipv4` | :attribute maydoni to'g'ri IPv4 manzil bo'lishi kerak. | Поле :attribute должно быть корректным IPv4-адресом. |
| `validation.ipv6` | :attribute maydoni to'g'ri IPv6 manzil bo'lishi kerak. | Поле :attribute должно быть корректным IPv6-адресом. |
| `validation.json` | :attribute maydoni to'g'ri JSON satr bo'lishi kerak. | Поле :attribute должно быть корректной JSON-строкой. |
| `validation.list` | :attribute maydoni ro'yxat bo'lishi kerak. | Поле :attribute должно быть списком. |
| `validation.lowercase` | :attribute maydoni kichik harflarda bo'lishi kerak. | Поле :attribute должно быть в нижнем регистре. |
| `validation.lt.array` | :attribute maydonida :value tadan kam element bo'lishi kerak. | Поле :attribute должно содержать меньше :value элементов. |
| `validation.lt.file` | :attribute maydonidagi fayl hajmi :value kilobaytdan kichik bo'lishi kerak. | Размер файла в поле :attribute должен быть меньше :value килобайт. |
| `validation.lt.numeric` | :attribute maydoni :value dan kichik bo'lishi kerak. | Поле :attribute должно быть меньше :value. |
| `validation.lt.string` | :attribute maydoni uzunligi :value belgidan kam bo'lishi kerak. | Длина поля :attribute должна быть меньше :value символов. |
| `validation.lte.array` | :attribute maydonida :value tadan ko'p element bo'lmasligi kerak. | Поле :attribute должно содержать не больше :value элементов. |
| `validation.lte.file` | :attribute maydonidagi fayl hajmi :value kilobaytdan oshmasligi kerak. | Размер файла в поле :attribute должен быть не больше :value килобайт. |
| `validation.lte.numeric` | :attribute maydoni :value dan oshmasligi kerak. | Поле :attribute должно быть не больше :value. |
| `validation.lte.string` | :attribute maydoni uzunligi :value belgidan oshmasligi kerak. | Длина поля :attribute должна быть не больше :value символов. |
| `validation.mac_address` | :attribute maydoni to'g'ri MAC manzil bo'lishi kerak. | Поле :attribute должно быть корректным MAC-адресом. |
| `validation.max.array` | :attribute maydonida :max tadan ko'p element bo'lmasligi kerak. | Поле :attribute должно содержать не больше :max элементов. |
| `validation.max.file` | :attribute maydonidagi fayl hajmi :max kilobaytdan oshmasligi kerak. | Размер файла в поле :attribute должен быть не больше :max килобайт. |
| `validation.max.numeric` | :attribute maydoni :max dan oshmasligi kerak. | Поле :attribute должно быть не больше :max. |
| `validation.max.string` | :attribute maydoni uzunligi :max belgidan oshmasligi kerak. | Длина поля :attribute должна быть не больше :max символов. |
| `validation.max_digits` | :attribute maydonida :max tadan ko'p raqam bo'lmasligi kerak. | Поле :attribute должно содержать не больше :max цифр. |
| `validation.mimes` | :attribute maydoni quyidagi turdagi fayl bo'lishi kerak: :values. | Поле :attribute должно быть файлом одного из типов: :values. |
| `validation.mimetypes` | :attribute maydoni quyidagi turdagi fayl bo'lishi kerak: :values. | Поле :attribute должно быть файлом одного из типов: :values. |
| `validation.min.array` | :attribute maydonida kamida :min ta element bo'lishi kerak. | Поле :attribute должно содержать не меньше :min элементов. |
| `validation.min.file` | :attribute maydonidagi fayl hajmi kamida :min kilobayt bo'lishi kerak. | Размер файла в поле :attribute должен быть не меньше :min килобайт. |
| `validation.min.numeric` | :attribute maydoni kamida :min bo'lishi kerak. | Поле :attribute должно быть не меньше :min. |
| `validation.min.string` | :attribute maydoni uzunligi kamida :min belgi bo'lishi kerak. | Длина поля :attribute должна быть не меньше :min символов. |
| `validation.min_digits` | :attribute maydonida kamida :min ta raqam bo'lishi kerak. | Поле :attribute должно содержать не меньше :min цифр. |
| `validation.missing` | :attribute maydoni bo'lmasligi kerak. | Поле :attribute должно отсутствовать. |
| `validation.missing_if` | :other :value bo'lganda :attribute maydoni bo'lmasligi kerak. | Поле :attribute должно отсутствовать, когда :other равно :value. |
| `validation.missing_unless` | :other :value bo'lmasa, :attribute maydoni bo'lmasligi kerak. | Поле :attribute должно отсутствовать, если :other не равно :value. |
| `validation.missing_with` | :values ko'rsatilganda :attribute maydoni bo'lmasligi kerak. | Поле :attribute должно отсутствовать, когда указано :values. |
| `validation.missing_with_all` | :values ko'rsatilganda :attribute maydoni bo'lmasligi kerak. | Поле :attribute должно отсутствовать, когда указаны :values. |
| `validation.multiple_of` | :attribute maydoni :value ga karrali bo'lishi kerak. | Значение поля :attribute должно быть кратно :value. |
| `validation.not_in` | Tanlangan :attribute qiymati yaroqsiz. | Выбрано недопустимое значение :attribute. |
| `validation.not_regex` | :attribute maydoni formati noto'g'ri. | Поле :attribute имеет неверный формат. |
| `validation.numeric` | :attribute maydoni son bo'lishi kerak. | Поле :attribute должно быть числом. |
| `validation.password.letters` | :attribute maydonida kamida bitta harf bo'lishi kerak. | Поле :attribute должно содержать хотя бы одну букву. |
| `validation.password.mixed` | :attribute maydonida kamida bitta bosh va bitta kichik harf bo'lishi kerak. | Поле :attribute должно содержать хотя бы одну заглавную и одну строчную букву. |
| `validation.password.numbers` | :attribute maydonida kamida bitta raqam bo'lishi kerak. | Поле :attribute должно содержать хотя бы одну цифру. |
| `validation.password.symbols` | :attribute maydonida kamida bitta belgi bo'lishi kerak. | Поле :attribute должно содержать хотя бы один символ. |
| `validation.password.uncompromised` | Kiritilgan :attribute ma'lumotlar sizib chiqishida topilgan. Boshqasini tanlang. | Указанное значение :attribute обнаружено в утечке данных. Выберите другое. |
| `validation.present` | :attribute maydoni mavjud bo'lishi kerak. | Поле :attribute должно присутствовать. |
| `validation.present_if` | :other :value bo'lganda :attribute maydoni mavjud bo'lishi kerak. | Поле :attribute должно присутствовать, когда :other равно :value. |
| `validation.present_unless` | :other :value bo'lmasa, :attribute maydoni mavjud bo'lishi kerak. | Поле :attribute должно присутствовать, если :other не равно :value. |
| `validation.present_with` | :values ko'rsatilganda :attribute maydoni mavjud bo'lishi kerak. | Поле :attribute должно присутствовать, когда указано :values. |
| `validation.present_with_all` | :values ko'rsatilganda :attribute maydoni mavjud bo'lishi kerak. | Поле :attribute должно присутствовать, когда указаны :values. |
| `validation.prohibited` | :attribute maydoniga ruxsat berilmagan. | Поле :attribute запрещено. |
| `validation.prohibited_if` | :other :value bo'lganda :attribute maydoniga ruxsat berilmagan. | Поле :attribute запрещено, когда :other равно :value. |
| `validation.prohibited_if_accepted` | :other qabul qilinganda :attribute maydoniga ruxsat berilmagan. | Поле :attribute запрещено, когда :other принято. |
| `validation.prohibited_if_declined` | :other rad etilganda :attribute maydoniga ruxsat berilmagan. | Поле :attribute запрещено, когда :other отклонено. |
| `validation.prohibited_unless` | :other :values ichida bo'lmasa, :attribute maydoniga ruxsat berilmagan. | Поле :attribute запрещено, если :other не входит в :values. |
| `validation.prohibits` | :attribute maydoni :other ning mavjud bo'lishiga yo'l qo'ymaydi. | Поле :attribute не допускает одновременного указания :other. |
| `validation.regex` | :attribute maydoni formati noto'g'ri. | Поле :attribute имеет неверный формат. |
| `validation.required` | :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно для заполнения. |
| `validation.required_array_keys` | :attribute maydonida quyidagilar uchun yozuvlar bo'lishi kerak: :values. | Поле :attribute должно содержать записи для: :values. |
| `validation.required_if` | :other :value bo'lganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда :other равно :value. |
| `validation.required_if_accepted` | :other qabul qilinganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда :other принято. |
| `validation.required_if_declined` | :other rad etilganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда :other отклонено. |
| `validation.required_unless` | :other :values ichida bo'lmasa, :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, если :other не входит в :values. |
| `validation.required_with` | :values ko'rsatilganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда указано :values. |
| `validation.required_with_all` | :values ko'rsatilganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда указаны :values. |
| `validation.required_without` | :values ko'rsatilmaganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда не указано :values. |
| `validation.required_without_all` | :values dan birortasi ham ko'rsatilmaganda :attribute maydoni to'ldirilishi shart. | Поле :attribute обязательно, когда не указано ни одно из :values. |
| `validation.same` | :attribute maydoni :other bilan bir xil bo'lishi kerak. | Значение поля :attribute должно совпадать с :other. |
| `validation.size.array` | :attribute maydonida :size ta element bo'lishi kerak. | Поле :attribute должно содержать :size элементов. |
| `validation.size.file` | :attribute maydonidagi fayl hajmi :size kilobayt bo'lishi kerak. | Размер файла в поле :attribute должен быть равен :size килобайт. |
| `validation.size.numeric` | :attribute maydoni :size ga teng bo'lishi kerak. | Поле :attribute должно быть равно :size. |
| `validation.size.string` | :attribute maydoni uzunligi :size belgi bo'lishi kerak. | Длина поля :attribute должна быть равна :size символам. |
| `validation.starts_with` | :attribute maydoni quyidagilardan biri bilan boshlanishi kerak: :values. | Поле :attribute должно начинаться с одного из: :values. |
| `validation.string` | :attribute maydoni matn bo'lishi kerak. | Поле :attribute должно быть строкой. |
| `validation.timezone` | :attribute maydoni to'g'ri vaqt mintaqasi bo'lishi kerak. | Поле :attribute должно быть корректным часовым поясом. |
| `validation.unique` | Bunday :attribute allaqachon band. | Такое значение поля :attribute уже занято. |
| `validation.uploaded` | :attribute yuklanmadi. | Не удалось загрузить поле :attribute. |
| `validation.uppercase` | :attribute maydoni bosh harflarda bo'lishi kerak. | Поле :attribute должно быть в верхнем регистре. |
| `validation.url` | :attribute maydoni to'g'ri URL manzil bo'lishi kerak. | Поле :attribute должно быть корректным URL-адресом. |
| `validation.ulid` | :attribute maydoni to'g'ri ULID bo'lishi kerak. | Поле :attribute должно быть корректным ULID. |
| `validation.uuid` | :attribute maydoni to'g'ri UUID bo'lishi kerak. | Поле :attribute должно быть корректным UUID. |
| `validation.attributes.init_data` | initData | initData |
| `validation.attributes.interface_locale` | interfeys tili | язык интерфейса |
| `validation.attributes.timezone` | vaqt mintaqasi | часовой пояс |
| `validation.attributes.reminder_time` | eslatma vaqti | время напоминания |
| `validation.attributes.daily_goal` | kunlik maqsad | дневная цель |
| `validation.attributes.goal` | o'qish maqsadi | цель обучения |
| `validation.attributes.level` | daraja | уровень |
| `validation.attributes.code` | kod | код |
| `validation.attributes.mode` | rejim | режим |
| `validation.attributes.word_id` | so'z | слово |
| `validation.attributes.quality` | baho | оценка |
| `validation.attributes.time_spent_ms` | sarflangan vaqt | затраченное время |
| `validation.attributes.title` | nomi | название |
| `validation.attributes.text` | matn | текст |
| `validation.attributes.format` | format | формат |

Проверить в первую очередь: «maydoni» как «поле»; «:attribute ... bo'lishi kerak» (должно быть); «to'ldirilishi shart» (обязательно для заполнения); «kamida / oshmasligi kerak» (не меньше / не больше); названия полей API в `validation.attributes.*` (interfeys tili, vaqt mintaqasi, eslatma vaqti, kunlik maqsad, o'qish maqsadi, daraja, kod, rejim, so'z, baho, sarflangan vaqt).

## Фаза 2 · задача 2D.1 (свои колоды) — на проверку носителю

> Экраны «Мои колоды» / редактор колоды в TWA. Термин: колода = «to'plam» (по смыслу «набор слов»); если у носителя есть
> более привычное слово для набора карточек — заменить во всех строках `decks.*`. Проверить порядок слов в `decks.report`
> и `decks.rejected` («{line}-qator»), а также тексты отказов по лимитам тарифа (`error.code.deck_*`, `paywall.lead_decks`).

| Ключ | uz | ru |
|---|---|---|
| `decks.title` | Mening to'plamlarim | Мои колоды |
| `decks.open` | Mening to'plamlarim | Мои колоды |
| `decks.intro` | O'z to'plamingizni yarating va so'zlarni ro'yxat yoki CSV orqali qo'shing. To'plamni faqat siz ko'rasiz. | Создайте свою колоду и добавьте слова списком или из CSV. Колоду видите только вы. |
| `decks.empty` | Sizda hali to'plamlar yo'q. | У вас пока нет колод. |
| `decks.limit` | To'plamlar: {max} tadan {used} tasi. Bitta to'plamda {words} tagacha so'z. | Колод: {used} из {max}. В одной колоде — до {words} слов. |
| `decks.new.placeholder` | To'plam nomi | Название колоды |
| `decks.new.submit` | To'plam yaratish | Создать колоду |
| `decks.edit.back` | To'plamlarga | К колодам |
| `decks.edit.rename` | Nomi | Название |
| `decks.edit.save` | Saqlash | Сохранить |
| `decks.edit.words` | So'zlar | Слова |
| `decks.edit.no_words` | To'plamda hali so'zlar yo'q. | В колоде пока нет слов. |
| `decks.edit.remove_word` | Olib tashlash | Убрать |
| `decks.edit.import` | So'zlar qo'shish | Добавить слова |
| `decks.edit.format.list` | Ro'yxat | Списком |
| `decks.edit.format.csv` | CSV | CSV |
| `decks.edit.hint.list` | Har qatorda bitta so'z: so'z — tarjima (yoki so'z;tarjima). | По слову в строке: слово — перевод (или слово;перевод). |
| `decks.edit.hint.csv` | Ustunlar: word,translation_ru,translation_uz,example. | Колонки: word,translation_ru,translation_uz,example. |
| `decks.edit.text.placeholder` | apple — olma | apple — яблоко |
| `decks.edit.add` | Qo'shish | Добавить |
| `decks.edit.delete` | To'plamni o'chirish | Удалить колоду |
| `decks.edit.delete.confirm` | {title} to'plami o'chirilsinmi? So'zlar arxivga o'tadi, natijangiz saqlanadi. | Удалить колоду «{title}»? Слова уйдут в архив, ваш прогресс сохранится. |
| `decks.edit.delete.yes` | Ha, o'chirish | Да, удалить |
| `decks.edit.delete.no` | Bekor qilish | Отмена |
| `decks.report` | Qo'shildi: {added} · qaytdi: {reactivated} · allaqachon bor: {duplicates} · o'tkazib yuborilgan qatorlar: {rejected} | Добавлено: {added} · вернулось: {reactivated} · уже были: {duplicates} · пропущено строк: {rejected} |
| `decks.rejected` | {line}-qator: {reason} | Строка {line}: {reason} |
| `decks.reason.empty_translation` | tarjima yo'q | нет перевода |
| `decks.reason.junk` | so'zga o'xshamaydi | не похоже на слово |
| `decks.reason.too_long` | juda uzun | слишком длинно |
| `decks.reason.duplicate` | takror | повтор |
| `decks.reason.over_limit` | qatorlar limitidan ortiq | сверх лимита строк |
| `decks.error.too_large` | Matn juda katta: 256 KB dan oshmasligi kerak. | Текст слишком большой: не больше 256 КБ. |
| `paywall.lead_decks` | Free tarifida to'plamlar limiti: 50 tagacha so'zli 1 ta to'plam. Pro'da 2000 tadan so'zli 20 tagacha to'plam. | Лимит колод тарифа Free: 1 колода до 50 слов. В Pro — до 20 колод по 2000 слов. |
| `error.closed.decks` | To'plam endi mavjud emas. | Колода больше недоступна. |
| `error.forbidden.decks` | Sizda to'plamlarga kirish huquqi yo'q. | У вас нет доступа к колодам. |
| `error.code.deck_not_found` | To'plam topilmadi. | Колода не найдена. |
| `error.code.deck_word_not_found` | To'plamda bunday so'z yo'q. | Такого слова в колоде нет. |
| `error.code.deck_limit_reached` | Tarifingizdagi to'plamlar limitiga yetdingiz. | Достигнут лимит колод вашего тарифа. |
| `error.code.deck_words_limit_reached` | Tarifingizdagi to'plamdagi so'zlar limitiga yetdingiz. Hech narsa qo'shilmadi. | Достигнут лимит слов в колоде вашего тарифа. Ничего не добавлено. |
| `error.code.deck_import_empty` | Matnda mos keladigan birorta qator topilmadi. | В тексте не нашлось ни одной подходящей строки. |
| `error.code.deck_title_invalid` | To'plam nomida harflar bo'lishi kerak. | Название колоды должно содержать буквы. |

## Фаза 2 · задача 2A.3 (оплата Telegram Stars: инвойс, отказ при оплате, подтверждение, возврат) — на проверку носителю

> Проверить: «hisob» (счёт/инвойс), «obuna» (подписка), «avtoyangilash», «qaytarish» (возврат); Telegram ограничивает `invoice.title`/`invoice.label` 32 символами, `invoice.description_*` — 255; `:period` подставляется как «1 oy» / «1 yil», `:date` — «дд.мм.гггг», `:amount` — «150 ⭐».

### Бот (`lang/uz/billing.php`, ключи `billing.stars.*`)

| Ключ | uz | ru |
|---|---|---|
| `billing.stars.invoice.title` | LexiFlow Pro — :period | LexiFlow Pro — :period |
| `billing.stars.invoice.label` | LexiFlow Pro, :period | LexiFlow Pro, :period |
| `billing.stars.invoice.description_month` | LexiFlow Pro obunasi: yangi so'zlar cheklovsiz, batafsil statistika. Har 30 kunda avtomatik yangilanadi; /pro buyrug'i orqali o'chirish mumkin. | Подписка LexiFlow Pro: новые слова без лимита, подробная статистика. Продлевается автоматически каждые 30 дней; отключить можно командой /pro. |
| `billing.stars.invoice.description_year` | LexiFlow Pro 1 yilga (365 kun): yangi so'zlar cheklovsiz, batafsil statistika. Bir martalik to'lov, avtoyangilashsiz. | LexiFlow Pro на 1 год (365 дней): новые слова без лимита, подробная статистика. Разовая оплата без автопродления. |
| `billing.stars.precheckout.order_not_found` | Hisob topilmadi. /pro ni oching va yangisini yarating. | Счёт не найден. Откройте /pro и создайте новый. |
| `billing.stars.precheckout.foreign` | Bu hisob boshqa foydalanuvchiga yozilgan. /pro ni oching va o'zingiznikini yarating. | Этот счёт выставлен другому пользователю. Откройте /pro и создайте свой. |
| `billing.stars.precheckout.blocked` | Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling: /paysupport. | Доступ к аккаунту ограничен. Обратитесь в поддержку: /paysupport. |
| `billing.stars.precheckout.order_closed` | Bu hisob allaqachon to'langan yoki yopilgan. /pro ni oching. | Этот счёт уже оплачен или закрыт. Откройте /pro. |
| `billing.stars.precheckout.order_expired` | Hisob muddati tugagan. /pro ni oching va yangisini yarating. | Срок счёта истёк. Откройте /pro и создайте новый. |
| `billing.stars.precheckout.amount_mismatch` | Hisob summasi buyurtma bilan mos kelmaydi. /pro ni oching va yangisini yarating. | Сумма счёта не совпадает с заказом. Откройте /pro и создайте новый. |
| `billing.stars.precheckout.provider_disabled` | Telegram Stars orqali to'lov hozir mavjud emas. /pro da boshqa usulni tanlang. | Оплата Telegram Stars сейчас недоступна. Выберите другой способ в /pro. |
| `billing.stars.precheckout.unavailable` | Xizmat vaqtincha ishlamayapti. Bir daqiqadan keyin urinib ko'ring. | Сервис временно недоступен. Попробуйте через минуту. |
| `billing.stars.paid` | To'lov qabul qilindi! LexiFlow Pro :date gacha faol. | Оплата получена! LexiFlow Pro активен до :date. |
| `billing.stars.renewed` | Obuna yangilandi: LexiFlow Pro :date gacha faol. | Подписка продлена: LexiFlow Pro активен до :date. |
| `billing.stars.paid_forever` | To'lov qabul qilindi! Sizda LexiFlow Pro muddatsiz. | Оплата получена! У вас LexiFlow Pro без ограничения по сроку. |
| `billing.stars.open_button` | LexiFlow ni ochish | Открыть LexiFlow |
| `billing.stars.paid_unresolved` | To'lov qabul qilindi, lekin uni avtomatik hisobga olib bo'lmadi. Biz aniqlab, Pro ni yoqamiz yoki Stars ni qaytaramiz. Tezlashtirish uchun qo'llab-quvvatlash xizmatiga yozing: /paysupport. | Оплата получена, но автоматически зачесть её не удалось. Мы разберёмся и включим Pro или вернём Stars. Чтобы ускорить — напишите в поддержку: /paysupport. |
| `billing.stars.refunded` | :amount qaytarildi. Agar bu to'lov bilan joriy Pro davri to'langan bo'lsa, u yakunlandi. | Возврат :amount выполнен. Если этим платежом был оплачен текущий период Pro, он завершён. |

## Фаза 2 · задача 2A.2 (оплата: бот `/pro`, `/paysupport`, TWA paywall и «Подписка») — на проверку носителю

> Проверить: «obuna» / «avtoyangilash» (автопродление), «to'lash» (оплата), «hisob» (счёт), «chegirma» (скидка), «oferta», «pulni qaytarish» (возврат); суммы подставляются как «150 ⭐» и «29 000 so'm».

### Бот (`lang/uz/billing.php`, `lang/uz/bot.php`)

| Ключ | uz | ru |
|---|---|---|
| `bot.help.private` (добавлены строки `/pro`, `/paysupport`) | Pro uchun promokod: /promo KOD<br>Pro'ni rasmiylashtirish va avtoyangilash: /pro<br>To'lov bo'yicha savollar: /paysupport | Промокод на Pro: /promo КОД<br>Оформить Pro и автопродление: /pro<br>Вопросы по оплате: /paysupport |
| `billing.pro.blocked` | Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling. | Доступ к аккаунту ограничен. Обратитесь в поддержку. |
| `billing.pro.rate_limited` | Juda ko'p urinish. Bir daqiqa kutib, qayta urinib ko'ring. | Слишком много попыток. Подождите минуту и повторите. |
| `billing.pro.status_free` | Hozir sizda Free tarif: kuniga :count ta yangi so'z, takrorlash cheklovsiz. | Сейчас у вас тариф Free: :count новых слов в день, повторения без лимита. |
| `billing.pro.status_free_generic` | Hozir sizda Free tarif: yangi so'zlar kunlik limit bilan cheklangan, takrorlash cheklovsiz. | Сейчас у вас тариф Free: новые слова ограничены дневным лимитом, повторения без лимита. |
| `billing.pro.status_pro_until` | Sizda LexiFlow Pro :date gacha. | У вас LexiFlow Pro до :date. |
| `billing.pro.status_pro_forever` | Sizda LexiFlow Pro muddatsiz. | У вас LexiFlow Pro без ограничения по сроку. |
| `billing.pro.renewal_auto` | Avtoyangilash yoqilgan: keyingi davr uchun to'lovni Telegram o'zi yechib oladi. | Автопродление включено: следующий период Telegram спишет сам. |
| `billing.pro.renewal_canceled` | Avtoyangilash o'chirilgan — Pro to'langan davr oxirigacha amal qiladi. | Автопродление отключено — Pro действует до конца оплаченного периода. |
| `billing.pro.soon` | LexiFlow Pro tez orada ochiladi. Promokodingiz bormi? /promo KOD yuboring. | LexiFlow Pro скоро откроется. Есть промокод? Отправьте /promo КОД. |
| `billing.pro.contact` | Markazda o'qiysizmi? Markaz administratoridan LexiFlow Pro'ni ulashni so'rang: :contact | Учитесь в центре? Попросите администратора центра подключить LexiFlow Pro: :contact |
| `billing.pro.choose` | Muddat va to'lov usulini tanlang: | Выберите срок и способ оплаты: |
| `billing.pro.price_line` | :period — :prices | :period — :prices |
| `billing.pro.offer` | Birinchi to'lovga :pct% chegirma :date gacha amal qiladi. | Скидка :pct% на первый платёж действует до :date. |
| `billing.pro.buy_button` | :provider · :period · :price | :provider · :period · :price |
| `billing.pro.cancel_button` | Avtoyangilashni o'chirish | Отключить автопродление |
| `billing.pro.resume_button` | Avtoyangilashni yoqish | Включить автопродление |
| `billing.pro.checkout_ready` | Hisob yaratildi: :period, :price.<br>Quyidagi tugma orqali to'lang — tasdiq kelishi bilan Pro o'zi yoqiladi. | Счёт создан: :period, :price.<br>Оплатите по кнопке ниже — Pro включится сам, как только придёт подтверждение. |
| `billing.pro.pay_button` | To'lash | Оплатить |
| `billing.pro.canceled` | Avtoyangilash o'chirildi. Pro :date gacha amal qiladi. | Автопродление отключено. Pro действует до :date. |
| `billing.pro.resumed` | Avtoyangilash qayta yoqildi. | Автопродление снова включено. |
| `billing.pro.errors.price_not_configured` | Bu to'lov varianti hozircha mavjud emas. | Этот вариант оплаты пока недоступен. |
| `billing.pro.errors.provider_disabled` | Bu to'lov usuli hozir mavjud emas. Boshqasini tanlang. | Этот способ оплаты сейчас недоступен. Выберите другой. |
| `billing.pro.errors.provider_not_supported` | Bu to'lov usuli siz uchun mavjud emas. | Этот способ оплаты вам недоступен. |
| `billing.pro.errors.checkout_conflict` | Sizda Telegram Stars avtoyangilash yoqilgan. Boshqa usulda to'lashdan oldin uni /pro orqali o'chiring. | У вас включено автопродление Telegram Stars. Отключите его в /pro, прежде чем платить другим способом. |
| `billing.pro.errors.too_many_pending` | Sizda to'lanmagan hisoblar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi. | У вас уже есть неоплаченные счета. Оплатите один из них или подождите — они закроются сами. |
| `billing.pro.errors.offer_not_applicable` | Chegirma bu xaridga qo'llanilmaydi. | Скидка к этой покупке не применяется. |
| `billing.pro.errors.invalid_period` | Bunday to'lov varianti yo'q. | Такого варианта оплаты нет. |
| `billing.pro.errors.invalid_tier` | Bunday to'lov varianti yo'q. | Такого варианта оплаты нет. |
| `billing.pro.errors.not_cancelable` | Hozir o'chiriladigan avtoyangilash yo'q. | Автопродление сейчас нечего отключать. |
| `billing.pro.errors.not_resumable` | Avtoyangilashni hozir yoqib bo'lmaydi: u allaqachon yoqilgan yoki to'langan davr tugagan. | Автопродление сейчас нельзя включить: оно уже включено или оплаченный период закончился. |
| `billing.pro.errors.provider_capability` | Bu amal sizning to'lov usulingiz uchun mavjud emas. | Это действие недоступно для вашего способа оплаты. |
| `billing.pro.errors.provider_not_registered` | Bu amal sizning to'lov usulingiz uchun mavjud emas. | Это действие недоступно для вашего способа оплаты. |
| `billing.pro.errors.provider_unavailable` | To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring. | Платёжный сервис не отвечает. Попробуйте через несколько минут. |
| `billing.pro.errors.generic` | Amalni bajarib bo'lmadi. Keyinroq urinib ko'ring yoki qo'llab-quvvatlash xizmatiga yozing: /paysupport. | Не получилось выполнить действие. Попробуйте позже или напишите в поддержку: /paysupport. |
| `billing.paysupport.with_contact` | LexiFlow to'lovlar bo'yicha yordam.<br><br>To'lov, pulni qaytarish va obunani bekor qilish masalalari: :contact<br>Muammoni yozing va chek yoki to'lov raqamini ilova qiling. Pulni qaytarish qoidalari ofertada: :offer<br>Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin. | Платёжная поддержка LexiFlow.<br><br>Вопросы по оплате, возвраты и отмена подписки: :contact<br>Опишите проблему и приложите чек или номер платежа. Правила возврата — в оферте: :offer<br>Автопродление Stars можно отключить командой /pro. |
| `billing.paysupport.without_contact` | LexiFlow to'lovlar bo'yicha yordam.<br><br>Yordam kontakti hozircha ko'rsatilmagan. Pulni qaytarish qoidalari ofertada: :offer<br>Stars avtoyangilashini /pro buyrug'i orqali o'chirish mumkin. | Платёжная поддержка LexiFlow.<br><br>Контакт поддержки пока не указан. Правила возврата — в оферте: :offer<br>Автопродление Stars можно отключить командой /pro. |
| `billing.period.month` | 1 oy | 1 месяц |
| `billing.period.year` | 1 yil | 1 год |
| `billing.provider.telegram_stars` | ⭐ Stars | ⭐ Stars |
| `billing.provider.payme` | Payme | Payme |
| `billing.provider.click` | Click | Click |
| `billing.currency.XTR` | :amount ⭐ | :amount ⭐ |
| `billing.currency.UZS` | :amount so'm | :amount сум |

### TWA (`resources/twa/src/i18n/uz.ts`)

| Ключ | uz | ru |
|---|---|---|
| `paywall.title_purchase` | LexiFlow Pro | LexiFlow Pro |
| `paywall.lead_purchase` | Yangi so'zlar kunlik limitsiz, to'plamlar va batafsil statistika. | Новые слова без дневного лимита, колоды и подробная статистика. |
| `billing.title` | Obuna | Подписка |
| `billing.open` | Pro obunasi | Подписка Pro |
| `billing.period.month` | Oy | Месяц |
| `billing.period.year` | Yil | Год |
| `billing.pay_with` | To'lash: {provider} · {price} | Оплатить: {provider} · {price} |
| `billing.provider.telegram_stars` | ⭐ Stars | ⭐ Stars |
| `billing.provider.payme` | Payme | Payme |
| `billing.provider.click` | Click | Click |
| `billing.provider.manual` | qo'lda berilgan | выдан вручную |
| `billing.provider.promo` | promokod | промокод |
| `billing.provider.reward` | mukofot | награда |
| `billing.offer` | Birinchi to'lovga {pct}% chegirma — {date} gacha. | Скидка {pct}% на первый платёж — до {date}. |
| `billing.starting` | Hisob yaratilmoqda… | Создаём счёт… |
| `billing.awaiting` | To'lov tasdig'i kutilmoqda… | Ждём подтверждение оплаты… |
| `billing.timeout` | Tasdiq hali kelmadi. Agar to'lagan bo'lsangiz, Pro bir necha daqiqada yoqiladi. | Подтверждение ещё не пришло. Если вы уже оплатили, Pro включится в течение нескольких минут. |
| `billing.check_again` | Yana tekshirish | Проверить ещё раз |
| `billing.payment_failed` | To'lov o'tmadi yoki hisob yopilgan. Qayta urinib ko'ring. | Платёж не прошёл или счёт закрыт. Попробуйте ещё раз. |
| `billing.paid_until` | Rahmat! Pro {date} gacha faol. | Спасибо! Pro активен до {date}. |
| `billing.paid_forever` | Rahmat! Pro faol. | Спасибо! Pro активен. |
| `billing.auto_renew_running` | Stars avtoyangilash yoqilgan — hech narsa to'lash shart emas. Obunani «Sozlamalar → Pro obunasi» bo'limida boshqarish mumkin. | Автопродление Stars включено — оплачивать ничего не нужно. Управлять подпиской можно в «Настройки → Подписка Pro». |
| `billing.plan.free` | Free tarif | Тариф Free |
| `billing.plan.pro` | LexiFlow Pro | LexiFlow Pro |
| `billing.sponsored` | Pro'ni markazingiz ulagan. | Pro подключён вашим центром. |
| `billing.until` | {date} gacha amal qiladi. | Действует до {date}. |
| `billing.forever` | Muddatsiz. | Без ограничения по сроку. |
| `billing.provider_line` | To'lov: {provider}. | Оплата: {provider}. |
| `billing.auto_on` | Avtoyangilash yoqilgan: keyingi davr uchun to'lovni Telegram o'zi yechib oladi. | Автопродление включено: следующий период Telegram спишет сам. |
| `billing.auto_off` | Avtoyangilash o'chirilgan — Pro to'langan davr oxirigacha amal qiladi. | Автопродление отключено — Pro действует до конца оплаченного периода. |
| `billing.cancel` | Avtoyangilashni o'chirish | Отключить автопродление |
| `billing.resume` | Avtoyangilashni yoqish | Включить автопродление |
| `billing.buy` | Pro'ni sotib olish | Купить Pro |
| `billing.renew` | Pro'ni uzaytirish | Продлить Pro |
| `billing.unavailable` | To'lov tez orada ochiladi. | Оплата скоро откроется. |
| `billing.sum` | so'm | сум |
| `error.code.invalid_init_data` | Telegram sessiyasini tasdiqlab bo'lmadi. Ilovani qaytadan oching. | Не удалось подтвердить Telegram-сессию. Откройте приложение заново. |
| `error.code.invalid_token` | Sessiya eskirgan. Ilovani qaytadan oching. | Сессия устарела. Откройте приложение заново. |
| `error.code.invalid_mode` | Noma'lum mashg'ulot rejimi. | Неизвестный режим тренировки. |
| `error.code.price_not_configured` | Bu to'lov varianti hozircha mavjud emas. | Этот вариант оплаты пока недоступен. |
| `error.code.provider_disabled` | Bu to'lov usuli hozir mavjud emas. Boshqasini tanlang. | Этот способ оплаты сейчас недоступен. Выберите другой. |
| `error.code.provider_not_supported` | Bu to'lov usuli siz uchun mavjud emas. | Этот способ оплаты вам недоступен. |
| `error.code.checkout_conflict` | Sizda Stars avtoyangilash yoqilgan. Boshqa usulda to'lashdan oldin uni «Obuna» bo'limida o'chiring. | У вас включено автопродление Stars. Отключите его в «Подписке», прежде чем платить другим способом. |
| `error.code.too_many_pending` | Sizda to'lanmagan hisoblar bor. Ulardan birini to'lang yoki kuting — ular o'zi yopiladi. | У вас уже есть неоплаченные счета. Оплатите один из них или подождите — они закроются сами. |
| `error.code.offer_not_applicable` | Chegirma bu xaridga qo'llanilmaydi. | Скидка к этой покупке не применяется. |
| `error.code.invalid_period` | Bunday to'lov varianti yo'q. | Такого варианта оплаты нет. |
| `error.code.invalid_tier` | Bunday to'lov varianti yo'q. | Такого варианта оплаты нет. |
| `error.code.not_cancelable` | Hozir o'chiriladigan avtoyangilash yo'q. | Автопродление сейчас нечего отключать. |
| `error.code.not_resumable` | Avtoyangilashni hozir yoqib bo'lmaydi: u allaqachon yoqilgan yoki to'langan davr tugagan. | Автопродление сейчас нельзя включить: оно уже включено или оплаченный период закончился. |
| `error.code.provider_capability` | Bu amal sizning to'lov usulingiz uchun mavjud emas. | Это действие недоступно для вашего способа оплаты. |
| `error.code.provider_not_registered` | Bu amal sizning to'lov usulingiz uchun mavjud emas. | Это действие недоступно для вашего способа оплаты. |
| `error.code.provider_unavailable` | To'lov xizmati javob bermayapti. Bir necha daqiqadan keyin urinib ko'ring. | Платёжный сервис не отвечает. Попробуйте через несколько минут. |
| `error.code.order_not_found` | Hisob topilmadi. | Счёт не найден. |
