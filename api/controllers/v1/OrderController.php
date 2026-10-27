<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 15.07.2019
 * Time: 15:44
 */

namespace api\controllers\v1;

use api\controllers\RestController;
use api\exceptions\BudgetNotAvailableException;
use api\exceptions\MinProductQuantityException;
use api\exceptions\MissingProductException;
use api\exceptions\NotEnoughAllQuantityProductException;
use api\exceptions\NotEnoughBudgetException;
use api\exceptions\NotEnoughQuantityProductException;
use api\exceptions\PONumberRequiredException;
use api\helpers\NotificationHelper;
use api\helpers\OneSOrder;
use api\models\forms\AddProductForm;
use api\models\forms\CommentForm;
use api\models\forms\CustomProductForm;
use api\models\forms\OrderFileForm;
use api\models\forms\OrderForm;
use api\models\forms\PurchaseOrderForm;
use api\services\GenerateOrderPdf;
use api\services\GenerateOrderXls;
use common\models\Cart;
use common\models\CartProduct;
use common\models\Order;
use common\models\OrderProductChangeHistory;
use common\models\OrderCoordination;
use common\models\OrderFile;
use common\models\OrderProduct;
use common\models\User;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Writer\Pdf;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\web\UploadedFile;
use api\helpers\NumberHelper;

/**
 * Class OrderController
 * @package api\controllers\v1
 */
