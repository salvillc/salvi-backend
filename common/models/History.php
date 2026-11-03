<?php

namespace common\models;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "history".
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $role
 * @property int $entity
 * @property int $entity_id
 */
class History extends \yii\db\ActiveRecord
{
    const LOCATION = 1;
    const COST_CENTER = 2;
    const BUDGET = 3;
    const COMPANY = 4;
    const COMPANY_ENTITY = 5;
    const CONTRACT = 6;
    const COORDINATION = 7;
    const ORDER = 8;
    const SPECIFICATION = 9;
    const SUPPLIER = 10;
    const USER = 11;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'history';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'entity', 'entity_id'], 'integer'],
            [['email', 'role'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'email' => 'Email',
            'role' => 'Role',
            'entity' => 'Entity',
            'entity_id' => 'Entity ID',
        ];
    }

    /**
     * @param int $entity
     * @param int $entityId
     * @param User|null $user
     * @return bool
     * @throws \Throwable
     */
    public static function saveHistory(int $entity, int $entityId, $user = null)
    {
        try {
            /** @var  $user User */
            if ($user === null && Yii::$app->has('user') && !Yii::$app->user->isGuest) {
                $user = Yii::$app->user->getIdentity();
            }
            $role = '';
            if ($user) {
                $roles = Yii::$app->authManager->getRolesByUser($user->id);
                if ($roles) {
                    foreach ($roles as $item) {
                        $role = ArrayHelper::getValue($item, 'description', '');
                        break;
                    }
                }
                $user_id = $user->id;
                $email = $user->email;
            } else {
                $user_id = 0;
                $email = 'Адміністратор';
                $role = '';
            }
            $model = new self([
                'entity_id' => $entityId,
                'entity' => $entity,
                'user_id' => $user_id,
                'email' => $email
            ]);
            if ($role != '') {
                $model->role = $role;
            }
            return $model->save();
        } catch (\Throwable $e) {
            Yii::error($e);
            return false;
        }
    }
}
