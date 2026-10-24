<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 21.05.2019
 * Time: 18:17
 */

namespace api\controllers;

use common\models\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\data\ArrayDataProvider;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\helpers\ArrayHelper;
use yii\rest\Controller;
use yii\web\Response;

/**
 * Class RestController
 * @package api\controllers
 */
class RestController extends Controller
{
    protected $breadcrumbs = [];


    const MESSAGE = 'message';

    public $page = 0;
    public $perPage = 5;
    public $post = [];

    public $serializer = [
        'class' => 'api\components\RestSerializer',
    ];

    /**
     * @return array
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::class,
        ];
        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::class,
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        // remove authentication filter
        $auth = $behaviors['authenticator'];
        unset($behaviors['authenticator']);

        // For cross-domain AJAX request
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                // restrict access to domains:
                'Origin' => static::allowedDomains(),
                'Access-Control-Request-Method' => [
                    'GET',
                    'POST',
                    'PUT',
                    'PATCH',
                    'DELETE',
                    'HEAD',
                    'OPTIONS'
                ],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age' => 86400,                 // Cache (seconds)
                'Access-Control-Allow-Headers' => [
                    'authorization',
                    'X-Requested-With',
                    'content-type',
                    'Access-Control-Allow-Origin'
                ]
            ],
        ];

        // re-add authentication filter
        $behaviors['authenticator'] = $auth;
        // avoid authentication on CORS-pre-flight requests (HTTP OPTIONS method)
        $behaviors['authenticator']['except'] = ['options'];

        return $behaviors;
    }

    /**
     * @return array
     */
    public static function allowedDomains()
    {
        return [
//             '*',                        // star allows all domains

            'http://localhost:8080',
            'http://localhost:8081',
            'http://192.168.1.84:8080',
            'http://salvi.ua/',
            'http://salvi.ua',
            'http://salvi.ua:80',
            'http://dev.salvi.com.ua:80',
            'http://dev.salvi.com.ua',
            'http://dev.salvi.com.ua/',
            'https://salvi.ua/',
            'https://salvi.ua',
            'https://salvi.ua:80',
            'https://salvi.ua:443',
            'https://dev.salvi.ua:80',
            'https://dev.salvi.ua',
            'https://dev.salvi.ua/',
            'https://stage.salvi.ua/',
            'https://stage.salvi.ua',
            'https://stage.salvi.ua:80',
            'https://stage.salvi.ua:443',
        ];
    }

    /**
     * @param ArrayDataProvider $model
     * @param array $additionalData
     * @return array
     */
    public function vueTableResponse(ArrayDataProvider $model, array $additionalData = []): array
    {
        $result = [
            'data' => array_values($model->getModels()),
            'current_page' => (int)$model->pagination->page,
            'total_pages' => (int)$model->pagination->pageCount,
            'total' => (int)$model->pagination->totalCount
        ];
        if (!empty($additionalData)) {
            $result = ArrayHelper::merge($result, $additionalData);
        }
        return $result;
    }

    /**
     * @param ActiveDataProvider $model
     * @param array $additionalData
     * @return array
     */
    public function vueTableResponseActiveData(ActiveDataProvider $model, array $additionalData = []): array
    {
        $result = [
            'data' => $model->getModels(),
            'current_page' => $model->pagination->page,
            'total_pages' => $model->pagination->pageCount,
            'total' => $model->pagination->totalCount
        ];
        if (!empty($additionalData)) {
            $result = ArrayHelper::merge($result, $additionalData);
        }
        return $result;
    }

    /**
     * @param array $data
     * @return array
     */
    public function successResponse($data = []): array
    {
        $result = ['success' => true];
        if (!empty($data)) {
            $result = ArrayHelper::merge($result, $data);
        }
        Yii::info($result);
        return $result;
    }

    /**
     * @param array $data
     * @return array
     */
    public function failResponse($data = []): array
    {
        $result = ['success' => false];
        if (!empty($data)) {
            $result = ArrayHelper::merge($result, $data);
        }
        Yii::info($result);
        return $result;
    }

    public function beforeAction($action)
    {
        $get = Yii::$app->request->get();
        $this->post = Yii::$app->request->post();
        if (isset($get['page']) && is_numeric($get['page'])) {
            $this->page = $get['page'] - 1;
        }
        if (isset($get['per-page']) && is_numeric($get['per-page'])) {
            $this->perPage = $get['per-page'];
        }

        try
        {
            return parent::beforeAction($action);
        }
        catch (\yii\web\UnauthorizedHttpException $e)
        {
            Yii::warning([
                'message' => $e->getMessage(),
                'Authorization' => Yii::$app->request->headers->get('Authorization'),
                'Headers' => Yii::$app->request->headers->toArray(),
            ], 'auth');

            throw $e;
        }
    }

    /**
     * @return array
     */
    public function accessDenied()
    {
        return $this->failResponse(['message' => 'Доступ заборонено']);
    }

    public function setBreadcrumbs($name, $link) {
        $this->breadcrumbs[] = [
            'name' => $name,
            'link' => $link
        ];
    }

    public function getBreadcrumbs() {
        return $this->breadcrumbs;
    }
}
