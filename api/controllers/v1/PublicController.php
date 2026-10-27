<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 30.05.2019
 * Time: 10:45
 */

namespace api\controllers\v1;

use common\helpers\UserSecurity;
use common\models\Order;
use common\models\OrderFile;
use common\models\Product;
use common\models\User;
use Yii;
use yii\caching\TagDependency;

/**
 * Class PublicController
 * @package api\controllers\v1
 */
class PublicController extends \api\controllers\PublicController
{
    /**
     * @return array
     */
    public function actionPasswordDifficult()
    {
        return $this->successResponse([
            'password_difficult' => UserSecurity::getPassLevel(Yii::$app->request->post('password'))
        ]);
    }

    public function actionDownload($id)
    {
        $fileOrder = OrderFile::find()->where(['id' => $id])->one();
        if ($fileOrder != false) {
            $order = Order::find()
                ->where(['id' => $fileOrder->order_id])
                ->one();
            if ($order != false) {
                if (file_exists($fileOrder->filePath)) {
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename=' . basename($fileOrder->filePath));
                        header('Content-Transfer-Encoding: binary');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($fileOrder->filePath));
                        ob_clean();
                        flush();
                        readfile($fileOrder->filePath);
                        exit;
                }
            }
        }
    }

    public function actionClearCache ()
    {

//        $tt = Product::find()->where(['name' => 'Рукавички нітрилові сині "М" 200шт/уп'])->all();
//
//        echo '<pre>';
//        print_r($tt);
//        echo '</pre>';
//        die();

        $token = Yii::$app->getRequest()->getBodyParam('token', "");

        if($token == 'hbjkNMDSHJofdhjlkfdsFD&SFHlsahiodS(D&798sDsJhl')
        {
            $listCacheToClear = [
                'GetSeasonGoods',
                'CategoryPopular',
                'Get4BlockCategories',
                'GetCategoryPage',
                'GetProductByCategoryOnCategoryPage',
                'CategoryTreeAllCategories'
            ];

            foreach ($listCacheToClear as $item)
            {
                TagDependency::invalidate(
                    Yii::$app->cache,
                    $item
                );
            }

            return $this->successResponse([true]);
        }
    }
    
    /**
     * Эндпоинт проверки доступности (Health Check) для HetrixTools и систем мониторинга
     * GET /v1/public/health
     *
     * @return array
     */
    public function actionHealth()
    {
        $checks = [
            'database' => false,
            'redis' => false,
            'queue' => false,
        ];

        // 1. Проверка доступности MySQL
        try {
            Yii::$app->db->createCommand('SELECT 1')->execute();
            $checks['database'] = true;
        } catch (\Throwable $e) {
            Yii::error('Healthcheck DB Error: ' . $e->getMessage(), 'health');
        }

        // 2. Проверка доступности Redis
        try {
            if (Yii::$app->has('redis') && Yii::$app->redis) {
                Yii::$app->redis->ping();
                $checks['redis'] = true;
            } else {
                $checks['redis'] = true;
            }
        } catch (\Throwable $e) {
            Yii::error('Healthcheck Redis Error: ' . $e->getMessage(), 'health');
        }

        // 3. Проверка статуса очередей задач Yii Queue
        $queueInfo = null;
        try {
            if (Yii::$app->has('queue')) {
                $queue = Yii::$app->queue;
                if ($queue instanceof \yii\queue\redis\Queue) {
                    $prefix = $queue->channel;
                    $redis = $queue->redis;

                    $waiting = (int)$redis->llen("$prefix.waiting");
                    $delayed = (int)$redis->zcount("$prefix.delayed", '-inf', '+inf');
                    $reserved = (int)$redis->zcount("$prefix.reserved", '-inf', '+inf');
                    $total = (int)$redis->get("$prefix.message_id");

                    $queueInfo = [
                        'channel' => $prefix,
                        'waiting' => $waiting,
                        'delayed' => $delayed,
                        'reserved' => $reserved,
                        'total' => $total,
                        'done' => max(0, $total - $waiting - $delayed - $reserved),
                    ];
                    $checks['queue'] = true;
                } else {
                    $checks['queue'] = true;
                }
            }
        } catch (\Throwable $e) {
            Yii::error('Healthcheck Queue Error: ' . $e->getMessage(), 'health');
            $checks['queue'] = false;
        }

        $isHealthy = $checks['database'] && $checks['redis'] && $checks['queue'];

        $responseData = [
            'status' => $isHealthy ? 'ok' : 'degraded',
            'timestamp' => time(),
            'checks' => $checks,
        ];

        if ($queueInfo !== null) {
            $responseData['queue'] = $queueInfo;
        }

        if (!$isHealthy) {
            Yii::$app->response->statusCode = 503;
            return $this->failResponse($responseData);
        }

        return $this->successResponse($responseData);
    }

    public function actionCheckDeploy()
    {


    	if(file_exists('/var/www/salvi/www_api/deploy'))
	    {
		    $result = ['deploy' => 'processing'];
	    	
		    return $this->successResponse($result);
	    }
	
	    $result = ['deploy' => 'finish'];
	
	    return $this->successResponse($result);
    }

//    public function actionTmpChangeApiKeys()
//    {
//        $userlist = User::find()->all();1
//
//        foreach ($userlist as $user)
//        {
//            $user->auth_key = Yii::$app->security->generateRandomString();
//
//            if($user->email == 'webmaster.kdteam@gmail.com')
//            {
//                echo '<pre>';
//                print_r($user->auth_key);
//                echo '</pre>';
//            }
//
//            $user->save();
//        }
//    }

    public function actionStartChangePassword ()
    {
        $token = Yii::$app->getRequest()->getBodyParam('token', "");

        if($token == 'hbjkNMDSHDI)DSds70&dsd7s8dshiodS(D&798sDsJhl')
        {
            $userlist = User::find()->all();

            foreach ($userlist as $user)
            {
                $user->is_need_change_password = 1;

                $user->save();
            }

            $result = ['StartChangePassword' => 'start'];

            return $this->successResponse($result);
        }
    }

    public function actionGetStatChangePassword ()
    {
        $token = Yii::$app->getRequest()->getBodyParam('token', "");

        if($token == 'hbjkNMHJ2KLYUI2slk3aOUI455D6huioS(&yUOIHjkz5867gukLHJhl')
        {
            $result = [];

            $userlist = User::find()->where(['is_need_change_password' => 0])->all();

            $count = 1;

            foreach ($userlist as $user)
            {
                $result[$count]['email'] = $user->email;
                $result[$count]['phone_number'] = $user->phone_number;
                $result[$count]['first_name'] = $user->first_name;
                $result[$count]['last_name'] = $user->last_name;

                ++$count;
            }

            return $this->successResponse($result);
        }
    }
}
