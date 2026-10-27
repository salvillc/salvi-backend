<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 04.02.2020
 * Time: 16:03
 */

namespace api\controllers\v1;

use api\controllers\RestController;
use api\helpers\CategoryTree;
use api\helpers\IntegrationHelper;
use api\models\integration\CategoriesForm;
use api\models\integration\ProductsForm;
use api\models\integration\UpdatePricesForm;
use common\models\Category;
use common\models\Order;
use common\models\OrderProductChangeHistory;
use common\models\OrderProduct;
use common\models\Product;
use common\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use api\helpers\TypeWebsite;
use yii\caching\TagDependency;


/**
 * Class IntegrationController
 * @package api\controllers\v1
 */
class IntegrationController extends RestController
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['access'] = [
            'class' => AccessControl::className(),
            'only'  => ['orders', 'product', 'update-product'],
            'rules' => [
                [
                    'allow'   => true,
                    'actions' => ['orders', 'product', 'update-product'],
                    'roles'   => ['@'],
//                    'ips' => ['127.0.0.1']
                ],
            ],
        ];

        return $behaviors;
    }

    public function actionOrders()
    {

        //if (Yii::$app->user->can('apiModel')) {
            $today = date('Y-m-d H:i:s', time());

            $startDate = date('2023-04-04 00:00:00');

            $get = Yii::$app->request->get();
            $arguments = self::arguments($get);
            $query = Order::find()
                ->joinWith(['location', 'costCenter', 'companyEntity', 'contract.supplier', 'product', 'createdBy'])
                ->andWhere(['order.status' => Order::STATUS_AT_SUPPLIER])
                ->andWhere(['>', 'order.created_at', 1584112034])
                ->andWhere(['>=', 'order.updated_at', strtotime($startDate)])
                ->andWhere(['<=', 'order.updated_at', strtotime($today)])
                ->groupBy('order.id')
                ->orderBy('updated_at DESC');
            $total = $query->count();
            if (!isset($get['showAll']) || isset($get['showAll']) && $get['showAll'] != true) {
                $query->offset($arguments['perPage'] * $arguments['page'])
                    ->limit($arguments['perPage']);
            }
            $orders = $query->all();
            $array = [];
            if ($orders) {

                foreach ($orders as $i => $order)
                {
                    $comment = $this->customProduct($order->product);
                    $array['orders'][$i] = [
                        'id'              => $order->id,
                        'uuid_client'     => ArrayHelper::getValue($order, 'costCenter.uuid_client', ''),
                        'company_edrpou'  => ArrayHelper::getValue($order, 'companyEntity.edrpou', ''),
                        'uuid_contract'   => ArrayHelper::getValue($order, 'costCenter.uuid_contract', ''),
                        'supplier_edrpou' => ArrayHelper::getValue($order, 'contract.supplier.edrpou', ''),
                        'contract_number' => ArrayHelper::getValue($order, 'contract.number', ''),
                        'products'        => $this->product($order->product),
                        'comment'         => $order->comment . ' ' . $comment,
                        'delivery'        => [
                            'location'  => ArrayHelper::getValue($order, 'location.fullAddress', ''),
                            'location_name'  => ArrayHelper::getValue($order, 'location.name', ''),
                            'cost'      => ArrayHelper::getValue($order, 'costCenter.name', ''),
                            'initiator' => ($order->createdBy) ? $this->userInfo($order->createdBy) : '',
                        ],
                        'delivery_date'   => date('d.m.Y', $order->delivery_date),
                        'ro_number'       => $order->ro_number,
                    ];
                }
            }
            
            $array['total'] = $total;
	
	
		    $fileName = Yii::getAlias('@console') . '/xml/orders/orders_' . time() . '.json';
	        $fp = fopen($fileName, 'a+');
	        fwrite($fp, json_encode($array,256));
	        fclose($fp);
	        
	         $file = Yii::$app->ftp->put($fileName);

	    return $this->successResponse($array);
      // }
       //  return $this->accessDenied();
    }

	public function actionOrdersTest()
	{

			$today = date('Y-m-d H:i:s', time());

			$startDate = date('Y-04-04 00:00:00');

			$get = Yii::$app->request->get();
			$arguments = self::arguments($get);

			$query = Order::find()
				->joinWith(['location', 'costCenter', 'companyEntity', 'contract.supplier', 'product', 'createdBy'])
				->andWhere(['order.status' => Order::STATUS_AT_SUPPLIER])
				->andWhere(['order.id' => 24039])
//				->andWhere(['>', 'order.created_at', 1584112034])
//				->andWhere(['>=', 'order.updated_at', strtotime($startDate)])
//				->andWhere(['<=', 'order.updated_at', strtotime($today)])
				->groupBy('order.id')
				->orderBy('updated_at DESC');
			$total = $query->count();

			if (!isset($get['showAll']) || isset($get['showAll']) && $get['showAll'] != true) {
				$query->offset($arguments['perPage'] * $arguments['page'])
					->limit($arguments['perPage']);
			}
			$orders = $query->all();
			$array = [];
			if ($orders) {

				foreach ($orders as $i => $order)
				{
					$comment = $this->customProduct($order->product);


					$array['orders'][$i] = [
						'id'              => $order->id,
						'uuid_client'     => ArrayHelper::getValue($order, 'costCenter.uuid_client', ''),
						'company_edrpou'  => ArrayHelper::getValue($order, 'companyEntity.edrpou', ''),
						'uuid_contract'   => ArrayHelper::getValue($order, 'costCenter.uuid_contract', ''),
						'supplier_edrpou' => ArrayHelper::getValue($order, 'contract.supplier.edrpou', ''),
						'contract_number' => ArrayHelper::getValue($order, 'contract.number', ''),
						'products'        => $this->productTest($order->product),
						'comment'         => $order->comment . ' ' . $comment,
						'delivery'        => [
							'location'  => ArrayHelper::getValue($order, 'location.fullAddress', ''),
							'location_name'  => ArrayHelper::getValue($order, 'location.name', ''),
							'cost'      => ArrayHelper::getValue($order, 'costCenter.name', ''),
							'initiator' => ($order->createdBy) ? $this->userInfo($order->createdBy) : '',
						],
						'delivery_date'   => date('d.m.Y', $order->delivery_date),
						'ro_number'       => $order->ro_number,
					];
				}
			}

			$array['total'] = $total;

			return $this->successResponse($array);
	}

	public function actionImportOrders()
	{
		$result = [
			'notFoundOrders' => [],
			'updateOrders' => [],
			'listEmailToSendMails' => [],
		];

		$requestData = Yii::$app->request->post();

		$date = date('Y-m-d');
		$date1 = date('Y-m-d H:i:s');
		$file = Yii::getAlias('@runtime/logs/orders/upload_' . $date . '.log');
		$fp = fopen($file, 'a+');
		fwrite($fp, "\r\n----------------------------------------------------");
		fwrite($fp, "\r\nСтарт импорта: " . $date1);
		fwrite($fp, "\r\nВыгрузка: " . json_encode($requestData,JSON_UNESCAPED_UNICODE));

		$date = date('U');

		if(isset($requestData['orders']))
		{
			foreach ($requestData['orders'] as $orderImport)
			{
				$mailHtml = "<div class='salvi-block' style=\"font-family:'Helvetica Neue', Arial;color:#666666!important\">";

				$productsDelete = [];

				$productsChangeQuantity = [];

				$order = Order::find()
					->joinWith(['product'])
					->andWhere(['order.id' => str_ireplace(' ','',$orderImport['id'])])
					->one();

				if(!is_null($order))
				{
					$mailHtml .= '<p>#FIO#</p>';

					$mailHtml .= '<p>Локація: <b>'. $order->location->name .'</b></p>';

					$mailHtml .= '<p>Кост-центр: <b>'. $order->costCenter->name .'</b></p>';

					if(!is_null($order->delivery_date) && is_int($order->delivery_date))
					{
						$mailHtml .= '<p>Дата доставки - <b>'.  date('d.m.Y',$order->delivery_date) .'</b> </p>';
					}
					else
					{
						$mailHtml .= '<p>Дата доставки - відсутня</p>';
					}

					$mailHtml .= '<p>До вашого <a href="https://salvi.ua/order/'.$order->id.'"><b>замовлення №'. $order->id .' від '. date('Y.m.d H:i:s',$order->created_at) .'</b></a> було внесено зміни:</p>';

					$order->status = Order::getStatusBy1cStatus($orderImport['status'])['id'];
					
					$order->delivery_date = strtotime($orderImport['delivery_date']);

					$total_sum_old = $order->total_sum;

					if(isset($orderImport['ttn']))
					{
						$order->ttn = $orderImport['ttn'];
					}
					
					if(isset($orderImport['one_day_shipment']))
					{
						$order->one_day_shipment = $orderImport['one_day_shipment'];
					}

					$orderProducts = $order->product;

					$orderSum = 0;

					foreach ($orderProducts as $product)
					{
						if($productImport = $this->getProductFromImport($product->product_uuid,$orderImport['products']))
						{
							$productInDb = Product::find()
								->joinWith(['categories'])
								->where(['product.uuid' => $productImport['uuid']])
								->one();

							if(!is_null($productInDb) && $product->custom == 1 && $productInDb->deleted == 0)
							{
								$product->custom = 0;

								$product->product_id = $productInDb->id;

								if(!is_null($productInDb->categories))
								{
									$product->parent_category_uuid = current($productInDb->categories)->uuid;
								}
							}

							$product->name = $productImport['name'];

							if($product->quantity != $productImport['quantity'])
							{
								$productsChangeQuantity[$product->id] = [
									'name' => $productImport['name'],
									'quantity' => $product->quantity,
									'quantity_new' => $productImport['quantity'],
								];
							}

							$product->quantity = $productImport['quantity'];

							$product->price = $productImport['price'];

							$product->total_sum = $productImport['quantity'] * $productImport['price'];

							if(isset($productsChangeQuantity[$product->id]))
							{
								$productsChangeQuantity[$product->id]['price'] = $product->price;

								$productsChangeQuantity[$product->id]['total_sum'] = $product->total_sum;
							}

							if($productImport['specification'] == 'true')
							{
								$product->catalog = 0;
							}
							else
							{
								$product->catalog = 1;
							}

							$orderSum += $product->total_sum;
							
							$product->save();
						}
						else
						{
							$productInDb = Product::find()
								->joinWith(['categories'])
								->where(['product.uuid' => $product->product_uuid])
								->one();

							$orderProductChangeHistory = new OrderProductChangeHistory();

							$orderProductChangeHistory->order_id = $order->id;

							$orderProductChangeHistory->product_uuid = $product->product_uuid;

							$orderProductChangeHistory->quantity = $product->quantity;

							$orderProductChangeHistory->price = $product->price;

							$orderProductChangeHistory->total_sum = round($product->price * $product->quantity,2);

							$orderProductChangeHistory->catalog = $product->catalog;

							$orderProductChangeHistory->type_event = 'delete';

							$orderProductChangeHistory->name = $productImport['name'];

							$orderProductChangeHistory->created_at = $date;

							if(!is_null($productInDb))
							{
								$product->name = $productInDb->name;

								$orderProductChangeHistory->product_id = $productInDb->id;
							}

							$orderProductChangeHistory->save();
							
							$productsDelete[] = $product;

							$product->delete();
						}
					}

					if(!empty($productsChangeQuantity))
					{
						$i = 1;

						$mailHtml .= '
	                                <div><p>Товари які <span style="color:#565656"><b>змінили</b></span> кількість</p></div>
	                                <table  style="border-collapse: collapse;border: 1px solid black;">
	                                <tr style="color: white;background-color: #666666;">
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">№</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Товар</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;" >Кількість була</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Кількість стала</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">од. вим</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Ціна з ПДВ</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;" >Сума з ПДВ</th>
								    </tr>';

						foreach ($productsChangeQuantity as $item)
						{
							$mailHtml .= '<tr>';

							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$i</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;width: 350px;text-align: left;\">".$item['name']."</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">".$item['quantity']."</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">".$item['quantity_new']."</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">шт</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">".$item['price']."</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">".$item['total_sum']."</th>";
							$mailHtml .= '</tr>';

							++$i;
						}

						$mailHtml .= '
								  </table>
	                        ';
					}

					unset($item);

					if(!empty($orderImport['products']))
					{
						$i = 1;

						$mailHtml .= '
	                                <div><p>Товари які були <span style="color:#38761d;"><b>додані</b></span>  до замовлення</p></div>
	                                <table style="border-collapse: collapse;border: 1px solid black;">
	                                <tr style="color: white;background-color: #38761d;">
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">№</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Товар</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Кількість</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">од. вим</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Ціна з ПДВ</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Сума з ПДВ</th>
								    </tr>';

						foreach ($orderImport['products'] as $productFromImport)
						{
							$product = Product::find()->joinWith(['categories'])->where(['product.uuid' => $productFromImport['uuid']])->one();

							$newProduct = new OrderProduct();
							$newProduct->created_at = $date;
							$newProduct->updated_at = $date;


							$newProduct->quantity = $productFromImport['quantity'];
							$newProduct->price = $productFromImport['price'];
							$newProduct->total_sum = $productFromImport['price'] * $productFromImport['quantity'];

							$orderSum += $newProduct->total_sum;

							$newProduct->order_id = $order->id;

							if ($productFromImport['specification'] == 'true')
							{
								$newProduct->catalog = 0;
							}
							else
							{
								$newProduct->catalog = 1;
							}

							$newProduct->on_approve_quantity = 1;

							$orderProductChangeHistory = new OrderProductChangeHistory();

							if (!is_null($product) && $product->deleted == 0)
							{
								$categoryUuid = '';

								if (!is_null($product->categories))
								{
									$categoryUuid = current($product->categories)->uuid;
								}

								$newProduct->product_id = $product->id;
								$newProduct->product_uuid = $product->uuid;
								$newProduct->parent_category_uuid = $categoryUuid;
								$newProduct->name = $product->name;

								$orderProductChangeHistory->product_id = $product->id;
								$orderProductChangeHistory->product_uuid = $product->uuid;
							}
							else
							{
								$newProduct->product_uuid = $productFromImport['uuid'];
								$newProduct->name = $productFromImport['name'];

								$newProduct->custom = 1;
							}


							$orderProductChangeHistory->order_id = $order->id;
							$orderProductChangeHistory->quantity = $newProduct->quantity;
							$orderProductChangeHistory->price = $newProduct->price;
							$orderProductChangeHistory->total_sum = $newProduct->total_sum;
							$orderProductChangeHistory->catalog = $newProduct->catalog;
							$orderProductChangeHistory->type_event = 'add';
							$orderProductChangeHistory->name = $productFromImport['name'];
							$orderProductChangeHistory->created_at = $date;

							$orderProductChangeHistory->save();
							
							$mailHtml .= '<tr>';

							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$i</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;width: 350px;text-align: left;\">$newProduct->name</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$newProduct->quantity</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">шт</th>";
							$mailHtml .= '<th  style="border-collapse: collapse;border: 1px solid black;padding : 10px;">'.$newProduct->price.'</th>';
							$mailHtml .= '<th  style="border-collapse: collapse;border: 1px solid black;padding : 10px;">'.$newProduct->total_sum.'</th>';
							$mailHtml .= '</tr>';

							++$i;

							$newProduct->save();
						}

						$mailHtml .= '
								  </table>
	                        ';
					}

					if(!empty($productsDelete))
					{
						$i = 1;

						$mailHtml .= '
	                                <div><p>Товари які були <span style="color:#cc0000"><b>видалені</b></span> із замовлення</p></div>
	                                <table style="border-collapse: collapse;border: 1px solid black;">
	                                <tr style="color: white;background-color: #cc0000;">
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">№</th>
								       <th	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;" >Товар</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Кількість</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">од. вим</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Ціна з ПДВ</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;"	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Сума з ПДВ</th>
								    </tr>';

						foreach ($productsDelete as $item)
						{
							$mailHtml .= '<tr>';

							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$i</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;width: 350px;text-align: left;\">$item->name</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$item->quantity</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">шт</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$item->price</th>";
							$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">".round($item->total_sum,2)."</th>";
							$mailHtml .= '</tr>';

							++$i;
						}

						$mailHtml .= '
								  </table>
	                        ';
					}

					unset($item);


					if(!empty($productsDelete) || !empty($orderImport['products']) || !empty($productsChangeQuantity))
					{
						$mailHtml .= '
	                           <div><p style="color:#565656"><b>Сума замовлення після змін</b></p></div>
	                                <table style="border-collapse: collapse;border: 1px solid black;">
	                                 <tr style="color: white;background-color: #666666;">
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Було грн з ПДВ</th>
								       <th 	style="border-collapse: collapse;border: 1px solid black;padding : 10px;">Стало грн з ПДВ</th>
								    </tr>';
						$mailHtml .= '<tr>';

						$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$total_sum_old</th>";
						$mailHtml .= "<th style=\"border-collapse: collapse;border: 1px solid black;padding : 10px;\">$orderSum</th>";
						$mailHtml .= '</tr>';
						$mailHtml .= '</table><br>';
						
						$mailHtml .= '<a href="https://salvi.ua/unsubscribe-mails" target="_blank">Відписатися від розсилки листів</a>';
						$mailHtml .= '</div>';

						$createrOrder = User::find()->where(['id' => $order->created_by_user])->select(['email','username','is_sending_mail'])->one();

						$email = $createrOrder->email;

						$mailHtml = str_replace('#FIO#',$createrOrder->username,$mailHtml);

						$subject = 'Зміни до замовлення № '.$order->id .' від '. date('Y.m.d H:i:s',$order->created_at);

						$result['listEmailToSendMails'][] = $email;

						if(TypeWebsite::getType() == 'prod' && $createrOrder->is_sending_mail == 1)
						{
							$this->sendEmail($email,$subject,$mailHtml);
						}
					}

					$order->total_sum = $orderSum;
					
					$order->save();

					$order = Order::find()
						->joinWith(['product'])
						->andWhere(['order.id' => $order->id])
						->one();

					if(empty($order->product))
					{
						$order->status = Order::STATUS_CANCEL;
						
						Order::budgetUnReserve($order);
					}
					else
					{
						if($order->status == Order::STATUS_COMPLETED || $order->status == Order::STATUS_TRANSPORT_SERVICE || $order->status == Order::STATUS_ON_PROVISION)
						{
							Order::budgetReserve($order);
						}
					}
					
					$order->save();
					
					$result['updateOrders'][] = $order['id'];
				}
				else
				{
					$result['notFoundOrders'][] = $orderImport['id'];
				}
			}
		}

		fwrite($fp, "\r\nВыгрузка окночена: " . json_encode($result));
		fclose($fp);

		return $this->successResponse(['data' => $result]);

	}

	public function actionImportLeftovers()
	{
		$result = [
			'notFoundProducts' => [],
			'updateProducts' => [],
		];

		$requestData = Yii::$app->request->post();

		$date = date('Y-m-d');
		$date1 = date('Y-m-d H:i:s');
		$file = Yii::getAlias('@runtime/logs/products/upload_' . $date . '.log');
		$fp = fopen($file, 'a+');
		fwrite($fp, "\r\n----------------------------------------------------");
		fwrite($fp, "\r\nСтарт импорта: " . $date1);
		fwrite($fp, "\r\nВыгрузка: " . json_encode($requestData,JSON_UNESCAPED_UNICODE));

		if(isset($requestData['leftovers']))
		{
			foreach ($requestData['leftovers'] as $leftover)
			{
				if(isset($leftover['uuid']) && isset($leftover['quantity']))
				{
					if(!empty($leftover['uuid']))
					{
						$product = Product::find()->where(['uuid' => $leftover['uuid']])->one();

						if(!is_null($product))
						{
							if(isset($leftover['leftovers_salvi']) && (int)$leftover['leftovers_salvi'] >= 0)
							{
								$product['leftovers_salvi'] = (int)$leftover['leftovers_salvi'];
							}
							
							if(isset($leftover['leftovers_supplier']) && (int)$leftover['leftovers_supplier'] >= 0)
							{
								$product['leftovers_supplier'] = (int)$leftover['leftovers_supplier'];
							}
							
							if(isset($leftover['supplier_delivery_postpone']) && (int)$leftover['supplier_delivery_postpone'] >= 0 && (int)$leftover['supplier_delivery_postpone'] <= 2)
							{
								$product['supplier_delivery_postpone'] = (int)$leftover['supplier_delivery_postpone'];
							}
							
							$product->updated_at = time();

							$product->update();

							$result['updateProducts'][] = $leftover['uuid'];
						}
						else
						{
							$result['notFoundProducts'][] = $leftover['uuid'];
						}
					}
					else
					{
						$result['notFoundProducts'][] = $leftover['uuid'];
					}
				}
			}
		}

		fwrite($fp, "\r\nВыгрузка окночена: " . json_encode($result));
		fclose($fp);

		return $this->successResponse(['data' => $result]);
	}

    private function getProductFromImport($productUuid = '',&$listProduct = [])
    {
        foreach ($listProduct as $key => $item)
        {
            if($item['uuid'] == $productUuid)
            {
                unset($listProduct[$key]);

                return $item;
            }
        }

        return false;
    }

    /**
     * @param User $user
     * @return array
     */
    private function userInfo(User $user)
    {
        $result = [];

        $result['username'] = $user->username;

        $result['first_name'] = $user->first_name;

        $result['last_name'] = $user->last_name;

        $result['phone_number'] = $user->phone_number;

        $result['email'] = $user->email;

        return $result;
    }

    private function product($data)
    {
        $result = [];

        if ($data)
        {
            foreach ($data as $item)
            {
                /** @var $item OrderProduct */
	            if (!$item->custom && !$item->is_delete)
	            {
		            $is_specification = false;

		            if($item->catalog == 0)
		            {
			            $is_specification = true;
		            }

		            $result[] = [
			            'uuid'     => $item->product_uuid,
			            'quantity' => $item->quantity,
			            'price'    => str_replace('.', ',', $item->price),
			            'is_specification' => $is_specification,
		            ];
	            }
            }
        }

        return $result;
    }

	private function productTest($data)
	{
		$result = [];

		if ($data)
		{
			foreach ($data as $item)
			{
				/** @var $item OrderProduct */
				if (!$item->custom && !$item->is_delete)
				{
					$is_specification = false;

					if($item->catalog == 0)
					{
						$is_specification = true;
					}

					$result[] = [
						'uuid'     => $item->product_uuid,
						'quantity' => $item->quantity,
						'price'    => str_replace('.', ',', $item->price),
						'is_specification' => $is_specification,
					];
				}
			}
		}
		return $result;
	}

    /**
     * @param $data
     * @return string
     */
    private function customProduct($data)
    {
        $result = '';
        if ($data) {
            foreach ($data as $item) {
                /** @var $item OrderProduct */
                if ($item->custom) {
                    $result .= $item->name . '_' . $item->quantity . '_' . 'шт_' . str_replace('.', ',', $item->price) . ' ';
                }
            }
        }
        return $result;
    }

    /**
     * @param bool $uuid
     * @return array
     */
    public function actionProduct($uuid = false)
    {
        if (Yii::$app->user->can('apiModel')) {
            if ($uuid) {
                $product = Product::find()
                    ->joinWith([
                        'unit',
                        'productUktzed',
                        'vendor',
                        'productBrand',
                        'categoriesNew',
                        'productAttribute',
                        'productAttribute.value',
                        'productAttribute.attr',
                    ])
                    ->where(['product.uuid' => $uuid])
                    ->andWhere(['product.deleted' => Product::STATUS_ACTIVE])
                    ->one();
                if ($product) {
                    $temp = [
                        'id'          => $product->id,
                        'uuid'        => $product->uuid,
                        'uktzed'      => IntegrationHelper::getUktzed($product),
                        'props'       => IntegrationHelper::getProps($product),
                        'type'        => IntegrationHelper::getType($product),
                        'sku'         => $product->sku,
                        'name'        => $product->name,
                        'category'    => IntegrationHelper::getCategory($product),
                        'brand'       => IntegrationHelper::getBrand($product),
                        'vendor'      => IntegrationHelper::getVendor($product),
                        'description' => $product->description,
                        'unit'        => IntegrationHelper::getUnit($product),
                        'image'       => IntegrationHelper::getImage($product),
                        'buy_price'   => $product->price,
                        'vat'         => $product->vat,
                        'deleted'     => $product->delete
                    ];
                    return $this->successResponse(['product' => $temp]);
                }
                return $this->failResponse();
            } else {
                $products = Product::find()
                    ->joinWith([
                        'unit',
                        'productUktzed',
                        'vendor',
                        'productBrand',
                        'categoriesNew',
                        'productAttribute',
                        'productAttribute.value',
                        'productAttribute.attr',
                    ])
                    ->where(['is_buffer' => 1])
                    ->andWhere(['buffer_status' => Product::BUFFER_WAITING])
                    ->groupBy('product.id')
                    ->all();
                $temp = [];
                if ($products) {
                    foreach ($products as $product) {
                        $temp[] = [
                            'id'          => $product->id,
                            'uuid'        => $product->uuid,
                            'uktzed'      => IntegrationHelper::getUktzed($product),
                            'props'       => IntegrationHelper::getProps($product),
                            'type'        => IntegrationHelper::getType($product),
                            'sku'         => $product->sku,
                            'name'        => $product->name,
                            'category'    => IntegrationHelper::getCategory($product),
                            'brand'       => IntegrationHelper::getBrand($product),
                            'vendor'      => IntegrationHelper::getVendor($product),
                            'description' => $product->description,
                            'unit'        => IntegrationHelper::getUnit($product),
                            'image'       => IntegrationHelper::getImage($product),
                            'buy_price'   => $product->price,
                            'vat'         => $product->vat
                        ];
                        $product->buffer_status = Product::BUFFER_APPROVED;
                        $product->save();
                    }
                }
                return $this->successResponse(['products' => $temp]);
            }
        }
        return $this->accessDenied();
    }

    /**
     * @param $args
     * @return array
     */
    private static function arguments($args)
    {
        $result = [];
        if (isset($args['page'])) {
            $result['page'] = $args['page'];
            if ($result['page'] <= 1) {
                $result['page'] = 0;
            } else {
                $result['page'] -= 1;
            }
        } else {
            $result['page'] = 0;
        }

        if (isset($args['perPage'])) {
            $result['perPage'] = $args['perPage'];
            if ($result['perPage'] <= 0) {
                $result['perPage'] = 50;
            }
        } else {
            $result['perPage'] = 50;
        }

        return $result;
    }

    /**
     * @return ProductsForm|array
     * @throws \yii\base\InvalidConfigException
     */
    public function actionUpdateProduct()
    {
//        if (Yii::$app->user->can('apiModel')) {

            $model = new ProductsForm();
	        $file = Yii::getAlias('@runtime/logs/UpdateProduct.log');
	        $fp = fopen($file, 'a+');
	        fwrite($fp, date('d-m-Y H:i:s') . json_encode(Yii::$app->getRequest()->getBodyParams(),256) . PHP_EOL);
	        fclose($fp);

            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
                $result = $model->save();

                $file = Yii::getAlias('@runtime/logs/updateProducts.log');
                $fp = fopen($file, 'w+');
                fwrite($fp, time(),256);
                fclose($fp);

                $listCacheToClear = [
                    'GetSeasonGoods',
                    'CategoryPopular',
                    'Get4BlockCategories',
                    'GetCategoryPage',
                    'GetProductByCategoryOnCategoryPage',
                ];

                foreach ($listCacheToClear as $item)
                {
                    TagDependency::invalidate(
                        Yii::$app->cache,
                        $item
                    );
                }

                $file = Yii::getAlias('@runtime/logs/integration_res.log');
                $fp = fopen($file, 'a+');
                fwrite($fp, date('d-m-Y H:i:s') . json_encode($result,256));
                fclose($fp);

//                exec('cd /var/www/salvi/www_api/; php yii search/update-table create > update-table.txt &');
                return $this->successResponse($result);
            }

            $model->validate();

            $file = Yii::getAlias('@runtime/logs/integration_res.log');
            $fp = fopen($file, 'a+');
            fwrite($fp, date('d-m-Y H:i:s') . 'integration errror: ProductsForm');
            fclose($fp);

            return $model;
