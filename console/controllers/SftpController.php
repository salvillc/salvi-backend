<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 08.10.2019
 * Time: 16:21
 */

namespace console\controllers;

use api\helpers\SpecificationHelper;
use api\job\EndCronJob;
use common\components\EmptyLogger;
use common\helpers\CropImageHelper;
use common\helpers\xml\ProductParse;
use common\helpers\XmlParse;
use common\models\CatalogLimitation;
use common\models\Category;
use common\models\CategoryExclude;
use common\models\Company;
use common\models\Product;
use common\models\ProductPhoto;
use Yii;
use yii\console\Controller;

/**
 * Class SftpController
 * @package console\controllers 1с_b2b.xml
 */
class SftpController extends Controller
{
    public function actionProduct()
    {
        ini_set("memory_limit", "512M");
        echo ini_get("memory_limit")."\n";
        try {
            $cron = unlink(Yii::getAlias('@console') . '/xml/cron.lock');
        } catch (\Exception $e) {
            $cron = false;
        }
        if ($cron) {
            echo 'Start time: ' . date('d.m.Y H:i:s') . "\n";
            $executionStartTime = microtime(true);
            $startTime = date('U');
            Yii::setLogger(new EmptyLogger());
            $mode = 'FTP_ASCII';
            $asynchronous = false;
            $file = Yii::$app->ftp->get('13.031C_b2b.xml', Yii::getAlias('@console') . '/xml/1c_b2b.xml', $mode, (int)$asynchronous);
//            $file = Yii::$app->ftp->get('10.03НА ДЕВ1C_b2b.xml', Yii::getAlias('@console') . '/xml/1c_b2b.xml', $mode, $asynchronous);
//            $file = Yii::getAlias('@console') . '/xml/1c_b2b.xml';
            $a = new XmlParse($file, 'product', ProductParse::class);
            $a->read();
            Yii::$app->queue->push(new EndCronJob([
                'startTime' => $startTime
            ]));
            //$a->update();
            // $this->deleteProducts($startTime);
            //$this->actionReloadCategory();
//            $this->actionCropImages();
            fopen(Yii::getAlias('@console') . '/xml/cron.lock', "w");

            $executionEndTime = microtime(true);
            $seconds = $executionEndTime - $executionStartTime;
            echo 'End time: ' . date('d.m.Y H:i:s') . "\n";
            echo "This script took $seconds to execute.";
        } else {
            echo 'Previous cron still executing';
        }
    }

    public function actionCreateLock()
    {
        fopen(Yii::getAlias('@console') . '/xml/cron.lock', "w");
        return true;
    }

    public function actionReloadCategory()
    {
        $companies = Company::find()
            ->where(['new_category' => 0])
            ->all();
        if ($companies) {
            $lastCategory = Category::find()
                ->orderBy('id DESC')
                ->one();
            foreach ($companies as $company) {
                $categories = Category::find()
                    ->select(['id', 'uuid'])
                    ->where(['>', 'id', $company->max_category_id])
                    ->all();
                $temp = [];
                if ($categories) {
                    foreach ($categories as $category) {
                        $temp[] = [
                            'company_id'    => $company->id,
                            'category_uuid' => $category['uuid'],
                            'category_id'   => $category['id']
                        ];
                    }
                }
                if (!empty($temp)) {
                    try {
                        Yii::$app->db->createCommand()->batchInsert(
                            CategoryExclude::tableName(),
                            [
                                'company_id',
                                'category_uuid',
                                'category_id'
                            ],
                            $temp
                        )->execute();
                    } catch (\Exception $e) {
                        Yii::error($e);
                    }
                }
                $company->max_category_id = $lastCategory->id;
                $company->save();
            }
        }
    }

    public function actionCropImages()
    {

        $images = ProductPhoto::find()
            ->all();
        if ($images) {
            foreach ($images as $image) {
                /** @var  $image ProductPhoto */
                $path = Yii::getAlias('@api') . '/web/product/';
                $img = $path . $image->name;
                try {
                    $size = filesize($img) / 1024;
                } catch (\Exception $e) {
                    $size = 0;
                }
                if ($size > 100) {
                    try {
                        CropImageHelper::createThumbnail($img, $img, 260);
                    } catch (\Throwable $e) {
                        Yii::error(sprintf(
                            'SftpController::actionCropImages error: photo_id=%s product_id=%s file=%s error=%s (%s:%d)',
                            $image->id,
                            $image->product_id,
                            $img,
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine()
                        ), 'crop_images');
                    }
                }
            }
        }
    }

    private function deleteProducts($startTime)
    {
        Product::updateAll(
            ['delete' => 1],
            [
                'and',
                ['<=', 'updated_at', $startTime],
                ['is_buffer' => 0]
            ]
        );
    }
}
