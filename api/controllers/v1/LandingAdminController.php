<?php

namespace api\controllers\v1;

use api\controllers\RestController;
use common\models\Category;
use common\models\LandingOrder;
use common\models\LandingPage;
use common\models\PromoMenuItem;
use common\models\User;
use Yii;
use yii\caching\TagDependency;
use yii\data\ActiveDataProvider;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;

/**
 * Class LandingAdminController
 * @package api\controllers\v1
 *
 * Управление промо-страницами и заявками в админ-панели
 */
class LandingAdminController extends RestController
{
    /**
     * Проверка прав администратора перед действиями
     * @param \yii\base\Action $action
     * @return bool
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (Yii::$app->user->isGuest) {
            throw new ForbiddenHttpException('Потрібна авторизація.');
        }

        // Проверяем права на управление
        $userId = Yii::$app->user->id;
        $permissions = Yii::$app->authManager->getPermissionsByUser($userId);

        if (!isset($permissions['managementProductView']) && !isset($permissions['admin']) && !isset($permissions['superadmin'])) {
            throw new ForbiddenHttpException('Недостатньо прав для управління промо-сторінками.');
        }

        return true;
    }

    /**
     * Получить или сохранить настройки промо-страницы
     * @param string $slug
     * @return array
     */
    public function actionSettings($slug = 'novorichni-nabory')
    {
        $slug = Yii::$app->request->get('slug')
            ?: (Yii::$app->request->post('slug') ?: $slug);
        $slug = strtolower(trim($slug, '/'));
        if (empty($slug)) {
            $slug = 'novorichni-nabory';
        }

        $page = LandingPage::findOne(['slug' => $slug]);
        if (!$page) {
            $page = new LandingPage([
                'slug' => $slug,
                'title' => 'Новорічні подарункові набори',
                'manager_email' => Yii::$app->params['senderEmail'] ?? 'info@salvigroup.com.ua',
                'active' => 1,
            ]);
            $page->save(false);
        }

        if (Yii::$app->request->isPost) {
            $body = Yii::$app->request->getBodyParams();
            if (empty($body)) {
                $raw = Yii::$app->request->getRawBody();
                $body = json_decode($raw, true) ?: [];
            }
            if (empty($body)) {
                $body = Yii::$app->request->post();
            }

            if (isset($body['manager_email'])) {
                $page->manager_email = trim($body['manager_email']);
            }
            if (array_key_exists('active', $body)) {
                $val = $body['active'];
                $page->active = (!empty($val) && $val !== '0' && $val !== 0) ? 1 : 0;
            }
            if (!empty($body['title'])) {
                $page->title = trim($body['title']);
            }
            if (empty($page->title)) {
                $page->title = 'Новорічні подарункові набори';
            }

            $page->save(false);

            return $this->successResponse([
                'message' => 'Налаштування успішно збережено',
                'settings' => $this->formatPageSettings($page),
            ]);
        }

        return $this->successResponse([
            'settings' => $this->formatPageSettings($page),
        ]);
    }

    /**
     * Быстрое переключение активности промо-страницы
     * @return array
     */
    public function actionToggleActive()
    {
        $body = Yii::$app->request->getBodyParams();
        if (empty($body)) {
            $raw = Yii::$app->request->getRawBody();
            $body = json_decode($raw, true) ?: [];
        }
        if (empty($body)) {
            $body = Yii::$app->request->post();
        }

        $slug = $body['slug'] ?? Yii::$app->request->get('slug', 'novorichni-nabory');
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

        if (array_key_exists('active', $body)) {
            $val = $body['active'];
            $page->active = (!empty($val) && $val !== '0' && $val !== 0) ? 1 : 0;
        } else {
            $page->active = $page->active ? 0 : 1;
        }

        $page->save(false);

        return $this->successResponse([
            'message' => $page->active ? 'Сторінку увімкнено (активна)' : 'Сторінку вимкнено (повертає 404)',
            'active' => (bool)$page->active,
            'settings' => $this->formatPageSettings($page),
        ]);
    }