//       }
//       return $this->accessDenied();
    }
    /**
     * @return ProductsForm|array
     * @throws \yii\base\InvalidConfigException
     */
    public function actionTestUpdateProduct()
    {
//        if (Yii::$app->user->can('apiModel')) {
            $model = new ProductsForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
                $result = $model->save();
                return $this->successResponse($result);
            }

            $file = Yii::getAlias('@runtime/logs/integration_res.log');
            $fp = fopen($file, 'a+');
            fwrite($fp, date('d-m-Y H:i:s') . 'integration errror');
            fclose($fp);

            $model->validate();
            return $model;
//        }
//        return $this->accessDenied();
    }

    public function beforeAction($action)
    {
        $data = Yii::$app->getRequest()->getRawBody();
        $file = Yii::getAlias('@runtime/logs/integration.log');
        $fp = fopen($file, 'a+');
        fwrite($fp, date('d-m-Y H:i:s') . $data);
        fclose($fp);
        return parent::beforeAction($action); // TODO: Change the autogenerated stub
    }


    public function actionUpdatePrice()
    {
        if (Yii::$app->user->can('apiModel')) {
            $model = new UpdatePricesForm();
            if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate()) {
                $result = $model->save();
                return $this->successResponse($result);
            }
            $model->validate();
            return $model;
        }
        return $this->accessDenied();
    }

    public function actionUpdateCategories()
    {
        //if (Yii::$app->user->can('apiModel'))
       // {
            $requestData = Yii::$app->getRequest()->getBodyParams();

            $file = Yii::getAlias('@runtime/logs/integration_category.log');
            $fp = fopen($file, 'a+');
            fwrite($fp, date('d-m-Y H:i:s') . json_encode(Yii::$app->getRequest()->getBodyParams(),256) . PHP_EOL);
            fclose($fp);

            $categories = [
                'categories' => []
            ];

            if(isset($requestData['drevo']) && !empty($requestData['drevo']))
            {
                foreach ($requestData['drevo'] as $item)
                {
                	if(!isset($item['additional_category']))
	                {
	                	$item['additional_category'] = 'false';
	                }

                    $cat = $item;

                    if(isset($item['views']) && !empty($item['views']))
                    {
                        $cat['has_child'] = 1;
                    } else {
                        $cat['has_child'] = 0;
                    }
                    unset($cat['views']);

                    $categories['categories'][] = $cat;

                    if(isset($item['views']) && !empty($item['views']))
                    {
                        foreach ($item['views'] as $view)
                        {
                            if(isset($view['views']) && !empty($view['views']))
                            {
                                $view['has_child'] = 1;
                            } else {
                                $view['has_child'] = 0;
                            }

                            $categories['categories'][] = $view;
                        }
                    }

                }
            }

            $model = new CategoriesForm();

            if ($model->load($categories, '') && $model->validate())
            {
                $result = $model->save();

                TagDependency::invalidate(
                    Yii::$app->cache,
                    'CategoryTreeAllCategories'
                );

                $categoriesTree  =  new CategoryTree();

                $Category = new Category();

                $allListCategories = $Category->find()->where(['<>','code',''])->asArray()->all();

                $categoriesTree = $categoriesTree->buildTree($allListCategories);

                foreach ($categoriesTree as $element)
                {
                    if (isset($element['children']))
                    {
                        $url = '/' . $element['code'] . '/' ;

                        $category = Category::find()->where(['id' => $element['id']])->one();

                        $category->full_path = $url ;
                        $category->level = 1 ;

                        $category->save();

                        foreach ($element['children'] as $element2)
                        {
                            if (isset($element2['children']))
                            {
                                $url = '/' . $element['code'] . '/' . $element2['code'] . '/';

                                $category = Category::find()->where(['id' => $element2['id']])->one();

                                $category->full_path = $url ;
                                $category->level = 2 ;

                                $category->save();

                                foreach ($element2['children'] as $element3)
                                {
                                    $url = '/' . $element['code'] . '/' . $element2['code'] . '/' . $element3['code'] . '/';

                                    $category = Category::find()->where(['id' => $element3['id']])->one();

                                    $category->full_path = $url ;
                                    $category->level = 3 ;

                                    $category->save();
                                }
                            }
                        }
                    }
                }

                $file = Yii::getAlias('@runtime/logs/updateCategories.log');
                $fp = fopen($file, 'w+');
                fwrite($fp, time(),256);
                fclose($fp);

                TagDependency::invalidate(
                    Yii::$app->cache,
                    'CategoryTreeAllCategories'
                );

                return $this->successResponse($result);
            }

            $model->validate();
            return $model;
        //}
        //return $this->accessDenied();
    }

	private function sendEmail($to, $subject, $message) {
		try {
			\Yii::$app->queue->push(new \api\job\SendEmail([
				'to' => $to,
				'subject' => $subject,
				'htmlBody' => $message,
			]));
		} catch (\Throwable $e) {
			\Yii::error('Failed to push IntegrationController sendEmail to queue: ' . $e->getMessage(), 'mailer');
		}
		return true;
	}

    public function actionTest()
    {
        $data = Yii::$app->getRequest()->getBodyParams();


//        $products = Product::find()->all();
//
//        foreach ($products as $product)
//        {
//            $product->synonym = null;
//
//            $product->save();
//        }
//        die();

        $logFile = "/var/www/salvi/www_api/indexElasticSettings.txt";
        file_put_contents($logFile,json_encode($data));


        exec('cd /var/www/salvi/www_api/; php yii search/search create > indexElastic.log');


        $logFile = "/var/www/salvi/www_api/indexElastic.log";
        $log = file_get_contents($logFile);

        echo '<pre>';
        print_r($log);
        echo '</pre>';
//        $ttt = SpecificationProduct::find()->where(['specification_id' => 471])->all();
//
//        echo '<pre>';
//        print_r($ttt);
//        echo '</pre>';
//        die();
////        $ttt = Specification::find()->where(['id' => 471])->all();
////
////        echo '<pre>';
////        print_r($ttt);
////        echo '</pre>';
////        die();
//        $ttt = SpecificationLocation::find()->where(['location_id' => 471])->all();
//        echo '<pre>';
//        print_r($ttt);
//        echo '</pre>';
//        die();
//        $startDate = date('2023-01-01');
//
//        $endDate = date('2024-01-01');
//
//        $responseDbContract = Contract::find()
//            ->joinWith(['supplier'])
//            ->Where(['>=', 'contract.date_end', strtotime($startDate)])
//            ->andWhere(['<=', 'contract.date_end', strtotime($endDate)])
//            ->all();
//
//        $fp = fopen('/var/www/salvi/www_api/file.csv', 'w');
//
//        $fields = [
//            'Контрагент(клиент)',
//            'name',
//            'срок действия',
//        ];
//
//        fputcsv($fp, $fields, ';', '"');
//
//        foreach($responseDbContract as $item)
//        {
//            $fields = [
//                $item->supplier->name,
//                $item->name,
//                date('d.m.Y',$item->date_start) . ' - ' . date('d.m.Y',$item->date_end)
//            ];
//
//            fputcsv($fp, $fields, ';', '"');
//        }
//
//        fclose($fp);
    }
}
