<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 01.08.2019
 * Time: 17:59
 */

namespace api\models\forms;

use api\helpers\NotificationHelper;
use api\helpers\SpecificationHelper;
use common\models\Locations;
use common\models\Specification;
use common\models\SpecificationLocation;
use common\models\SpecificationProduct;
use common\models\User;
use yii\base\Model;
use yii\helpers\ArrayHelper;

/**
 * Class SpecificationForm
 * @package api\models\forms
 */
class SpecificationForm extends Model
{
    public $id;
    public $name;
    public $contract_id;
    public $locations_id = [];
    public $date_start;
    public $date_end;
    public $status;
    public $products = [];
    public $all_location;

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [['name', 'contract_id', 'date_end', 'date_start', 'products'], 'required'],
            [['id', 'status'], 'safe'],
            [['date_end', 'date_start'], 'filter', 'filter' => function ($value) {
                if (is_numeric($value)) {
                    return $value;
                }
                return strtotime($value);
            }],
            [['all_location','locations_id'], 'safe'],
            ['date_end', 'validateDateEnd']
        ];
    }

    public function create()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $model = new Specification([
            'name' => $this->name,
            'date_start' => $this->date_start,
            'date_end' => $this->date_end,
            'contract_id' => $this->contract_id,
            'company_id' => $user->company_id,
            'status' => Specification::STATUS_ACTIVE,
            'all_location' => ($this->all_location) ? 1 : 0
        ]);
        $result = $model->save();
        if ($result) {
            if ($this->all_location) {
                $locations = Locations::getListOfLocationsByContract($this->contract_id);
                if ($locations) {
                    SpecificationLocation::addLocations($model->id, ArrayHelper::getColumn($locations, 'id'));
                }
            } else {
                SpecificationLocation::addLocations($model->id, $this->locations_id);
            }
            SpecificationProduct::addProducts($model->id, $this->products);
            SpecificationProduct::recalculateAvailable($model->id);
        }
        return $result;
    }

    public function attributeLabels()
    {
        return [
            'name' => 'Назва',
            'contract_id' => 'Договір',
            'locations_id' => 'Локації',
            'date_start' => 'Дата старту',
            'date_end' => 'Дата завершення',
            'products' => 'Товари',
        ];
    }

    public function update()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $model = Specification::find()->where(['id' => $this->id])
            ->andWhere(['company_id' => $user->company_id])
            ->one();
        if ($model) {
            $notify = false;
            if ($model->status != $this->status) {
                $notify = true;
            }
            $model->name = $this->name;
            $model->date_start = $this->date_start;
            $model->date_end = $this->date_end;
            $model->status = $this->status;
            $model->all_location = ($this->all_location) ? 1 : 0;
            $result = $model->save();
            if ($result) {
                SpecificationLocation::deleteAll(['specification_id' => $this->id]);
                if ($this->all_location) {
                    $locations = Locations::getListOfLocationsByContract($this->contract_id);
                    if ($locations) {
                        SpecificationLocation::addLocations($model->id, ArrayHelper::getColumn($locations, 'id'));
                    }
                } else {
                    SpecificationLocation::addLocations($model->id, $this->locations_id);
                }
                if ($notify) {
                    NotificationHelper::changeSpecificationStatusManager($model);
                }
                (new SpecificationHelper($model->company_id))->init();
                SpecificationProduct::recalculateAvailable($model->id);
            }
            return $result;
        }
        return false;
    }

    public function validateDateEnd($attribute, $params, $validator)
    {
        if ($this->date_end <= $this->date_start) {
            $this->addError('date_end', 'Дата завершення повинна бути більша дати старту');
        }
        if (!$this->all_location && empty($this->locations_id)) {
            $this->addError('locations_id', 'Необхідно вибрати хоча б 1 локацію');
        }
    }
}