    /**
     * Загрузка HTML-файла (тестового, гостевого или клиентского)
     * @return array
     */
    public function actionUploadFile()
    {
        $slug = Yii::$app->request->post('slug', 'novorichni-nabory');
        $type = Yii::$app->request->post('type'); // 'test', 'guest', 'auth'

        if (!in_array($type, [LandingPage::TYPE_TEST, LandingPage::TYPE_GUEST, LandingPage::TYPE_AUTH], true)) {
            return $this->failResponse(['message' => 'Некоректний тип версії (очікується test, guest або auth)']);
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

        $file = UploadedFile::getInstanceByName('file');
        if (!$file) {
            return $this->failResponse(['message' => 'Файл не передано']);
        }

        $ext = strtolower($file->extension);
        if (!in_array($ext, ['html', 'htm'], true)) {
            return $this->failResponse(['message' => 'Дозволені лише файли з розширенням .html або .htm']);
        }

        // Максимальный размер 25 МБ (с запасом для встроенных картинок base64)
        if ($file->size > 25 * 1024 * 1024) {
            return $this->failResponse(['message' => 'Розмір файлу не повинен перевищувати 25 МБ']);
        }

        $storagePath = $page->getStoragePath();
        $fileName = $type . '_' . date('Ymd_His') . '.' . $ext;
        $targetPath = $storagePath . DIRECTORY_SEPARATOR . $fileName;

        if (!$file->saveAs($targetPath)) {
            return $this->failResponse(['message' => 'Не вдалося зберегти файл на сервері']);
        }

        // Обновляем запись в БД
        $attribute = 'file_' . $type;
        $page->$attribute = $fileName;
        $page->save(false);

        return $this->successResponse([
            'message' => 'Файл успішно завантажено',
            'type' => $type,
            'file_info' => $page->getFileInfo($type),
            'settings' => $this->formatPageSettings($page),
        ]);
    }

    /**
     * Список заявок с промо-страниц
     * @return array
     */
    public function actionOrders()
    {
        $slug = Yii::$app->request->get('slug', 'novorichni-nabory');
        $search = trim(Yii::$app->request->get('search', ''));
        $page = (int)Yii::$app->request->get('page', 1);
        $perPage = (int)Yii::$app->request->get('per_page', 15);

        $query = LandingOrder::find()->orderBy(['id' => SORT_DESC]);

        if (!empty($slug)) {
            $landingPage = LandingPage::findOne(['slug' => $slug]);
            if ($landingPage) {
                $query->andWhere(['landing_page_id' => $landingPage->id]);
            }
        }

        if (!empty($search)) {
            $cleanPhone = preg_replace('/[^\d]/', '', $search);
            $conditions = [
                'or',
                ['like', 'order_number', $search],
                ['like', 'name', $search],
                ['like', 'phone', $search],
                ['like', 'email', $search],
                ['like', 'company', $search],
                ['like', 'channel', $search],
                ['like', 'comment', $search],
            ];
            if (strlen($cleanPhone) >= 3) {
                $conditions[] = ['like', 'phone', $cleanPhone];
            }
            $query->andWhere($conditions);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => $perPage > 0 ? $perPage : 15,
                'page' => $page > 0 ? $page - 1 : 0,
            ],
        ]);

        $orders = [];
        foreach ($dataProvider->getModels() as $model) {
            /** @var LandingOrder $model */
            $orders[] = $model->toAdminArray();
        }

