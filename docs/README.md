# Salvi Backend — Архитектурная документация

Добро пожаловать в техническую документацию бэкенда **Salvi** (`salvi_back`). Документация построена по принципу **Docs-as-Code** и детально описывает не просто структуру каталогов, а реальные жизненные циклы бизнес-процессов с указанием точек входа, файлов, классов и методов, отвечающих за старт механизмов.

---

## 🧭 Навигация по документации

### 1. [Инфраструктура и сервера](infrastructure/servers-and-services.md)
* Топология серверов (Stage / Prod), характеристики ОС и ядра
* Версии ПО: Nginx, PHP-FPM, MySQL, Redis, Node.js, Postfix
* Systemd сервисы, воркеры очередей, демоны Forever
* Конфигурация Nginx виртуальных хостов и маршрутизация доменов
* CI/CD процесс (GitHub Actions Runner, `deploy-guard.sh`, `deploy.sh`)
* Расписание Crontab
* Трехуровневый мониторинг: внешний мониторинг доступности сайта и API из 3 стран ([HetrixTools](https://hetrixtools.com/dashboard/account/settings/)), метрики сервера (Zabbix) и алертинг приложения ([TelegramTarget](flows/10-error-handling-and-telegram-alerts.md))

### 2. [Архитектура приложения и точки входа](architecture/overview.md)
* Архитектура Yii2 Advanced (`api`, `console`, `common`, `nodejs`)
* Точки входа (HTTP API REST, Console CLI, WebSocket Server)
* Схема взаимодействия компонентов

### 3. Механизмы и сценарии выполнения (Flows)

* **[01. Авторизация, аутентификация и RBAC](flows/01-auth-and-rbac.md)**
  * Эндпоинты входа (`/v1/auth/login`), валидация ReCaptcha v3, генерация токенов
  * Иерархия прав (`common\components\RbacManager`) и поведенческие фильтры (`HttpBearerAuth`)
* **[02. Синхронизация каталога и товаров из 1С (SFTP & XML)](flows/02-catalog-and-1c-sftp-sync.md)**
  * Точка входа в CLI (`php yii sftp/product`), триггер из сокетов
  * SFTP-клиент (`\gftp\FtpComponent`), парсинг XML-файла `1c_b2b.xml` потоковым `XmlParse`
  * Обновление моделей `Product`, `Category`, генерация очередей для картинок и спецификаций
* **[03. Жизненный цикл заказа (Order Lifecycle)](flows/03-orders-lifecycle.md)**
  * Корзина (`CartController`) и создание заказа (`actionCreate`)
  * Процесс согласования (`actionApproveCoordination`), модерация, разделение заказов (`splitOrder`)
  * Генерация накладных и отчетов: Krajee PDF (`mPDF`) и экспорт XLSX (`XLSXWriterRewrite`)
* **[04. Real-time WebSocket сервер (Node.js + Redis Pub/Sub)](flows/04-realtime-sockets.md)**
  * Архитектура Node.js демона (`nodejs/socket.js`) на порту `7070` под управлением `forever`
  * Каналы подписки: чаты заказов, корзины (`cartChannel`), персональные пуши пользователей
  * Механизм удаленного выполнения консольных команд из сокета (`execSync`)
* **[05. Фоновые очереди и Cron-задачи](flows/05-cron-and-background-jobs.md)**
  * Системный почасовой крон (`/etc/cron.hourly/1elasicindexrecreate`): переиндексация каталога в Elasticsearch (`search/update-table create`), контроль сроков контрактов (`contract`), чистка логов и архивов (`clear-logs`)
  * Очереди Yii Queue (`yii\queue\redis\Queue`), воркер `yii queue/listen`
  * Классы задач: `DeleteProductJob`, `ParseXlsxJob`, `SendEmail`, `UploadPhoto`, `GetCoordinate`
  * Ночной пересчет бюджетов (`php yii budget/index`) и обработка лимитов
* **[06. Каталог, поиск и витрины товаров](flows/06-search-and-catalog-display.md)**
  * Связка `SearchController.php` с компонентами Vue.js (`salvi_front`)
  * Особенности выборки блоков («Популярное», «Must See / Сезонные», «4 категории на главной»)
  * Механизмы и теги кэширования в Redis (`TagDependency`), TTL и сброс кэша при обновлении картинок и категорий
* **[07. Процесс утверждения («Узгодження» / Matching)](flows/07-matching-coordination.md)**
  * Настройка цепочки шагов (`Coordination`), пороговые суммы и условия (`ADD_TYPE_HIGHER_THAN_BUDGET`, `ADD_TYPE_WITHOUT_BUDGET`)
  * Персональные слепки шагов заказа (`OrderCoordination`), автопропуск шагов через `OrderCoordinationHelper`
  * Проверка прав `canApprove` на фронтенде (`order__page.vue`) и завершение согласования
* **[08. Настройки компаний, ценовая политика и Спецификации](flows/08-company-settings-and-specifications.md)**
  * Флаги компании: «порожній рядок» (`empty_string`), «замовлення без бюджету», обязательное поле №РО
  * Ограничения каталога и наценки (`CatalogLimitation`)
  * Контрактные спецификации (`Specification`) и 4-уровневый алгоритм расчета финальной цены товара
* **[09. Промо-баннеры на главной странице (/catalog)](flows/09-banners-slider.md)**
  * Управление промо-слайдером (`BannerController.php` и `category-main.vue`)
  * Динамический таймер показа (`time_swap`), порядок сортировки (`sort`) и ссылки (`url`)
  * Предзагрузка картинок (`preloadBanner`) и тегированное кэширование в Redis (`GetBanners`)
* **[10. Алертинг и мониторинг ошибок в Telegram](flows/10-error-handling-and-telegram-alerts.md)**
  * Логирование критических ошибок API и консольных команд (`common\components\TelegramTarget`)
  * Мгновенная доставка алертов с контекстом (URL, метод, IP, User ID, трейс) в Telegram-канал
  * Фильтрация исключений (401, 403, 404), защита от лимитов и изоляция от сбоев сети (Fail-safe)

---

## 🛠 Как читать документацию

Каждый документ механизма в папке `flows/` содержит:
1. **Точки старта (Entrypoints):** откуда инициируется процесс (HTTP запрос, Cron, Webhook, WebSocket или CLI).
2. **Таблицу файлов и функций:** точные пути к файлам и сигнатуры методов, задействованных на каждом шаге.
3. **Mermaid-диаграмму последовательности (Sequence Diagram):** визуальную схему передачи управления между контроллерами, сервисами, очередями и базой данных.
