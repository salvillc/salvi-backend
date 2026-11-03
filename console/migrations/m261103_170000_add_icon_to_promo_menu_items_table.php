<?php

use yii\db\Migration;

/**
 * Class m261103_170000_add_icon_to_promo_menu_items_table
 */
class m261103_170000_add_icon_to_promo_menu_items_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $table = $this->db->getTableSchema('{{%promo_menu_items}}');
        if ($table && !isset($table->columns['icon'])) {
            $this->addColumn('{{%promo_menu_items}}', 'icon', $this->string(255)->defaultValue(null)->after('url'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $table = $this->db->getTableSchema('{{%promo_menu_items}}');
        if ($table && isset($table->columns['icon'])) {
            $this->dropColumn('{{%promo_menu_items}}', 'icon');
        }
    }
}