        return $this->successResponse([
            'orders' => $orders,
            'pagination' => [
                'total' => (int)$dataProvider->getTotalCount(),
                'page' => (int)$dataProvider->getPagination()->getPage() + 1,
                'per_page' => (int)$dataProvider->getPagination()->getPageSize(),
                'total_pages' => (int)$dataProvider->getPagination()->getPageCount(),
            ],
        ]);
    }

    /**
     * Детали конкретной заявки
     * @param int $id
     * @return array
     * @throws NotFoundHttpException
     */
    public function actionOrder($id)
    {
        $order = LandingOrder::findOne((int)$id);
        if (!$order) {
            throw new NotFoundHttpException('Заявку не знайдено');
        }

        $data = $order->toAdminArray();
        $data['raw_data'] = $order->raw_data ? json_decode($order->raw_data, true) : null;

        return $this->successResponse([
            'order' => $data,
        ]);
    }

    /**
     * Список пунктов промо-меню + категории для выбора родителей
     * @return array
     */
    public function actionMenuItems()
    {
        $itemsData = [];
        try {
            $items = PromoMenuItem::find()
                ->orderBy(['level' => SORT_ASC, 'sort' => SORT_ASC, 'id' => SORT_DESC])
                ->all();

            $itemsData = array_map(function (PromoMenuItem $m) {
                return $m->toAdminArray();
            }, $items);
        } catch (\Throwable $e) {
            $itemsData = [];
        }

        // Получаем категории 1-го уровня (родители для 2-го уровня)
        $level1Raw = Category::find()
            ->select(['id', 'uuid', 'name', 'sort'])
            ->where(['delete' => 0])
            ->andWhere(['or', ['parent_id' => 0], ['parent_id' => null]])
            ->orderBy(['sort' => SORT_ASC, 'name' => SORT_ASC])
            ->asArray()
            ->all();

        $level1 = array_map(function ($l1) {
            return [
                'id' => (int)$l1['id'],
                'uuid' => $l1['uuid'],
                'name' => $l1['name'],
                'sort' => (int)($l1['sort'] ?? 0),
            ];
        }, $level1Raw);

        // Получаем категории 2-го уровня (родители для 3-го уровня)
        $level1Ids = array_column($level1, 'id');
        $level2 = [];
        if (!empty($level1Ids)) {
            $level2Raw = Category::find()
                ->select(['id', 'uuid', 'name', 'parent_id', 'sort'])
                ->where(['delete' => 0, 'parent_id' => $level1Ids])
                ->orderBy(['parent_id' => SORT_ASC, 'sort' => SORT_ASC, 'name' => SORT_ASC])
                ->asArray()
                ->all();

            $level1Map = [];
            foreach ($level1 as $l1) {
                $level1Map[$l1['id']] = $l1['name'];
            }

            foreach ($level2Raw as $l2) {
                $parentName = $level1Map[$l2['parent_id']] ?? '—';
                $level2[] = [
                    'id' => (int)$l2['id'],
                    'uuid' => $l2['uuid'],
                    'name' => $l2['name'],
                    'parent_id' => (int)$l2['parent_id'],
                    'parent_name' => $parentName,
                    'full_label' => $parentName . ' → ' . $l2['name'],
                ];
            }
        }

        return $this->successResponse([
            'items' => $itemsData,
            'categories' => [
                'level1' => $level1,
                'level2' => $level2,
            ],
        ]);
    }

    /**
     * Создание или редактирование промо-пункта меню
     * @return array
     */
    public function actionSaveMenuItem()
    {
        $body = Yii::$app->request->getBodyParams();
        if (empty($body)) {
            $rawInput = Yii::$app->request->getRawBody();
            $body = json_decode($rawInput, true) ?: [];
        }

        $id = $body['id'] ?? null;
        if ($id) {
            $model = PromoMenuItem::findOne($id);
            if (!$model) {
                return $this->failResponse(['message' => 'Пункт меню не знайдено']);
            }
        } else {
            $model = new PromoMenuItem();
            $model->active = 1;
        }

        $model->title = trim($body['title'] ?? '');
        $model->url = trim($body['url'] ?? '');
        $model->level = (int)($body['level'] ?? 2);
        $model->parent_id = (int)($body['parent_id'] ?? 0);
        $model->sort = (int)($body['sort'] ?? 0);
        $model->highlight = !empty($body['highlight']) ? 1 : 0;
        $model->target_blank = isset($body['target_blank']) ? (!empty($body['target_blank']) ? 1 : 0) : 1;
        $model->active = isset($body['active']) ? (!empty($body['active']) ? 1 : 0) : 1;
        $model->landing_page_id = !empty($body['landing_page_id']) ? (int)$body['landing_page_id'] : null;
        if (array_key_exists('icon', $body)) {
            $model->icon = !empty($body['icon']) ? trim($body['icon']) : null;
        }

        // Автоматически определяем parent_uuid по выбранной категории
        if ($model->parent_id) {
            $parentCat = Category::findOne($model->parent_id);
            if ($parentCat) {
                $model->parent_uuid = $parentCat->uuid;
            }
        }

        if (!$model->validate()) {
            $firstError = reset($model->getFirstErrors());
            return $this->failResponse(['message' => $firstError ?: 'Помилка валідації']);
        }

        if (!$model->save(false)) {
            return $this->failResponse(['message' => 'Не вдалося зберегти пункт меню']);
        }

        // Сбрасываем кэш дерева категорий
        try {
            TagDependency::invalidate(Yii::$app->cache, 'CategoryTreeAllCategories');
        } catch (\Throwable $e) {}

        return $this->successResponse([
            'message' => 'Пункт меню успішно збережено',
            'item' => $model->toAdminArray(),
        ]);
    }

    /**
     * Удаление промо-пункта меню
     * @return array
     */
    /**
     * Загрузка іконки для промо-пункту меню
     * @return array
     */
    public function actionUploadMenuIcon()
    {
        $file = UploadedFile::getInstanceByName('icon') ?: UploadedFile::getInstanceByName('file');
        if (!$file) {
            return $this->failResponse(['message' => 'Файл не передано']);
        }

        $ext = strtolower($file->extension);
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
        if (!in_array($ext, $allowedExtensions, true)) {
            return $this->failResponse(['message' => 'Дозволені формати: PNG, JPG, WEBP, SVG']);
        }

        if ($file->size > 5 * 1024 * 1024) {
            return $this->failResponse(['message' => 'Розмір іконки не повинен перевищувати 5 МБ']);
        }

        $uploadDir = Yii::getAlias('@api/web/landings/menu_icons');
        if (!is_dir($uploadDir)) {
            FileHelper::createDirectory($uploadDir, 0777);
        }

        $fileName = 'icon_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.' . $ext;
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!$file->saveAs($targetPath)) {
            return $this->failResponse(['message' => 'Не вдалося зберегти іконку на сервері']);
        }

        $url = Yii::$app->urlManager->createAbsoluteUrl(['/landings/menu_icons/' . $fileName]);

        return $this->successResponse([
            'message' => 'Іконку успішно завантажено',
            'file_name' => $fileName,
            'url' => $url,
        ]);
    }

    public function actionDeleteMenuItem()
    {
        $id = Yii::$app->request->post('id') ?? Yii::$app->request->getBodyParam('id');
        $model = PromoMenuItem::findOne($id);
        if (!$model) {
            return $this->failResponse(['message' => 'Пункт меню не знайдено']);
        }

        $model->delete();

        // Сбрасываем кэш дерева категорий
        try {
            TagDependency::invalidate(Yii::$app->cache, 'CategoryTreeAllCategories');
        } catch (\Throwable $e) {}

        return $this->successResponse(['message' => 'Пункт меню успішно видалено']);
    }

    /**
     * Быстрое переключение активности пункта меню
     * @return array
     */
    public function actionToggleMenuItem()
    {
        $id = Yii::$app->request->post('id') ?? Yii::$app->request->getBodyParam('id');
        $model = PromoMenuItem::findOne($id);
        if (!$model) {
            return $this->failResponse(['message' => 'Пункт меню не знайдено']);
        }

        $model->active = $model->active ? 0 : 1;
        $model->save(false);

        // Сбрасываем кэш дерева категорий
        try {
            TagDependency::invalidate(Yii::$app->cache, 'CategoryTreeAllCategories');
        } catch (\Throwable $e) {}

        return $this->successResponse([
            'message' => $model->active ? 'Пункт меню увімкнено' : 'Пункт меню вимкнено',
            'active' => (bool)$model->active,
        ]);
    }

    /**
     * Форматирование настроек страницы для ответа
     * @param LandingPage $page
     * @return array
     */
    protected function formatPageSettings(LandingPage $page)
    {
        // Генерируем чистый URL на базе текущего API хоста (sapi.salvi.ua на stage, api.salvi.ua на prod)
        $host = rtrim(Yii::$app->request->getHostInfo(), '/');
        $liveUrl = $host . '/promo/' . $page->slug;

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'manager_email' => $page->manager_email,
            'active' => (bool)$page->active,
            'files' => [
                'guest' => $page->getFileInfo(LandingPage::TYPE_GUEST),
                'auth' => $page->getFileInfo(LandingPage::TYPE_AUTH),
            ],
            'urls' => [
                'live' => $liveUrl,
            ],
            'updated_at' => $page->updated_at ? date('Y-m-d H:i:s', $page->updated_at) : null,
        ];
    }
}
