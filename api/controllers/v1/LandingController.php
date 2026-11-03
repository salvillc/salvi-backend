<?php

namespace api\controllers\v1;

use api\controllers\RestController;
use common\models\LandingOrder;
use common\models\LandingPage;
use common\models\User;
use Throwable;
use Yii;
use yii\filters\auth\HttpBearerAuth;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class LandingController
 * @package api\controllers\v1
 *
 * Публичный контроллер для отображения промо-страниц и приёма заявок
 */
class LandingController extends RestController
{
    /**
     * Настройка авторизации: публичный доступ с опциональным определением пользователя
     * @return array
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        // Разрешаем публичный доступ к методам (аутентификация опциональна)
        $behaviors['authenticator']['optional'] = ['view', 'submit-order', 'user-info'];
        // Разрешаем возвращать как JSON, так и HTML
        if (isset($behaviors['contentNegotiator']['formats'])) {
            $behaviors['contentNegotiator']['formats']['text/html'] = Response::FORMAT_HTML;
        }
        return $behaviors;
    }

    /**
     * Отображение промо-страницы (для гостей або авторизованих клієнтів)
     * @param string $slug
     * @return mixed
     * @throws NotFoundHttpException
     */
    public function actionView($slug = 'novorichni-nabory')
    {
        // Защитный шлюз: если запрос submit-order попал сюда, перенаправляем на приём заказа
        if ($slug === 'submit-order') {
            return $this->actionSubmitOrder();
        }

        Yii::$app->response->format = Response::FORMAT_HTML;
        Yii::$app->response->headers->set('Content-Type', 'text/html; charset=utf-8');
        // Забороняємо кешування браузером та проксі, щоб зміна активності застосовувалась негайно
        Yii::$app->response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        Yii::$app->response->headers->set('Pragma', 'no-cache');
        Yii::$app->response->headers->set('Expires', '0');

        $slug = strtolower(trim($slug, '/'));
        if (empty($slug)) {
            $slug = 'novorichni-nabory';
        }

        $page = LandingPage::findOne(['slug' => $slug]);
        if (!$page) {
            $page = new LandingPage([
                'slug' => $slug,
                'title' => 'Новорічні подарункові набори',
                'active' => 1,
            ]);
            $page->save(false);
        }

        // Если страница отключена переключателем в админке - строго 404 для всех
        if (!$page->active || (int)$page->active === 0) {
            Yii::$app->response->statusCode = 404;
            return $this->renderErrorHtml('404 — Сторінку не знайдено', 'Ця промо-сторінка наразі вимкнена або не існує.');
        }

        // Определяем пользователя (через Bearer, Cookie salvi_token/access_token или сессию)
        $user = $this->resolveUser();

        // 1. Авторизованный пользователь (клиент)
        if ($user) {
            $htmlPath = $page->getAbsoluteFilePath(LandingPage::TYPE_AUTH)
                ?: $page->getAbsoluteFilePath(LandingPage::TYPE_GUEST)
                ?: $page->getAbsoluteFilePath(LandingPage::TYPE_TEST);

            $content = $this->loadHtmlContent($htmlPath);
            return $this->injectSystemScripts($content, $user, false);
        }

        // 2. Неавторизованный пользователь (гость / режим инкогнито)
        $htmlPath = $page->getAbsoluteFilePath(LandingPage::TYPE_GUEST)
            ?: $page->getAbsoluteFilePath(LandingPage::TYPE_AUTH)
            ?: $page->getAbsoluteFilePath(LandingPage::TYPE_TEST);

        $content = $this->loadHtmlContent($htmlPath);
        return $this->injectSystemScripts($content, null, false);
    }

    /**
     * Приём заявки с формы промо-страницы и отправка email менеджеру
     * @return array
     */
    public function actionSubmitOrder()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $bodyParams = Yii::$app->request->getBodyParams();
        if (empty($bodyParams)) {
            $rawInput = Yii::$app->request->getRawBody();
            $bodyParams = json_decode($rawInput, true) ?: [];
        }

        $slug = $bodyParams['slug'] ?? Yii::$app->request->post('slug', 'novorichni-nabory');
        if ($slug === 'submit-order') {
            $slug = 'novorichni-nabory';
        }
        $page = LandingPage::findOne(['slug' => $slug]);

