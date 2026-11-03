<?php

namespace common\models;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "auth_item_history".
 *
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $role
 * @property string $name
 */
class AuthItemHistory extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'auth_item_history';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id'], 'integer'],
            [['email', 'role', 'name'], 'string', 'max' => 255],
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
            'name' => 'Name',
        ];
    }

    /**
     * @param string $name
     * @param User|null $user
     * @return bool
     * @throws \Throwable
     */
    public static function saveHistory(string $name, $user = null)
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
                'name' => $name,
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
