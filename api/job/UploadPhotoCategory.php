<?php

namespace api\job;

use common\helpers\ImageConvertHelper;
use common\models\Category;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\base\BaseObject;
use yii\helpers\FileHelper;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\caching\TagDependency;

class UploadPhotoCategory extends BaseObject implements JobInterface {

    public $category;
    public $image;

    public function execute($queue) {
        $this->uploadImage($this->image);
    }

    private function uploadImage(string $url) {
        $chunks = explode('/', $url);
        $chunks = array_reverse($chunks);

        if (!isset($chunks[0])) {
            return false;
        }

        try {
            $mode = FTP_BINARY;
            $asynchronous = false;
            $name = Uuid::uuid4()->toString() . '.' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $path = Yii::getAlias('@api') . '/web/category/';

            FileHelper::createDirectory($path);

            $fileFullPath = $path . $name;
            $remotePath = '/upload/treeicons/' . $chunks[0];

            Yii::$app->ftp->get($remotePath, $fileFullPath, $mode, $asynchronous);

            if (!file_exists($fileFullPath) || filesize($fileFullPath) === 0) {
                Yii::error(sprintf(
                    'UploadPhotoCategory: downloaded file is missing or empty, keeping old image: category_id=%s uuid=%s remote=%s local=%s',
                    $this->category->id ?? '',
                    $this->category->uuid ?? '',
                    $remotePath,
                    $fileFullPath
                ), 'upload_photo_category');
                return false;
            }

            Yii::$app->ftp->delete($remotePath);

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'png') {
                $jpgName = pathinfo($name, PATHINFO_FILENAME) . '.jpg';
                $jpgFullPath = $path . $jpgName;
                if (ImageConvertHelper::convertPngToJpg($fileFullPath, $jpgFullPath)) {
                    unlink($fileFullPath);
                    $name = $jpgName;
                    $fileFullPath = $jpgFullPath;
                }
            }

            $categoryDb = Category::find()->where(['uuid' => $this->category->uuid])->one();

            if (!$categoryDb) {
                return false;
            }

            $oldFile = Yii::getAlias('@api') . '/web/category/' . $categoryDb->img_path;

            $categoryDb->img_path = $name;
            $categoryDb->save();

            if ($categoryDb->img_path && file_exists($oldFile)) {
                try {
                    unlink($oldFile);
                } catch (\Exception $e) {
                    Yii::error($e->getMessage());
                }
            }

            TagDependency::invalidate(
                Yii::$app->cache,
                'CategoryTreeAllCategories'
            );
        } catch (\Throwable $e) {
            $fileSize = (isset($fileFullPath) && file_exists($fileFullPath)) ? (int)@filesize($fileFullPath) : 0;
            Yii::error(sprintf(
                'UploadPhotoCategory error: category_id=%s uuid=%s url=%s remote=%s local=%s size=%d error=%s (%s:%d)',
                $this->category->id ?? '',
                $this->category->uuid ?? '',
                $url,
                $remotePath ?? '',
                $fileFullPath ?? '',
                $fileSize,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), 'upload_photo_category');
        }
    }
}