        // Извлекаем контактные данные (поддерживаем как вложенный contact, так и плоский массив)
        $contact = $bodyParams['contact'] ?? $bodyParams;
        $name = trim($contact['name'] ?? ($bodyParams['name'] ?? ''));
        $phone = $this->normalizePhoneNumber(trim($contact['phone'] ?? ($bodyParams['phone'] ?? '')));
        $email = trim($contact['email'] ?? ($bodyParams['email'] ?? ''));
        $company = trim($contact['company'] ?? ($bodyParams['company'] ?? ''));
        $channel = trim($contact['channel'] ?? ($bodyParams['channel'] ?? 'не вказано'));
        $comment = trim($contact['comment'] ?? ($bodyParams['comment'] ?? ''));

        if (empty($name) || empty($phone)) {
            return $this->failResponse(['message' => 'Будь ласка, вкажіть контактне ім\'я та номер телефону']);
        }

        // Извлекаем данные квиза
        $quiz = $bodyParams['quiz'] ?? [];
        $budget = $quiz['budget'] ?? '';
        $qty = (int)($quiz['qty'] ?? 0);
        $estimate = (float)($bodyParams['estimate'] ?? ($quiz['estimate'] ?? 0));
        $deadline = $quiz['deadline'] ?? 'Не вказано';
        $wishes = $quiz['wishes'] ?? '';

        $interests = $quiz['interests'] ?? [];
        $interestsStr = is_array($interests) ? implode(', ', $interests) : (string)$interests;

        // Извлекаем упаковку и декор
        $packaging = $bodyParams['packaging']['name'] ?? ($bodyParams['packaging'] ?? 'Не обрано');
        $filler = $bodyParams['filler']['name'] ?? ($bodyParams['filler'] ?? 'Не обрано');
        $ribbon = $bodyParams['ribbon']['name'] ?? ($bodyParams['ribbon'] ?? 'Не обрано');

        $decor = $bodyParams['decor'] ?? [];
        $decorStr = is_array($decor) ? implode(', ', $decor) : (string)$decor;

        // Извлекаем логотип
        $logoData = $bodyParams['logo'] ?? [];
        $logoPlacement = is_array($logoData) ? ($logoData['placement'] ?? '') : '';

        // Создаем номер заявки
        $orderNumber = $bodyParams['number'] ?? ('NY-' . date('ymd') . '-' . mt_rand(1000, 9999));

        // Обработка файла логотипа (если передан base64 или файл)
        $savedLogoRelative = $this->saveLogoFile($orderNumber, $bodyParams);

        // Пользователь (если авторизован)
        $user = $this->resolveUser();

        // 1. Сохраняем заявку в базу данных
        $order = new LandingOrder([
            'landing_page_id' => $page ? $page->id : null,
            'order_number' => $orderNumber,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'company' => $company,
            'channel' => $channel,
            'comment' => $comment,
            'budget' => (string)$budget,
            'qty' => $qty,
            'total_estimate' => $estimate,
            'deadline' => (string)$deadline,
            'wishes' => (string)$wishes,
            'interests' => $interestsStr,
            'packaging' => (string)$packaging,
            'filler' => (string)$filler,
            'ribbon' => (string)$ribbon,
            'decor' => $decorStr,
            'logo_placement' => (string)$logoPlacement,
            'logo_file' => $savedLogoRelative,
            'raw_data' => json_encode($bodyParams, JSON_UNESCAPED_UNICODE),
            'user_id' => $user ? $user->id : null,
            'ip' => Yii::$app->request->getUserIP(),
            'email_status' => LandingOrder::STATUS_PENDING,
        ]);

        $order->save(false);

        // 2. Отправка письма менеджеру
        $managerEmail = ($page && !empty($page->manager_email))
            ? $page->manager_email
            : (Yii::$app->params['senderEmail'] ?? 'info@salvigroup.com.ua');