class OrderController extends RestController
{
    /**
     * @return OrderForm|array
     * @throws InvalidConfigException
     */
    public function actionCreate()
    {
        if (Yii::$app->user->can('createOrder')) {
            $model = new OrderForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
                $result = $model->create();

                $logFile = "/var/www/salvi/www_api/Step3.txt";
                file_put_contents($logFile,date('d.m.Y H:i:s').PHP_EOL,FILE_APPEND);
                file_put_contents($logFile,print_r(json_encode($result),true).PHP_EOL,FILE_APPEND);

                if ($result && !isset($result['error'])) {
                    return $this->successResponse([
                        'id'   => $result,
                        'cart' => Cart::getCartByOrderId($result)
                    ]);
                }
                else
                {
	                return $this->failResponse(['error' => $result['error']]);
                }
            }
            $model->validate();
            return $model;
        } else {
            return $this->accessDenied();
        }
    }


    /**
     * @return CommentForm|array
     * @throws Throwable
     * @throws InvalidConfigException
     */
    public function actionAddComment()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $model = new CommentForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate() &&
                $model->create()) {
                $this->sendEmailAddComment(Yii::$app->getRequest()->getBodyParam('order_id'), Yii::$app->getRequest()->getBodyParam('text'));
                return $this->successResponse();
            }
            $model->validate();
            return $model;
        } else {
            return $this->accessDenied();
        }
    }

    /**
     * @return array
     * @throws Throwable
     */
    public function actionApproveCoordination()
    {
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $orderId = ArrayHelper::getValue($this->post, 'order_id', 0);
        $order = Order::find()->where(['id' => $orderId])->one();
        if ($order && $order->status == Order::STATUS_ON_APPROVE) {
            $coordination = OrderCoordination::find()
                ->where(['id' => $id])
                ->andWhere(['order_id' => $orderId])
                ->one();
            if ($coordination) {
                if ($coordination->canApprove) {
                    if (!$coordination->approveStatus($user->id)) {
                        $orderCoord = OrderCoordination::find()
                            ->where(['order_id' => $orderId])
                            ->andWhere(['company_id' => $user->company_id])
                            ->andWhere(['next_step' => 1])
                            ->andWhere(['status' => 0])
                            ->one();
                        if (!$orderCoord) {
                            $order->changeStatusOnAtSupplier();
                        }
                    }
                    return $this->successResponse();
                } else {
                    return $this->failResponse(['message' => 'Ви не можете підтвердити данний крок']);
                }
            }
        }
        return $this->accessDenied();
    }

    /**
     * @return OrderFileForm|array
     */
    public function actionUploadFiles()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $model = new OrderFileForm();
            if ($model->load(Yii::$app->request->post(), '')) {
                $model->files = UploadedFile::getInstancesByName('files');
                if ($model->validate()) {
                    $model->upload();
                    return $this->successResponse();
                }
            }
            $model->validate();
            return $model;
        } else {
            return $this->accessDenied();
        }
    }

    /**
     * @return array
     * @throws Throwable
     */
    public function actionSetStatusOnApprove()
    {
        Yii::info($_SERVER, 'status');
        Yii::info($this->post, 'status');
        
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->joinWith(['budget'])
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->andWhere(['in', 'order.status', [Order::STATUS_OPEN, Order::STATUS_MOD_APPROVE]])
            ->one();
        if ($order && $order->created_by_user == $user->id && !empty($id)) {
            $comment = ArrayHelper::getValue($this->post, 'comment', null);

            if(!is_null($comment))
            {
                $order->comment = $comment;
                $order->save();
            }

            try {
                $result = $order->changeStatusToOnApprove();
            } catch (NotEnoughBudgetException $e) {
                return $this->failResponse(['message' => $e->getMessage(), 'show_modal' => false]);
            } catch (PONumberRequiredException $e) {
                return $this->failResponse(['message' => $e->getMessage(), 'show_modal' => false]);
            } catch (MissingProductException $e) {
                return $this->failResponse(['message' => $e->getMessage(), 'show_modal' => false]);
            } catch (NotEnoughQuantityProductException $e) {
                return $this->failResponse(['message' => $e->getMessage(), 'show_modal' => false]);
            } catch (BudgetNotAvailableException $e) {
                return $this->failResponse([self::MESSAGE => $e->getMessage(), 'show_modal' => true]);
            } catch (\Throwable $e) {
                Yii::error('changeStatusToOnApprove error: ' . $e->getMessage(), 'order');
                return $this->failResponse(['message' => $e->getMessage(), 'show_modal' => false]);
            }
            if ($result) {
                $order->setDeliveryDate(ArrayHelper::getValue($this->post, 'delivery_date', null));

                try {
                    NotificationHelper::onApproveOrderManager($order);
                } catch (\Throwable $e) {
                    Yii::error('NotificationHelper::onApproveOrderManager error: ' . $e->getMessage(), 'order');
                }
                return $this->successResponse();
            } else {
                return $this->failResponse(['message' => 'Не вдалося перевести замовлення на погодження']);
            }
        }
        return $this->failResponse(['message' => 'Замовлення не знайдено або доступ заборонено']);
    }

    public function actionRepeatFromStart()
    {
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->andWhere(['in', 'status', [Order::STATUS_ON_APPROVE, Order::STATUS_AT_MANAGER]])
            ->one();
        if ($order != false) {
            if ($order->changeStatusToStart()) {
                \api\helpers\Mail\Order::repeatToStartOrder($order);
                return $this->successResponse();
            }
        }
        return $this->failResponse();
    }

    public function actionOrderReceived()
    {
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->andWhere(['status' => Order::STATUS_AT_SUPPLIER])
            ->one();
        if ($order) {
            if ($order->changeStatusToReceived()) {
                return $this->successResponse();
            }
        }
        return $this->failResponse();
    }

    public function actionDeleteProduct()
    {
        if (Yii::$app->user->can('updateOrder') || Yii::$app->user->can('editOnApproveOrder')) {
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $cartId = ArrayHelper::getValue($this->post, 'cartId', 0);
            $orderId = ArrayHelper::getValue($this->post, 'order_id', 0);

            $product = OrderProduct::find()->where(['id' => $id])->one();
            $order = Order::find()->where(['id' => $orderId])->one();

            if ($order && ($order->status == Order::STATUS_OPEN || $order->status == Order::STATUS_ON_APPROVE))
            {
                if ($product)
                {
                    try
                    {
                        $cartProduct = CartProduct::find()->where([
                            'product_id' => $product->product_id,
                            'cart_id' => $cartId
                        ])->one();

                        if($cartProduct)
                        {
                            $cartProduct->delete();
                        }

	                    $orderProductChangeHistory = new OrderProductChangeHistory();

	                    $orderProductChangeHistory->order_id = $order->id;

	                    $orderProductChangeHistory->product_uuid = $product->product_uuid;

	                    $orderProductChangeHistory->quantity = $product->quantity;

	                    $orderProductChangeHistory->price = $product->price;

	                    $orderProductChangeHistory->total_sum = round($product->price * $product->quantity,2);

	                    $orderProductChangeHistory->catalog = $product->catalog;

	                    $orderProductChangeHistory->type_event = 'delete';

	                    $orderProductChangeHistory->created_at = date('U');

	                    $orderProductChangeHistory->product_id = $product->product_id;

	                    $orderProductChangeHistory->save();

                        $result = $product->deleteFromOrder();

                    }
                    catch (MinProductQuantityException $e)
                    {
                        return $this->failResponse([self::MESSAGE => $e->getMessage()]);
                    }

                    if ($result)
                    {
                        return $this->successResponse();
                    }
                    else
                    {
                        return $this->failResponse();
                    }
                }
            }
        }
        return $this->accessDenied();
    }

    public function actionAddCustomProduct()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $model = new CustomProductForm();
            if ($model->load(Yii::$app->request->post(), '')) {
                if ($model->validate()) {
                    $model->addProduct();
                    return $this->successResponse();
                }
            }
            $model->validate();
            return $model;
        }
        return $this->accessDenied();
    }

    public function actionEditCustomProduct()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $model = new CustomProductForm();
            if ($model->load(Yii::$app->request->post(), '')) {
                if ($model->validate()) {
                    $model->editProduct();
                    return $this->successResponse();
                }
            }
            $model->validate();
            return $model;
        }
        return $this->accessDenied();
    }

    public function actionAddProducts()
    {
        if (Yii::$app->user->can('updateOrder') || Yii::$app->user->can('createOrder')) {
            $model = new AddProductForm();
            if ($model->load(Yii::$app->request->post(), '')) {
                if ($model->validate()) {
                    $model->addProducts();
                    return $this->successResponse();
                }
            }
            $model->validate();
            return $model;
        }
        return $this->accessDenied();
    }

    public function actionChangeQuantity()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $quantity = ArrayHelper::getValue($this->post, 'quantity', 0);
            $changeQty = ArrayHelper::getValue($this->post, 'changeQty', true);
            try {
                $result = AddProductForm::changeQuantity($id, $quantity, $changeQty);
            } catch (NotEnoughBudgetException $e) {
                return $this->failResponse([self::MESSAGE => $e->getMessage()]);
            }
            if ($result) {
                return $this->successResponse();
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionDeleteFile()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $model = OrderFile::find()->where(['id' => $id])->one();
            if ($model) {
                $result = $model->delete();
                if ($result) {
                    return $this->successResponse();
                }
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionSetStatusAtSupplier()
    {
        if (Yii::$app->user->can('changeAnyStatusOrder')) {
            /** @var  $user User */
            $user = Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->andWhere(['order.status' => Order::STATUS_AT_MANAGER])
                ->one();
            if ($order) {
                $sendData = new OneSOrder([$id]);
                $sendData->generateXml();
                if ($order->changeStatusOnAtSupplier()) {
                    return $this->successResponse();
                }
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionDelete()
    {
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->andWhere(['order.status' => Order::STATUS_OPEN])
            ->one();
        if ($order) {
            if ($order->delete()) {
                return $this->successResponse();
            }
        }
        return $this->failResponse();
    }

    public function actionUpdate()
    {
        if (Yii::$app->user->can('updateOrder')) {
            $model = new OrderForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
                $result = $model->update();
                if ($result) {
                    return $this->successResponse(['id' => $result]);
                }

                // Заказ не удалось обновить в БД — это реальная ошибка сервера
                Yii::error([
                    'message' => 'Ошибка сохранения при обновлении заказа',
                    'order_id' => $model->id,
                    'errors' => $model->getErrors(),
                ]);
                return $this->failResponse(['message' => 'Не вдалося оновити замовлення']);
            }

            return $model; // вернет 422 с ошибками валидации клиенту
        } else {
            return $this->accessDenied();
        }
    }

    public function actionCancel()
	{
		if (Yii::$app->user->can('updateOrder'))
		{
			$requestData = Yii::$app->getRequest()->getBodyParams();
			
			$user = Yii::$app->user->getIdentity();
			
			$model = Order::find()
				->where(['id' => $requestData['order_id']])
				->andWhere(['company_id' => $user->company_id])
				->one();
			
			$model->status = Order::STATUS_CANCEL;
			
			$model->save();
			
			Order::budgetUnReserve($model);
			
			if(isset($requestData['id']))
			{
				$orderCoord = OrderCoordination::find()
					->where(['order_id' =>  $requestData['order_id']])
					->andWhere(['company_id' => $user->company_id])
					->andWhere(['id' => $requestData['id']])
					->one();
				
				$orderCoord->status_cancel = 1;
				
				$orderCoord->user_id = $user->id;
				
				$orderCoord->updated_at = time();
				
				$orderCoord->save();
			}
			
			return $this->successResponse(['order' => $model]);
		}
		else
		{
			return $this->accessDenied();
		}
	}

    public function actionGetAllOrders()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $query = Order::find()->select(['id']);
        if ($user->company_id) {
            $query->where(['order.company_id' => $user->company_id]);
        } else {
            $query->where(['in', 'order.company_id', $user->getManagerCompanies()]);
        }
        if (isset($this->post['status']) && $this->post['status'] != -1) {
            if (is_array($this->post['status'])) {
                $query->andWhere(['in', 'order.status', $this->post['status']]);
            } else {
                $query->andWhere(['order.status' => $this->post['status']]);
            }
        }
        $result = $query->asArray()->all();
        foreach ($result as $k => $value) {
            $result[$k]['id'] = (int)$value['id'];
        }
        return $this->successResponse(['orders' => $result]);
    }

    public function actionHideModal()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->one();
        if ($order) {
            $order->modal = 0;
            $order->save();
            return $this->successResponse();
        }
        return $this->failResponse();
    }

    public function actionCheckProductAvailable()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->one();
        if ($order) {
            try {
                $result = $order->checkAvailableSpecificationProduct();
            } catch (NotEnoughQuantityProductException $e) {
                $result = 0;
            } catch (NotEnoughAllQuantityProductException $e) {
                $result = 4;
            }
            return $this->successResponse(['stage' => $result]);

        }
        return $this->failResponse();
    }

    public function actionSplitOrder()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->one();
        if ($order && $newOrder = $order->splitOrder()) {
            return $this->successResponse(['id' => $newOrder]);
        }
        return $this->failResponse();
    }

    public function actionDoNotSplitOrder()
    {
        /** @var  $user User */
        $user = \Yii::$app->user->getIdentity();
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        $order = Order::find()
            ->joinWith(['product'])
            ->where(['order.id' => $id])
            ->andWhere(['order.company_id' => $user->company_id])
            ->one();
        if ($order && $order->setStatusModeration()) {
            return $this->successResponse();
        }
        return $this->failResponse();
    }

    public function actionApproveModeration()
    {
        if (Yii::$app->user->can('orderModeration')) {
            /** @var  $user User */
            $user = \Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->one();
            if ($order->approveByModerator()) {
                return $this->successResponse();
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionRejectModeration()
    {
        if (Yii::$app->user->can('orderModeration')) {
            /** @var  $user User */
            $user = \Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->one();
            if ($order->rejectByModerator()) {
                return $this->successResponse();
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionOnApproveModeration()
    {
        if (Yii::$app->user->can('orderModeration')) {
            /** @var  $user User */
            $user = \Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->one();
            if ($order->approveByModerator()) {
                try {
                    $result = $order->changeStatusToOnApprove();
                } catch (NotEnoughBudgetException $e) {
                    return $this->failResponse(['message' => $e->getMessage()]);
                } catch (MissingProductException $e) {
                    return $this->failResponse(['message' => $e->getMessage()]);
                } catch (NotEnoughQuantityProductException $e) {
                    return $this->failResponse(['message' => $e->getMessage()]);
                }
                if ($result) {
                    NotificationHelper::onApproveOrderManager($order);
                    return $this->successResponse();
                } else {
                    return $this->failResponse();
                }
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }


    /**
     * @return array
     */
    public function actionSendToSupplier()
    {
        if (Yii::$app->user->can('changeAnyStatusOrder')) {
            $ids = ArrayHelper::getColumn($this->post['ids'], 'id');
            $result = new OneSOrder($ids);
            $result->generateXml();
            $orders = Order::find()
                ->where(['in', 'id', $ids])
                ->all();
            if ($orders) {
                foreach ($orders as $order) {
                    $order->changeStatusOnAtSupplier();
                }
            }
            return $this->successResponse();
        }
        return $this->accessDenied();
    }

    public function actionDuplicate()
    {
        if (Yii::$app->user->can('orderDuplicate')) {
            /** @var  $user User */
            $user = \Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->one();
            if ($order && $newOrderId = $order->duplicate()) {
                return $this->successResponse(['id' => $newOrderId]);
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionGoBack()
    {
        if (Yii::$app->user->can('orderGoBack')) {
            /** @var  $user User */
            $user = \Yii::$app->user->getIdentity();
            $id = ArrayHelper::getValue($this->post, 'id', 0);
            $order = Order::find()
                ->where(['order.id' => $id])
                ->andWhere(['order.company_id' => $user->company_id])
                ->one();
            if ($order->changeStatusOnAtManager()) {
                return $this->successResponse();
            }
            return $this->failResponse();
        }
        return $this->accessDenied();
    }

    public function actionChangeProduct()
    {
        if (Yii::$app->user->can('orderChangeProduct')) {
            $from = ArrayHelper::getValue($this->post, 'from', false);
            $to = ArrayHelper::getValue($this->post, 'to', false);
            if ($from && $to) {
                $product = OrderProduct::find()
                    ->where(['id' => $from])
                    ->one();
                if ($product && $product->changeProduct($to)) {
                    $sendData = new OneSOrder([$product->order_id]);
                    $sendData->generateXml();
                    return $this->successResponse();
                }
            }
            return $this->failResponse(['message' => 'Виберіть доступний товар']);
        }
        return $this->accessDenied();
    }

    /**
     * @return array
     */
    public function actionChangeOrdersProduct()
    {
        if (Yii::$app->user->can('orderChangeProduct')) {
            $from = ArrayHelper::getValue($this->post, 'from', false);
            $to = ArrayHelper::getValue($this->post, 'to', false);
            $orders = ArrayHelper::getValue($this->post, 'orders', false);
            if ($from && $to && $orders) {
                $ordersAv = Order::find()
                    ->where(['in', 'id', ArrayHelper::getColumn($orders, 'id')])
                    ->andWhere(['in', 'status', [Order::STATUS_RECEIVED, Order::STATUS_AT_SUPPLIER]])
                    ->all();
                if ($ordersAv) {
                    $products = OrderProduct::find()
                        ->where(['product_uuid' => $from])
                        ->andWhere(['in', 'order_id', ArrayHelper::getColumn($ordersAv, 'id')])
                        ->all();
                    if ($products) {
                        $updatedOrders = [];
                        foreach ($products as $product) {
                            $k = $product->changeProduct($to);
                            $updatedOrders[$k] = true;
                        }
                        if ($updatedOrders) {
                            $orderToXml = array_keys($updatedOrders);
                            $sendData = new OneSOrder($orderToXml);
                            $sendData->generateXml();
                            return $this->successResponse();
                        }
                    }
                }
                return $this->failResponse(['message' => 'Ні один товар не було змінено']);
            }
            return $this->failResponse(['message' => 'Виберіть доступний товар']);
        }
        return $this->accessDenied();
    }

    public function actionDuplicateWithModal()
    {
        if (Yii::$app->user->can('orderDuplicate')) {
            $model = new OrderForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate())
            {
                $result = $model->duplicate();

                if ($result)
                {
                    return $this->successResponse([
                        'id'   => $result,
                        'cart' => Cart::getCartByOrderId($result)
                    ]);
                }
            }
            $model->validate();
            return $model;
        }
        return $this->accessDenied();
    }

    public function actionGetListNoAvailableProductForDuplicateOrder()
    {
        if (Yii::$app->user->can('orderDuplicate')) {

            $model = new OrderForm();

            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate())
            {
                return $this->successResponse($model->getListNoAvailableProductForDuplicateOrder());
            }

            $model->validate();

            return $model;
        }
        return $this->accessDenied();
    }

    public function actionCheckNoAvailableProductForOrder()
    {
        if (Yii::$app->user->can('createOrder')) {

            $result = [];

            $user = \Yii::$app->user->getIdentity();
            $cartQuery = Cart::find()->select(array_keys((new Cart())->attributeLabels()));

            $cartQuery->where(['order_id' => 0]);
            $cartQuery->andWhere(['company_id' => $user->company_id]);
            $cartQuery->andWhere(['user_id' => $user->id]);

            $cart = $cartQuery->one();

            if ($cart)
            {
                $query = CartProduct::find()->joinWith([
                    'product',
                    'product.unit',
                    'product.images',
                ]);
                $query->where(['cart_product.cart_id' => $cart->id]);

                $query->groupBy('cart_product.id');

                $list = $query->all();

                $y= 0;


                foreach ($list as $item)
                {
                    $mainProduct = $item->product;

                    if($mainProduct->deleted > 0)
                    {
                        $mainProductTmp = $mainProduct->toArray();

                        $mainProductTmp['images'] = $mainProduct->images;

                        if (!empty($mainProductTmp['images']))
                        {
                            $images = [];

                            foreach ($mainProductTmp['images'] as $image)
                            {
                                $path = Yii::$app->urlManager->createAbsoluteUrl(['/product/' . $image->name]);

                                $images[]['url'] = str_replace('sapi','api',$path);
                            }


                            $mainProductTmp['images'] = $images;

                            unset($images);
                        }

                        $result[] = $mainProductTmp;

                        $item->delete();
                    }

                    ++$y;
                }

                return $this->successResponse(['listDeleteFormCart' => $result]);
            };
        }
        return $this->accessDenied();
    }

    public function actionRoNumber()
    {
        $model = new PurchaseOrderForm();
        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()
            && $model->update()) {
            return $this->successResponse();
        }
        return $this->failResponse();
    }

    public function actionGenerateXls()
    {
        $id = ArrayHelper::getValue($this->post, 'id', 0);
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
//        try {
            $service = new GenerateOrderXls($id, $user->company_id);
            $result = $service->generate();
//        } catch (\Exception $e) {
//            return $this->failResponse([$e->getMessage()]);
//        }
        return $this->successResponse(['link' => $result]);
    }

    public function actionGeneratePdf()
    {
        $id = ArrayHelper::getValue($this->post, 'id', 0);

        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();

        $order = Order::find()
            ->joinWith(['location', 'costCenter', 'budget'])
            ->where(['order.company_id' => $user->company_id])
            ->andWhere(['order.id' => $id])
            ->one();
        if (!$order) {
            throw new \DomainException('Order not found');
        }

        $orderData = [
            'Контрагент' => $order->company->short_name,
            'Бажана дата доставки' => date('d.m.Y', $order->delivery_date),
            'Локація' => $order->location->name,
            'Кост центр' => $order->costCenter->name,
            'Бюджет' => ($order->budget) ? NumberHelper::convertToExcel($order->budget->sum) : '-',
            'Ініціатор' => $order->createdBy->first_name . ' ' . $order->createdBy->last_name,
            '№ Purchase order' => $order->ro_number,
            'Сума замовлення, грн з ПДВ' => NumberHelper::convertToExcel($order->total_sum),
        ];

        $products = OrderProduct::find()
            ->where(['order_id' => $id])
            ->andWhere(['is_delete' => 0])
            ->all();

        $content = $this->renderPartial('pdf-order', [
            'orderData' => $orderData,
            'order' => $order,
            'productHeader' => ["Артикул", "Найменування товару", "Од. вим.", "Кількість", "Ціна з ПДВ", "Сума з ПДВ"],
            'products' => $products
        ]);


        $path = '/report' . DIRECTORY_SEPARATOR . $id . '.pdf';
        $fileName = Yii::getAlias('@api/web') . $path;



        $pdf = Yii::$app->pdf;

        $pdf->output($content, $fileName, 'F');

        $url = Yii::$app->params['backUrl'] . str_replace('\\', '/', $path);

        return $this->successResponse(['link' => $url]);
    }

	public function actionGetOrderProductChangeHistory()
	{
		$result = ['product' => []];

		$id = ArrayHelper::getValue($this->post, 'id', 0);

		$products = OrderProductChangeHistory::find()
			->joinWith(['products'])
			->where(['order_id' => $id])
			->all();

		foreach ($products as $item)
		{
			$result['product'][$item->id] = $item->toArray();

			$original = current($item->products);

			if(!empty($original))
			{
				$result['product'][$item->id]['name'] = $original->name;

				$result['product'][$item->id]['sku'] = $original->sku;

				$photoPath = '';

				$photo = $original->getImages()->one();

				if(!empty($photo) && !is_null($photo))
				{
					$photoPath = Yii::$app->urlManager->createAbsoluteUrl(['/product/' . $photo->name]);
				}

				$result['product'][$item->id]['imgs'] =  $photoPath;
			}
		}

		return $this->successResponse($result);
	}

    public function actionCheckedOrderProductChangeHistory()
    {
	    $id = ArrayHelper::getValue($this->post, 'id', 0);

	    $orderProductChangeHistory = OrderProductChangeHistory::find()
		    ->where(['order_id' => $id])
		    ->all();

	    if (!empty($orderProductChangeHistory))
	    {
	    	foreach ($orderProductChangeHistory as $item)
		    {
		    	$item->is_note = 1;

		    	$item->save();
		    }

		    return $this->successResponse();
	    }
	    return $this->failResponse();
    }

    private function sendEmailAddComment($order_id, $text) {
        $order = Order::find()->where(['=', 'id', $order_id])->one();
        $products = $order->getProduct()->all();
        $company = $order->getCompany()->one();

        $user = Yii::$app->user->getIdentity();
        
        if($user->is_sending_mail == 0)
        {
        	return true;
        }
        
        $subject = '=?utf-8?B?'. base64_encode("Добавлен комментарий к заказу №{$order->id}") .'?=';
        $message = "Добавлен комментарий к заказу №{$order_id} пользователем {$user->first_name} {$user->last_name} c текстом: \"{$text}\"";

        $message .= "\r\n<br>В заказе были такие товары:\r\n<br/>";
        $messageProduct = "";
        if(!empty($products)) {
            $messageProduct .= "<table style='width: 100%;border-collapse: collapse;border: 1px solid black;'>
                <thead>
                    <tr style='width: 100%;border: 1px solid black;'>
                        <th style='border: 1px solid black;'>#</th>
                        <th style='border: 1px solid black;'>Артикул</th>
                        <th style='border: 1px solid black;'>Название</th>
                        <th style='border: 1px solid black;'>Кол-во / Доступно</th>
                        <th style='border: 1px solid black;'>Цена (с ПДВ)</th>
                        <th style='border: 1px solid black;'>Сумма (с ПДВ)</th>
                    </tr>
                </thead>
                <tbody>";

            foreach ($products as $key => $product) {
                $index = ++$key;

                $messageProduct .= "<tr style='border: 1px solid black;text-align: center;'>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$index}</td>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$product->getProduct()->one()->sku}</td>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$product->getProduct()->one()->name}</td>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$product->quantity}</td>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$product->price}</td>";
                $messageProduct .= "<td style='border: 1px solid black;'>{$product->total_sum}</td>";
                $messageProduct .= "</tr>";
            }

            $messageProduct .= "</tbody></table>";
        }
	
	    $messageProduct .= '<br><a href="https://salvi.ua/unsubscribe-mails" target="_blank">Відписатися від розсилки листів</a>';

        $message .= "\r\n".$messageProduct;
        return $this->sendEmail($company->manager_email, $subject, $message);

    }

    private function sendEmail($to, $subject, $message)
    {
        try {
            Yii::$app->queue->push(new \api\job\SendEmail([
                'to' => $to,
                'subject' => $subject,
                'htmlBody' => $message,
            ]));
        } catch (\Throwable $e) {
            Yii::error('Failed to push sendEmail to queue: ' . $e->getMessage(), 'mailer');
        }
        return true;
    }
}
