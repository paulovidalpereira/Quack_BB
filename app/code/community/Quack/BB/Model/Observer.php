<?php
/**
 * Este arquivo é parte do programa Quack BB
 *
 * Quack BB é um software livre; você pode redistribuí-lo e/ou
 * modificá-lo dentro dos termos da Licença Pública Geral GNU como
 * publicada pela Fundação do Software Livre (FSF); na versão 3 da
 * Licença, ou (na sua opinião) qualquer versão.
 *
 * Este programa é distribuído na esperança de que possa ser  útil,
 * mas SEM NENHUMA GARANTIA; sem uma garantia implícita de ADEQUAÇÃO
 * a qualquer MERCADO ou APLICAÇÃO EM PARTICULAR. Veja a
 * Licença Pública Geral GNU para maiores detalhes.
 *
 * Você deve ter recebido uma cópia da Licença Pública Geral GNU junto
 * com este programa, Se não, veja <http://www.gnu.org/licenses/>.
 *
 * @category   Quack
 * @package    Quack_BB
 * @author     Rafael Patro <rafaelpatro@gmail.com>
 * @copyright  Copyright (c) 2015 Rafael Patro (rafaelpatro@gmail.com)
 * @license    http://www.gnu.org/licenses/gpl.txt
 * @link       https://github.com/rafaelpatro/Quack_BB
 */

class Quack_BB_Model_Observer {
    
    const PAYMENT_METHOD = 'bb_standard';
    
    /**
     * Set additional payment information in frontend order view page.
     * 
     * @param Varien_Event_Observer $observer
     */
    public function setFrontendPaymentInfo(Varien_Event_Observer $observer) {
        $block     = $observer->getEvent()->getBlock();     /* @var $block     Mage_Payment_Block_Info */
        $payment   = $observer->getEvent()->getPayment();   /* @var $payment   Mage_Payment_Model_Info */
        $transport = $observer->getEvent()->getTransport(); /* @var $transport Varien_Object */
        if ($block->getBlockAlias() == 'payment_info' && $payment->getMethodInstance()->getCode() == self::PAYMENT_METHOD) {
            $order    = $payment->getOrder();
            if ($order instanceof Mage_Sales_Model_Order) {
                $orderId = $order->getData('entity_id');
                $baseUrl = Mage::getBaseUrl();
                $url     = "{$baseUrl}bb/standard/redirect/order_id/{$orderId}";
                $state   = $order->getData('state');
                $isAllowBankTransfer = ($payment->getMethodInstance()->getConfigData('allowbanktransfer') == 1);
                $cmsBankTransferPage = $payment->getMethodInstance()->getConfigData('cms_banktransfer_page');
                $transport->setData(array(
                    Mage::helper('bb')->__('Bank Method') => Mage::helper('bb')->__("Method {$payment->getAdditionalInformation( 'paymentType'   )}"),
                    Mage::helper('bb')->__('Bank Status') => Mage::helper('bb')->__("Status {$payment->getAdditionalInformation( 'paymentStatus' )}"),
                ));
                if ($state==Mage_Sales_Model_Order::STATE_NEW) {
                    $childBlock = $block->getLayout()->createBlock('payment/info'); /* @var $childBlock Mage_Payment_Block_Info */
                    $childBlock->setData('paymenturl', $url);
                    $childBlock->setData('allowbanktransfer', $isAllowBankTransfer);
                    $childBlock->setData('cmsbanktransferpage', $cmsBankTransferPage);
                    $childBlock->setTemplate("bb/info/redirect.phtml");
                    $block->setChild('payment_try_again', $childBlock);
                }
            }
        } elseif ($block->getBlockAlias() == 'payment.info.'.self::PAYMENT_METHOD) {
            // Checkout Payment Info Block
            $transport->setData( Mage::helper('bb')->__('For BB clients only') );
        }
        return;
    }
    
    /**
     * Set additional payment information in backend order view page.
     * 
     * @param Varien_Event_Observer $observer
     */
    public function setBackendPaymentInfo(Varien_Event_Observer $observer) {
        $payment  = $observer->getEvent()->getPayment(); /* @var $payment Mage_Payment_Model_Info */
        if ($payment->getMethodInstance()->getCode() == self::PAYMENT_METHOD) {
            $observer->getEvent()->getTransport()->setData(array(
                    Mage::helper('bb')->__('Bank Method') => Mage::helper('bb')->__("Method {$payment->getAdditionalInformation( 'paymentType'   )}"),
                    Mage::helper('bb')->__('Bank Status') => Mage::helper('bb')->__("Status {$payment->getAdditionalInformation( 'paymentStatus' )}"),
            ));
            $url = Mage::getBaseUrl();
            $url.= "bb/standard/redirect/order_id/{$payment->getOrder()->getId()}";
            $button = Mage::app()->getLayout()->createBlock('adminhtml/widget_button')->setData(
                array(
                    'id'        => 'bb_standard_pay_btn',
                    'label'     => Mage::helper('bb')->__('Payment Link'),
                    'onclick'   => "window.open('{$url}', '_blank'); return false;",
                )
            );
            $observer->getEvent()->getBlock()->setChild('bb_standard_pay_btn', $button);
        }
        return;
    }
    
    
    public function checkPayment() {
        $order = Mage::getModel('sales/order'); /* @var $order Mage_Sales_Model_Order */
        $capturedPaymentsCounter = 0;
        $collection = self::loadPending();
        foreach ($collection as $entity) {
            $order->load($entity->getParentId());
            if ($order->canInvoice()) {
                $invoice = $order->prepareInvoice();
                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                try {
                    $invoice->register();
                } catch (Exception $e) {
                    Mage::log("{$e->getMessage()}");
                    unset($invoice);
                    unset($order);
                    $order = Mage::getModel('sales/order');
                    continue;
                }
                $invoice->getOrder()->setIsInProcess(true);
                $order->addStatusHistoryComment( Mage::helper('bb')->__('Payment auto captured') );
                $transaction = Mage::getModel('core/resource_transaction')
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transaction->save();
                $invoice->sendEmail(true);
                $capturedPaymentsCounter++;
            }
        }
        $pendingPaymentsCounter = count($collection);
        return "{$capturedPaymentsCounter} payments captured of {$pendingPaymentsCounter} pending";
    }
    
    public function loadPending() {
        /* @var $order Mage_Sales_Model_Order */
        $order = Mage::getModel('sales/order');
        $table = $order->getResource()->getTable('sales/order');
        /* @var $collection Mage_Sales_Model_Mysql4_Order_Payment_Collection */
        $collection = Mage::getModel('sales/order_payment')->getCollection();
        $collection->getSelect()->join($table, "main_table.parent_id = {$table}.entity_id", array());
        $collection
            ->addAttributeToFilter('method', self::PAYMENT_METHOD)
            ->addAttributeToFilter('amount_paid', array('null' => true))
            ->addAttributeToFilter('state', $order::STATE_NEW);
        return $collection;
    }
    
    /**
     * @return Quack_BB_Helper_Data
     */
    public function getHelper() {
        return Mage::helper('bb/data');
    }
}