        try {
            $this->sendEmailToManager($order, $managerEmail);
            $order->email_status = LandingOrder::STATUS_SENT;
            $order->save(false);
        } catch (Throwable $e) {
            Yii::error('Помилка відправки email заявки ' . $order->order_number . ': ' . $e->getMessage(), 'landing');
            $order->email_status = LandingOrder::STATUS_FAILED;
            $order->email_error = $e->getMessage();
            $order->save(false);
        }

        return $this->successResponse([
            'message' => 'Заявка успішно прийнята',
            'order_number' => $order->order_number,
        ]);
    }

    /**
     * API для получения контактов текущего пользователя (если клиентский скрипт запрашивает асинхронно)
     * @return array
     */
    public function actionUserInfo()
    {
        $user = $this->resolveUser();
        if (!$user) {
            return $this->successResponse(['is_auth' => false, 'user' => null]);
        }

        $companyName = '';
        if ($user->company) {
            $companyName = $user->company->short_name ?: $user->company->name;
        }

        return $this->successResponse([
            'is_auth' => true,
            'user' => [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? ''),
                'phone' => $user->phone_number ?? ($user->phone ?? ''),
                'email' => $user->email ?? '',
                'company' => $companyName,
            ],
        ]);
    }

    /**
     * Поиск пользователя по Bearer-токену, параметру auth_token или Cookie
     * @param string|null $paramToken
     * @return User|null
     */
    protected function resolveUser($paramToken = null)
    {
        if (!Yii::$app->user->isGuest && Yii::$app->user->identity instanceof User) {
            return Yii::$app->user->identity;
        }

        $token = $paramToken ?: Yii::$app->request->get('auth_token');
        if (empty($token)) {
            // Сырые куки от JS (document.cookie) и валидированные Yii
            $token = $_COOKIE['salvi_token'] ?? ($_COOKIE['access_token'] ?? null);
            if (empty($token)) {
                $token = Yii::$app->request->cookies->getValue('salvi_token')
                    ?: Yii::$app->request->cookies->getValue('access_token');
            }
        }

        if (!empty($token)) {
            $identity = User::findIdentityByAccessToken($token);
            if ($identity) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * Проверка, является ли пользователь администратором
     * @param User $user
     * @return bool
     */
    protected function isUserAdmin(User $user)
    {
        $permissions = Yii::$app->authManager->getPermissionsByUser($user->id);
        return isset($permissions['managementProductView'])
            || isset($permissions['admin'])
            || isset($permissions['superadmin']);
    }

    /**
     * Загрузка исходного HTML файла
     * @param string|null $path
     * @return string
     */
    protected function loadHtmlContent($path)
    {
        if ($path && file_exists($path)) {
            return file_get_contents($path);
        }

        // Резервный файл из scripts
        $defaultFile = Yii::getAlias('@common/../scripts/salvi-novorichni-nabory-template.html');
        if (file_exists($defaultFile)) {
            return file_get_contents($defaultFile);
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>SALVI</title></head><body><h1>Сторінку ще не завантажено в адмінці</h1></body></html>';
    }

    /**
     * Нормализация номера телефона (особенно для украинских номеров, где ведущий 0 срезается при хранении в integer)
     * @param string|int|null $phone
     * @return string
     */
    public function normalizePhoneNumber($phone)
    {
        if (empty($phone)) {
            return '';
        }
        $digits = preg_replace('/[^\d]/', '', (string)$phone);
        // Если 9 цифр (например 990077886) -> превращаем в +380990077886
        if (strlen($digits) === 9) {
            return '+380' . $digits;
        }
        // Если 10 цифр и начинается с 0 (например 0990077886) -> +380990077886
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            return '+38' . $digits;
        }
        // Если 12 цифр и начинается с 380 -> +380990077886
        if (strlen($digits) === 12 && substr($digits, 0, 3) === '380') {
            return '+' . $digits;
        }
        return (string)$phone;
    }

    /**
     * Внедрение системных скриптов, автоподстановки контактов и перенаправления формы на API
     * @param string $html
     * @param User|null $user
     * @param bool $isTest
     * @return string
     */
    protected function injectSystemScripts($html, $user, $isTest)
    {
        $userData = null;
        if ($user) {
            $companyName = '';
            if ($user->company) {
                $companyName = $user->company->short_name ?: $user->company->name;
            }

            $rawPhone = $user->phone_number ?? ($user->phone ?? '');
            $phone = $this->normalizePhoneNumber($rawPhone);

            $userData = [
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? ''),
                'phone' => $phone,
                'email' => $user->email ?? '',
                'company' => $companyName,
            ];

            // Замена текстовых плейсхолдеров, если заказчик вставил их в разметку
            $html = str_replace(
                ['{{user_name}}', '{{user_phone}}', '{{user_email}}', '{{user_company}}'],
                [htmlspecialchars($userData['name']), htmlspecialchars($userData['phone']), htmlspecialchars($userData['email']), htmlspecialchars($userData['company'])],
                $html
            );
        }

        // Бэдж для тестовой версии под админом
        $testBadge = '';
        if ($isTest) {
            $testBadge = '
            <div style="position:fixed;top:0;left:0;right:0;z-index:999999;background:#e53935;color:#fff;text-align:center;padding:7px 12px;font-size:13px;font-family:sans-serif;font-weight:bold;box-shadow:0 2px 8px rgba(0,0,0,0.25);">
                ⚠️ ТЕСТОВИЙ РЕЖИМ (попередній перегляд під адміністратором). Ця версія недоступна звичайним користувачам.
            </div>
            <style>body { padding-top: 34px !important; }</style>';
        }

        $apiUrl = '/promo/submit-order';
        $jsonUserData = $userData ? json_encode($userData, JSON_UNESCAPED_UNICODE) : 'null';

        // Инжектируемый скрипт
        $injectedScript = '
        <script>
            window.__SALVI_API_ENDPOINT__ = ' . json_encode($apiUrl) . ';
            window.__SALVI_USER__ = ' . $jsonUserData . ';

            // Автозаполнение полей формы контактами авторизованного пользователя
            document.addEventListener("DOMContentLoaded", function() {
                function normalizePhone(p) {
                    if (!p) return "";
                    var d = String(p).replace(/\D/g, "");
                    if (d.length === 9) return "+380" + d;
                    if (d.length === 10 && d.indexOf("0") === 0) return "+38" + d;
                    if (d.length === 12 && d.indexOf("380") === 0) return "+" + d;
                    return String(p);
                }

                var u = window.__SALVI_USER__;
                if (!u && typeof localStorage !== "undefined") {
                    try {
                        var localU = JSON.parse(localStorage.getItem("current_user"));
                        if (localU) {
                            u = {
                                name: (localU.first_name ? (localU.first_name + " " + (localU.last_name || "")) : localU.username) || "",
                                phone: normalizePhone(localU.phone_number || localU.phone || ""),
                                email: localU.email || "",
                                company: localU.company_name || ""
                            };
                        }
                    } catch(e) {}
                }

                if (u && u.phone) {
                    u.phone = normalizePhone(u.phone);
                }

                var form = document.getElementById("orderForm") || document.querySelector("form");
                if (form) {
                    if (u) {
                        if (form.elements["name"] && !form.elements["name"].value && u.name) form.elements["name"].value = u.name;
                        if (form.elements["phone"] && !form.elements["phone"].value && u.phone) form.elements["phone"].value = u.phone;
                        if (form.elements["email"] && !form.elements["email"].value && u.email) form.elements["email"].value = u.email;
                        if (form.elements["company"] && !form.elements["company"].value && u.company) form.elements["company"].value = u.company;
                    }

                    // Гарантированная нормализация телефона при отправке, чтобы валидация длины не блокировала
                    if (form.elements["phone"]) {
                        form.elements["phone"].addEventListener("blur", function() {
                            var val = this.value.replace(/\D/g, "");
                            if (val.length === 9) this.value = "+380" + val;
                        });
                        form.addEventListener("submit", function() {
                            var p = form.elements["phone"];
                            if (p) {
                                var val = p.value.replace(/\D/g, "");
                                if (val.length === 9) p.value = "+380" + val;
                            }
                        }, true);
                    }
                }
            });
        </script>';

        // Вставляем бэдж и скрипт
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $injectedScript . "\n</body>", $html);
        } else {
            $html .= $injectedScript;
        }

        if ($isTest) {
            if (stripos($html, '<body') !== false) {
                $html = preg_replace('/(<body[^>]*>)/i', '$1' . $testBadge, $html, 1);
            } else {
                $html = $testBadge . $html;
            }
        }

        return $html;
    }

    /**
     * Сохранение файла логотипа клиента (base64 или загруженный файл)
     * @param string $orderNumber
     * @param array $bodyParams
     * @return string|null
     */
    protected function saveLogoFile($orderNumber, $bodyParams)
    {
        $base64 = $bodyParams['logo']['data'] ?? ($bodyParams['logo_base64'] ?? ($bodyParams['logo_data'] ?? null));
        if (!$base64 && isset($bodyParams['logo']) && is_string($bodyParams['logo']) && strpos($bodyParams['logo'], 'data:') === 0) {
            $base64 = $bodyParams['logo'];
        }

        if (empty($base64)) {
            return null;
        }

        $uploadDir = Yii::getAlias('@api/web/landings/logos');
        if (!is_dir($uploadDir)) {
            FileHelper::createDirectory($uploadDir, 0777);
        }

        // Извлекаем расширение
        $ext = 'png';
        if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            $ext = strtolower($matches[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $decodedData = base64_decode($base64);
        if (!$decodedData) {
            return null;
        }

        $cleanNumber = preg_replace('/[^a-zA-Z0-9_-]/', '', $orderNumber);
        $fileName = 'logo_' . $cleanNumber . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (file_put_contents($targetPath, $decodedData)) {
            return $fileName;
        }

        return null;
    }

    /**
     * Формирование и отправка HTML письма менеджеру
     * @param LandingOrder $order
     * @param string $managerEmail
     * @throws Throwable
     */
    protected function sendEmailToManager(LandingOrder $order, $managerEmail)
    {
        $clientTitle = $order->company ?: $order->name;
        $subject = "Заявка №{$order->order_number}: Новорічні набори — {$clientTitle} ({$order->qty} шт.)";

        $logoHtml = '<i>Без логотипу</i>';
        $logoPathOnDisk = null;

        if ($order->logo_file) {
            $logoUrl = $order->getLogoUrl();
            $logoPathOnDisk = Yii::getAlias('@api/web/landings/logos/' . $order->logo_file);
            $logoHtml = "Місце: <b>" . htmlspecialchars($order->logo_placement ?: 'на упаковці') . "</b>";
            if ($logoUrl) {
                $logoHtml .= "<br><a href='{$logoUrl}' target='_blank' style='color:#269447;font-weight:bold;'>Завантажити логотип клієнта &rarr;</a>";
            }
        }

        $htmlBody = '
        <div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;color:#222;">
            <div style="background:#2E2F32;padding:20px 24px;color:#fff;">
                <h2 style="margin:0;font-size:22px;color:#269447;">Нова заявка на новорічні набори SALVI</h2>
                <div style="margin-top:6px;font-size:14px;color:#bbb;">№ заявки: <b>' . htmlspecialchars($order->order_number) . '</b> | Дата: ' . date('d.m.Y H:i') . '</div>
            </div>
            
            <div style="padding:24px;">
                <h3 style="margin-top:0;color:#2E2F32;border-bottom:2px solid #269447;padding-bottom:6px;font-size:16px;">1. Контактні дані клієнта</h3>
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px;">
                    <tr><td style="padding:6px 0;color:#666;width:160px;">Клієнт / Ім\'я:</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($order->name) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Компанія:</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($order->company ?: '—') . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Телефон:</td><td style="padding:6px 0;font-weight:bold;"><a href="tel:' . htmlspecialchars($order->phone) . '" style="color:#269447;">' . htmlspecialchars($order->phone) . '</a></td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Email:</td><td style="padding:6px 0;">' . ($order->email ? '<a href="mailto:' . htmlspecialchars($order->email) . '">' . htmlspecialchars($order->email) . '</a>' : '—') . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Зручний канал:</td><td style="padding:6px 0;"><b>' . htmlspecialchars($order->channel) . '</b></td></tr>
                    ' . ($order->comment ? '<tr><td style="padding:6px 0;color:#666;">Коментар:</td><td style="padding:6px 0;background:#f9f9f9;padding:8px;border-radius:4px;">' . nl2br(htmlspecialchars($order->comment)) . '</td></tr>' : '') . '
                </table>

                <h3 style="color:#2E2F32;border-bottom:2px solid #269447;padding-bottom:6px;font-size:16px;">2. Параметри замовлення</h3>
                <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px;">
                    <tr><td style="padding:6px 0;color:#666;width:160px;">Бюджет на набір:</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($order->budget) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Кількість наборів:</td><td style="padding:6px 0;font-weight:bold;">' . (int)$order->qty . ' шт.</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Орієнтовна сума:</td><td style="padding:6px 0;font-size:18px;font-weight:bold;color:#269447;">' . number_format((float)$order->total_estimate, 0, '', ' ') . ' грн</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Бажаний термін:</td><td style="padding:6px 0;font-weight:bold;">' . htmlspecialchars($order->deadline) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Побажання по наповненню:</td><td style="padding:6px 0;">' . htmlspecialchars($order->interests ?: 'Підбере менеджер') . '</td></tr>
                    ' . ($order->wishes ? '<tr><td style="padding:6px 0;color:#666;">Додаткові побажання:</td><td style="padding:6px 0;">' . nl2br(htmlspecialchars($order->wishes)) . '</td></tr>' : '') . '
                </table>

                <h3 style="color:#2E2F32;border-bottom:2px solid #269447;padding-bottom:6px;font-size:16px;">3. Оформлення та брендування</h3>
                <table style="width:100%;border-collapse:collapse;margin-bottom:10px;font-size:14px;">
                    <tr><td style="padding:6px 0;color:#666;width:160px;">Упаковка:</td><td style="padding:6px 0;">' . htmlspecialchars($order->packaging) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Наповнювач:</td><td style="padding:6px 0;">' . htmlspecialchars($order->filler) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Стрічка:</td><td style="padding:6px 0;">' . htmlspecialchars($order->ribbon) . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Декор:</td><td style="padding:6px 0;">' . htmlspecialchars($order->decor ?: '—') . '</td></tr>
                    <tr><td style="padding:6px 0;color:#666;">Логотип:</td><td style="padding:6px 0;">' . $logoHtml . '</td></tr>
                </table>
            </div>
            
            <div style="background:#f4f5f7;padding:14px 24px;text-align:center;font-size:12px;color:#888;">
                Повідомлення створено автоматично системою SALVI з промо-сторінки новорічних наборів.
            </div>
        </div>';

//        $mailer = Yii::$app->mailer->compose()
//            ->setFrom(Yii::$app->params['senderEmail'] ?? 'no-reply@salvigroup.com.ua')
//            ->setTo($managerEmail)
//            ->setSubject($subject)
//            ->setHtmlBody($htmlBody);
//
//        // Прикрепляем логотип к письму, если он существует на диске
//        if ($logoPathOnDisk && file_exists($logoPathOnDisk)) {
//            $mailer->attach($logoPathOnDisk, [
//                'fileName' => 'client_logo_' . basename($logoPathOnDisk),
//            ]);
//        }

       // $mailer->send();
    }

    /**
     * Отображение простой страницы ошибки
     * @param string $title
     * @param string $message
     * @return string
     */
    protected function renderErrorHtml($title, $message)
    {
        $code = Yii::$app->response->statusCode;
        $icon = ($code === 404) ? '🔍' : (($code === 403) ? '🔒' : '⚠️');
        return '<!DOCTYPE html>
        <html lang="uk">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>' . htmlspecialchars($title) . ' — SALVI</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f6f8; color: #222; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
                .card { background: #fff; max-width: 480px; width: 100%; padding: 36px 30px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center; }
                .icon { font-size: 48px; margin-bottom: 16px; }
                h1 { font-size: 24px; margin: 0 0 12px; color: #1e2022; }
                p { font-size: 15px; color: #666; line-height: 1.5; margin: 0 0 24px; }
                a.btn { display: inline-block; background: #269447; color: #fff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: 500; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">' . $icon . '</div>
                <h1>' . htmlspecialchars($title) . '</h1>
                <p>' . htmlspecialchars($message) . '</p>
                <a href="/" class="btn">Повернутися на головну</a>
            </div>
        </body>
        </html>';
    }
}
