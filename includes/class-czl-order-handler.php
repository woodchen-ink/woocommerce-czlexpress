<?php
class CZL_Order_Handler {
    private $api;
    
    public function __construct() {
        $this->api = new CZL_API();
    }
    
    /**
     * 创建运单
     */
    public function create_shipment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // 检查是否已创建运单
        $tracking_number = get_post_meta($order_id, '_czl_tracking_number', true);
        if ($tracking_number) {
            return;
        }
        
        // 检查是否使用CZL Express配送
        if (!$this->is_czl_shipping($order)) {
            return;
        }
        
        try {
            // 准备订单数据
            $order_data = new CZL_Order_Data($order);
            $data = $order_data->prepare();
            
            // 调用API创建运单
            $response = $this->api->create_order($data);
            
            if (!empty($response['order_id']) && !empty($response['tracking_number'])) {
                // 保存运单信息
                update_post_meta($order_id, '_czl_order_id', $response['order_id']);
                update_post_meta($order_id, '_czl_tracking_number', $response['tracking_number']);

                // 在数据库中创建运单记录
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'czl_shipments',
                    array(
                        'order_id' => $order_id,
                        'tracking_number' => $response['tracking_number'],
                        'czl_order_id' => $response['order_id'],
                        'status' => 'pending',
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s', '%s')
                );

                $shipment_id = $wpdb->insert_id;

                // 清除相关缓存
                $tracking = new CZL_Tracking();
                $tracking->clear_tracking_cache($shipment_id, $response['tracking_number'], $order_id);

                return $response;
            }
        } catch (Exception $e) {
            error_log('CZL Express Error: Failed to create shipment - ' . $e->getMessage());
            throw $e;
        }
        
        return false;
    }
    
    /**
     * 检查是否使用CZL Express配送
     */
    private function is_czl_shipping($order) {
        $shipping_methods = $order->get_shipping_methods();
        foreach ($shipping_methods as $shipping_method) {
            if (strpos($shipping_method->get_method_id(), 'czl_express_') === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 取消运单
     */
    public function cancel_shipment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $czl_order_id = get_post_meta($order_id, '_czl_order_id', true);
        if (!$czl_order_id) {
            return;
        }
        
        try {
            $response = $this->api->cancel_order($czl_order_id);
            
            // 添加订单备注
            $note = __('CZL Express运单已取消', 'czlexpress-for-woocommerce');
            $order->add_order_note($note);
            
            // 清除运单信息
            delete_post_meta($order_id, '_czl_order_id');
            delete_post_meta($order_id, '_czl_tracking_number');
            delete_post_meta($order_id, '_czl_label_url');
            
        } catch (Exception $e) {
            $error_message = sprintf(
                __('CZL Express运单取消失败: %s', 'czlexpress-for-woocommerce'),
                $e->getMessage()
            );
            $order->add_order_note($error_message);
            error_log('CZL Express Error: ' . $error_message);
        }
    }
    
    public function process_order_action($order) {
        try {
            error_log('CZL Express: Processing order action for order ' . $order->get_id());
            
            // 检查是否已经创建过运单
            $tracking_number = $order->get_meta('_czl_tracking_number');
            if (!empty($tracking_number)) {
                error_log('CZL Express: Order already has tracking number: ' . $tracking_number);
                return;
            }
            
            // 创建运单
            $czl_order = new CZL_Order();
            $result = $czl_order->create_shipment($order->get_id());
            
            error_log('CZL Express: Create shipment result - ' . print_r($result, true));
            
        } catch (Exception $e) {
            error_log('CZL Express Error: Failed to process order action - ' . $e->getMessage());
        }
    }
} 