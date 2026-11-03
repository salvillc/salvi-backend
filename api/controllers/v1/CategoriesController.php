<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 22.07.2019
 * Time: 16:09
 */

namespace api\controllers\v1;

use api\controllers\RestController;
use api\helpers\CategoryTree;
use common\models\Category;
use common\models\Config;
use common\models\PromoMenuItem;
use yii\helpers\ArrayHelper;

/**
 * Class CategoriesController
 * @package api\controllers\v1
 */
class CategoriesController extends RestController
{
    /**
     * @return array
     */
    public function actionIndex()
    {
        $location_id = ArrayHelper::getValue($this->post, 'location_id', 0);
        $order_id = ArrayHelper::getValue($this->post, 'order_id', 0);
        $add_product = ArrayHelper::getValue($this->post, 'add_product', 0);
        $categories = new CategoryTree(true, 0, $location_id, $order_id, $add_product);

        // Внедряем пользовательские промо-ссылки в дерево меню каталога
        PromoMenuItem::injectIntoTree($categories->tree);

        return $this->successResponse(['category' => $categories]);
    }

    /**
     * @return array
     */
    public function actionAllTree()
    {
        $id = ArrayHelper::getValue($this->post, 'id');
        $categories = new CategoryTree(false, $id);
        return $this->successResponse([
            'category' => $categories,
            'default' => Config::getValue(Config::MARKUP)
        ]);
    }

    public function actionDefaultTree()
    {
        $categories = Category::find()->asArray()->all();
        return $this->successResponse([
            'category' => ['tree' => Category::buildTree($categories), 'selection' => []],
            'default' => Config::getValue(Config::MARKUP)
        ]);
    }

    public function actionClearCategories()
    {
        echo '111' . PHP_EOL;
        die();
        $categoriesListNeed = file_get_contents('/var/www/salvi/www_api/drevo1.txt');

	    $categoriesListNeed = json_decode($categoriesListNeed,true);

	    $categoriesListNeedTmp = [];

	    foreach ($categoriesListNeed['drevo'] as $category)
	    {
		    $categoriesListNeedTmp[$category['uuid']] = true;
	    }


        echo '<pre>';
        print_r($categoriesListNeedTmp);
        echo '</pre>';
        die();
		$tt = [];

	    $categoriesDbList = Category::find()->where(['delete' => 0])->all();

	    foreach ($categoriesDbList as $item)
	    {
	    	if(!isset($categoriesListNeedTmp[$item->uuid]))
		    {
			    $tt[] = $item['uuid'];

			    $item->delete = 1;

			    $item->update();
		    }

	    }


	    return $this->successResponse([$tt]);

    }
}
